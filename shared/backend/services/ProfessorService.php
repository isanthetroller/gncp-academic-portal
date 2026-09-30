<?php
/**
 * GNCP Academic Portal — Professor Domain Service
 * Manages professor profiles, teaching workload, section rosters, weekly schedule grids,
 * Personal File Drive (with strict MIME/magic-byte validation), and LMS Material / Announcement publishing.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../utils/logger.php';
require_once __DIR__ . '/ScheduleConflictService.php';

class ProfessorService {

    const UPLOAD_DIR = __DIR__ . '/../../../uploads/professor_drive/';
    const MAX_FILE_SIZE = 26214400; // 25 MB

    const ALLOWED_MIME_TYPES = [
        'pdf'  => ['application/pdf'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip']
    ];

    /**
     * Resolve professor record by station_users ID
     */
    public static function getProfessorByUserId(PDO $pdo, int $userId): ?array {
        $stmt = $pdo->prepare("
            SELECT p.*, d.code as department_code, d.name as department_name, 
                   u.username, u.avatar, u.status as user_status
            FROM `professors` p
            JOIN `station_users` u ON p.user_id = u.id
            JOIN `departments` d ON p.department_id = d.id
            WHERE p.user_id = :uid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get professor profile and comprehensive teaching statistics
     */
    public static function getProfile(PDO $pdo, int $userId): array {
        $prof = self::getProfessorByUserId($pdo, $userId);
        if (!$prof) {
            return ['success' => false, 'message' => 'Professor profile not found.', 'code' => 404];
        }

        $profId = (int)$prof['id'];

        // Compute total sections taught
        $secStmt = $pdo->prepare("
            SELECT COUNT(DISTINCT COALESCE(ss.section_id, ss.code)) 
            FROM `subject_sections` ss 
            WHERE ss.professor_id = :pid
        ");
        $secStmt->execute(['pid' => $profId]);
        $totalSections = (int)$secStmt->fetchColumn();

        // Compute total subjects handled
        $subStmt = $pdo->prepare("SELECT COUNT(DISTINCT ss.subject) FROM `subject_sections` ss WHERE ss.professor_id = :pid");
        $subStmt->execute(['pid' => $profId]);
        $totalSubjects = (int)$subStmt->fetchColumn();

        // Compute total units
        $unitStmt = $pdo->prepare("
            SELECT SUM(COALESCE(sub.lecture_units, 3) + COALESCE(sub.lab_units, 0)) as total_units
            FROM `subject_sections` ss
            LEFT JOIN `subjects` sub ON (ss.code = sub.code OR ss.subject = sub.title)
            WHERE ss.professor_id = :pid
        ");
        $unitStmt->execute(['pid' => $profId]);
        $totalUnits = (float)($unitStmt->fetchColumn() ?: 0.0);

        // Compute files in drive count
        $fileStmt = $pdo->prepare("SELECT COUNT(*) FROM `professor_files` WHERE professor_id = :pid");
        $fileStmt->execute(['pid' => $profId]);
        $totalFiles = (int)$fileStmt->fetchColumn();

        // Published materials count
        $matStmt = $pdo->prepare("SELECT COUNT(*) FROM `lms_materials` WHERE professor_id = :pid");
        $matStmt->execute(['pid' => $profId]);
        $totalMaterials = (int)$matStmt->fetchColumn();

        return [
            'success' => true,
            'data'    => [
                'professor'      => $prof,
                'totalSections'  => $totalSections,
                'totalSubjects'  => $totalSubjects,
                'totalUnits'     => $totalUnits,
                'totalFiles'     => $totalFiles,
                'totalMaterials' => $totalMaterials
            ]
        ];
    }

    /**
     * Get sections assigned to the professor
     */
    public static function getSections(PDO $pdo, int $professorId): array {
        $stmt = $pdo->prepare("
            SELECT ss.*, s.id as sec_id, s.code as section_code, s.capacity as section_capacity,
                   COALESCE(ap.name, '1st Semester, A.Y. 2026-2027') as academic_period_name
            FROM `subject_sections` ss
            LEFT JOIN `sections` s ON ss.section_id = s.id
            LEFT JOIN `academic_periods` ap ON s.academic_period_id = ap.id
            WHERE ss.professor_id = :pid
            ORDER BY ss.program ASC, ss.year_level ASC, ss.code ASC
        ");
        $stmt->execute(['pid' => $professorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Group by distinct section
        $grouped = [];
        foreach ($rows as $r) {
            $secCode = $r['section_code'] ?: $r['code'];
            if (!isset($grouped[$secCode])) {
                // Count enrolled students in this section
                $studCountStmt = $pdo->prepare("
                    SELECT 
                        (SELECT COUNT(*) FROM `students` WHERE JSON_UNQUOTE(JSON_EXTRACT(enrollment_data, '$.assignedSection')) = :s1) +
                        (SELECT COUNT(*) FROM `pre_enrollments` WHERE section_code = :s2 AND status NOT IN ('REJECTED', 'PRE_REGISTERED'))
                    as cnt
                ");
                $studCountStmt->execute(['s1' => $secCode, 's2' => $secCode]);
                $studentCount = (int)$studCountStmt->fetchColumn();

                $grouped[$secCode] = [
                    'sectionCode'        => $secCode,
                    'program'            => $r['program'],
                    'yearLevel'          => $r['year_level'],
                    'semester'           => $r['semester'],
                    'academicPeriod'     => $r['academic_period_name'],
                    'capacity'           => (int)($r['section_capacity'] ?: $r['capacity']),
                    'enrolledStudents'   => $studentCount,
                    'subjects'           => []
                ];
            }

            $grouped[$secCode]['subjects'][] = [
                'id'       => (int)$r['id'],
                'subject'  => $r['subject'],
                'code'     => $r['code'],
                'days'     => $r['days'],
                'time'     => $r['time'],
                'room'     => $r['room']
            ];
        }

        return ['success' => true, 'data' => array_values($grouped)];
    }

    /**
     * Get subjects handled by the professor
     */
    public static function getSubjects(PDO $pdo, int $professorId): array {
        $stmt = $pdo->prepare("
            SELECT ss.*, s.code as section_code,
                   sub.lecture_units, sub.lab_units, (COALESCE(sub.lecture_units, 3) + COALESCE(sub.lab_units, 0)) as total_units
            FROM `subject_sections` ss
            LEFT JOIN `sections` s ON ss.section_id = s.id
            LEFT JOIN `subjects` sub ON (ss.code = sub.code OR ss.subject = sub.title)
            WHERE ss.professor_id = :pid
            ORDER BY ss.days ASC, ss.time ASC
        ");
        $stmt->execute(['pid' => $professorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($rows as $r) {
            $formatted[] = [
                'id'           => (int)$r['id'],
                'subject'      => $r['subject'],
                'code'         => $r['code'],
                'sectionCode'  => $r['section_code'] ?: $r['code'],
                'program'      => $r['program'],
                'yearLevel'    => $r['year_level'],
                'semester'     => $r['semester'],
                'days'         => $r['days'],
                'time'         => $r['time'],
                'room'         => $r['room'],
                'lectureUnits' => (float)($r['lecture_units'] ?? 3.0),
                'labUnits'     => (float)($r['lab_units'] ?? 0.0),
                'totalUnits'   => (float)($r['total_units'] ?? 3.0)
            ];
        }

        return ['success' => true, 'data' => $formatted];
    }

    /**
     * Get weekly schedule of the professor
     */
    public static function getSchedule(PDO $pdo, int $professorId): array {
        $subStmt = $pdo->prepare("
            SELECT ss.*, s.code as section_code 
            FROM `subject_sections` ss
            LEFT JOIN `sections` s ON ss.section_id = s.id
            WHERE ss.professor_id = :pid
            ORDER BY ss.id ASC
        ");
        $subStmt->execute(['pid' => $professorId]);
        $rows = $subStmt->fetchAll(PDO::FETCH_ASSOC);

        $days = ['Monday' => [], 'Tuesday' => [], 'Wednesday' => [], 'Thursday' => [], 'Friday' => [], 'Saturday' => []];

        $dayMap = [
            'M'  => 'Monday',
            'T'  => 'Tuesday',
            'W'  => 'Wednesday',
            'TH' => 'Thursday',
            'F'  => 'Friday',
            'S'  => 'Saturday'
        ];

        foreach ($rows as $r) {
            $tokens = ScheduleConflictService::parseDays($r['days']);
            $timeData = ScheduleConflictService::parseTimeRange($r['time']);

            foreach ($tokens as $tok) {
                if (isset($dayMap[$tok])) {
                    $dayName = $dayMap[$tok];
                    $days[$dayName][] = [
                        'id'          => (int)$r['id'],
                        'subject'     => $r['subject'],
                        'code'        => $r['code'],
                        'sectionCode' => $r['section_code'] ?: $r['code'],
                        'time'        => $r['time'],
                        'room'        => $r['room'],
                        'start'       => $timeData['rawStart'] ?? '',
                        'end'         => $timeData['rawEnd'] ?? ''
                    ];
                }
            }
        }

        // Sort each day chronologically
        foreach ($days as $d => &$items) {
            usort($items, function($a, $b) {
                return strcmp($a['time'], $b['time']);
            });
        }

        return ['success' => true, 'data' => $days];
    }

    /**
     * Personal Drive: List files
     */
    public static function getFiles(PDO $pdo, int $professorId): array {
        $stmt = $pdo->prepare("
            SELECT f.*, 
                   (SELECT COUNT(*) FROM `lms_materials` m WHERE m.file_id = f.id) as published_count
            FROM `professor_files` f
            WHERE f.professor_id = :pid
            ORDER BY f.id DESC
        ");
        $stmt->execute(['pid' => $professorId]);
        $files = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($files as $f) {
            $formatted[] = [
                'id'             => (int)$f['id'],
                'filename'       => $f['filename'],
                'fileType'       => $f['file_type'],
                'fileSize'       => (int)$f['file_size'],
                'fileSizeFormat' => self::formatBytes((int)$f['file_size']),
                'mimeType'       => $f['mime_type'],
                'isPublished'    => ((int)$f['published_count'] > 0),
                'publishedCount' => (int)$f['published_count'],
                'uploadedAt'     => $f['created_at']
            ];
        }

        return ['success' => true, 'data' => $formatted];
    }

    /**
     * Personal Drive: Upload file with strict MIME & magic byte inspection
     */
    public static function uploadFile(PDO $pdo, int $professorId, array $file): array {
        if (!isset($file['error']) || is_array($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'Upload failed or no file uploaded.', 'code' => 400];
        }

        if ($file['size'] > self::MAX_FILE_SIZE) {
            return ['success' => false, 'message' => 'File exceeds maximum permitted size of 25MB.', 'code' => 400];
        }

        $rawName = basename($file['name']);
        $ext = strtolower(pathinfo($rawName, PATHINFO_EXTENSION));

        if (!array_key_exists($ext, self::ALLOWED_MIME_TYPES)) {
            return ['success' => false, 'message' => "File type '.{$ext}' is not permitted. Allowed types: PDF, PPT, PPTX, DOC, DOCX.", 'code' => 400];
        }

        // Inspect magic bytes via PHP finfo
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);

        $allowedMimes = self::ALLOWED_MIME_TYPES[$ext];
        if (!in_array($detectedMime, $allowedMimes, true)) {
            return [
                'success' => false,
                'message' => "Security validation failed: File content does not match extension '.{$ext}'. Detected MIME: '{$detectedMime}'.",
                'code'    => 400
            ];
        }

        // Ensure target directory exists and is secured
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }
        $htaccessPath = self::UPLOAD_DIR . '.htaccess';
        if (!file_exists($htaccessPath)) {
            file_put_contents($htaccessPath, "Options -Indexes\n<FilesMatch \"\.(php|php5|phtml|phar|sh|exe|bat|cmd|js)$\">\nOrder Deny,Allow\nDeny from all\n</FilesMatch>\n");
        }

        // Cryptographically unique disk filename
        $uniqueHash = bin2hex(random_bytes(16));
        $storedFilename = "prof_{$professorId}_{$uniqueHash}.{$ext}";
        $targetPath = self::UPLOAD_DIR . $storedFilename;

        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            return ['success' => false, 'message' => 'Failed to store file on server filesystem.', 'code' => 500];
        }

        // Insert metadata
        $stmt = $pdo->prepare("
            INSERT INTO `professor_files` (`professor_id`, `filename`, `stored_filename`, `file_type`, `mime_type`, `file_size`, `storage_path`)
            VALUES (:pid, :fname, :sfname, :ftype, :mime, :fsize, :spath)
        ");
        $stmt->execute([
            'pid'    => $professorId,
            'fname'  => $rawName,
            'sfname' => $storedFilename,
            'ftype'  => $ext,
            'mime'   => $detectedMime,
            'fsize'  => $file['size'],
            'spath'  => 'uploads/professor_drive/' . $storedFilename
        ]);

        $fileId = (int)$pdo->lastInsertId();

        return [
            'success' => true,
            'message' => 'File uploaded to personal drive successfully.',
            'data'    => [
                'id'       => $fileId,
                'filename' => $rawName,
                'fileType' => $ext,
                'fileSize' => $file['size']
            ]
        ];
    }

    /**
     * Personal Drive: Delete file
     */
    public static function deleteFile(PDO $pdo, int $professorId, int $fileId): array {
        $stmt = $pdo->prepare("SELECT * FROM `professor_files` WHERE id = :id AND professor_id = :pid LIMIT 1");
        $stmt->execute(['id' => $fileId, 'pid' => $professorId]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$f) {
            return ['success' => false, 'message' => 'File not found or unauthorized access.', 'code' => 404];
        }

        $diskPath = self::UPLOAD_DIR . $f['stored_filename'];
        if (file_exists($diskPath)) {
            @unlink($diskPath);
        }

        $delStmt = $pdo->prepare("DELETE FROM `professor_files` WHERE id = :id");
        $delStmt->execute(['id' => $fileId]);

        return ['success' => true, 'message' => 'File removed from personal drive.'];
    }

    /**
     * Publish a file from Personal Drive to Section(s) as LMS Material
     */
    public static function publishMaterial(PDO $pdo, int $professorId, array $payload): array {
        $fileId = !empty($payload['fileId']) ? (int)$payload['fileId'] : null;
        $title = trim($payload['title'] ?? '');
        $description = trim($payload['description'] ?? '');
        $subjectCode = trim($payload['subjectCode'] ?? '');
        $targetSections = (array)($payload['sections'] ?? []);

        if (!$fileId || empty($title) || empty($targetSections)) {
            return ['success' => false, 'message' => 'File, title, and at least one target section are required.', 'code' => 400];
        }

        // Verify file ownership
        $fStmt = $pdo->prepare("SELECT id, filename FROM `professor_files` WHERE id = :id AND professor_id = :pid LIMIT 1");
        $fStmt->execute(['id' => $fileId, 'pid' => $professorId]);
        $fileRow = $fStmt->fetch(PDO::FETCH_ASSOC);
        if (!$fileRow) {
            return ['success' => false, 'message' => 'Selected file not found in your drive.', 'code' => 403];
        }

        // Verify professor actually teaches the targeted sections
        $secStmt = $pdo->prepare("
            SELECT DISTINCT COALESCE(s.code, ss.code) as sec_code
            FROM `subject_sections` ss
            LEFT JOIN `sections` s ON ss.section_id = s.id
            WHERE ss.professor_id = :pid
        ");
        $secStmt->execute(['pid' => $professorId]);
        $taughtSections = $secStmt->fetchAll(PDO::FETCH_COLUMN);

        $pdo->beginTransaction();
        try {
            $insMat = $pdo->prepare("
                INSERT INTO `lms_materials` (`professor_id`, `file_id`, `title`, `description`, `subject_code`)
                VALUES (:pid, :fid, :title, :desc, :sub)
            ");
            $insMat->execute([
                'pid'   => $professorId,
                'fid'   => $fileId,
                'title' => $title,
                'desc'  => $description,
                'sub'   => $subjectCode
            ]);
            $materialId = (int)$pdo->lastInsertId();

            $insSec = $pdo->prepare("
                INSERT INTO `lms_material_sections` (`material_id`, `section_code`)
                VALUES (:mid, :scode)
            ");

            foreach ($targetSections as $sec) {
                $secClean = trim($sec);
                if (!empty($secClean)) {
                    $insSec->execute(['mid' => $materialId, 'scode' => $secClean]);
                }
            }

            $pdo->commit();
            return [
                'success' => true,
                'message' => "Learning material '{$title}' successfully published to selected section(s).",
                'data'    => ['id' => $materialId, 'materialId' => $materialId]
            ];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Failed to publish material: ' . $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Get published materials list for the professor
     */
    public static function getPublishedMaterials(PDO $pdo, int $professorId): array {
        $stmt = $pdo->prepare("
            SELECT m.*, f.filename, f.file_type, f.file_size
            FROM `lms_materials` m
            JOIN `professor_files` f ON m.file_id = f.id
            WHERE m.professor_id = :pid
            ORDER BY m.id DESC
        ");
        $stmt->execute(['pid' => $professorId]);
        $materials = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($materials as $m) {
            $secStmt = $pdo->prepare("SELECT section_code FROM `lms_material_sections` WHERE material_id = :mid");
            $secStmt->execute(['mid' => $m['id']]);
            $sections = $secStmt->fetchAll(PDO::FETCH_COLUMN);

            $formatted[] = [
                'id'             => (int)$m['id'],
                'fileId'         => (int)$m['file_id'],
                'filename'       => $m['filename'],
                'fileType'       => $m['file_type'],
                'fileSizeFormat' => self::formatBytes((int)$m['file_size']),
                'title'          => $m['title'],
                'description'    => $m['description'],
                'subjectCode'    => $m['subject_code'],
                'sections'       => $sections,
                'publishedAt'    => $m['created_at']
            ];
        }

        return ['success' => true, 'data' => $formatted];
    }

    /**
     * Delete/unpublish an LMS material post
     */
    public static function unpublishMaterial(PDO $pdo, int $professorId, int $materialId): array {
        $stmt = $pdo->prepare("DELETE FROM `lms_materials` WHERE id = :id AND professor_id = :pid");
        $stmt->execute(['id' => $materialId, 'pid' => $professorId]);
        return ['success' => true, 'message' => 'Material posting removed.'];
    }

    /**
     * Professor Announcements: List
     */
    public static function getAnnouncements(PDO $pdo, int $professorId): array {
        $stmt = $pdo->prepare("
            SELECT a.*, p.name as professor_name
            FROM `announcements` a
            JOIN `professors` p ON a.author_id = p.user_id
            WHERE p.id = :pid
            ORDER BY a.id DESC
        ");
        $stmt->execute(['pid' => $professorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $formatted = [];
        foreach ($rows as $r) {
            $secStmt = $pdo->prepare("SELECT section_code FROM `announcement_sections` WHERE announcement_id = :aid");
            $secStmt->execute(['aid' => $r['id']]);
            $sections = $secStmt->fetchAll(PDO::FETCH_COLUMN);

            $formatted[] = [
                'id'             => (int)$r['id'],
                'title'          => $r['title'],
                'content'        => $r['content'],
                'category'       => $r['category'],
                'subjectCode'    => $r['subject_code'] ?? null,
                'subject_code'   => $r['subject_code'] ?? null,
                'targetAudience' => $r['target_audience'],
                'sections'       => $sections,
                'isPinned'       => (bool)$r['is_pinned'],
                'createdAt'      => $r['created_at']
            ];
        }

        return ['success' => true, 'data' => $formatted];
    }

    /**
     * Professor Announcements: Create section-targeted announcement
     */
    public static function createAnnouncement(PDO $pdo, int $professorId, array $payload, string $authorName, int $userId): array {
        $title = trim($payload['title'] ?? '');
        $content = trim($payload['content'] ?? '');
        $targetSections = (array)($payload['sections'] ?? []);
        $subjectCode = trim($payload['subjectCode'] ?? ($payload['subject_code'] ?? ''));

        if (empty($title) || empty($content) || empty($targetSections)) {
            return ['success' => false, 'message' => 'Title, content, and at least one target section are required.', 'code' => 400];
        }

        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare("
                INSERT INTO `announcements` (`title`, `category`, `subject_code`, `content`, `author_id`, `author_name`, `target_audience`, `status`, `is_pinned`)
                VALUES (:title, 'ACADEMIC', :sub, :content, :aid, :aname, 'SECTION', 'PUBLISHED', 0)
            ");
            $ins->execute([
                'title'   => $title,
                'sub'     => !empty($subjectCode) ? $subjectCode : null,
                'content' => $content,
                'aid'     => $userId,
                'aname'   => $authorName
            ]);
            $annId = (int)$pdo->lastInsertId();

            $insSec = $pdo->prepare("INSERT INTO `announcement_sections` (`announcement_id`, `section_code`) VALUES (:aid, :scode)");
            foreach ($targetSections as $s) {
                $clean = trim($s);
                if ($clean) {
                    $insSec->execute(['aid' => $annId, 'scode' => $clean]);
                }
            }

            $pdo->commit();
            return ['success' => true, 'message' => 'Announcement published to section(s) successfully.', 'data' => ['id' => $annId]];
        } catch (Exception $e) {
            $pdo->rollBack();
            return ['success' => false, 'message' => 'Failed to create announcement: ' . $e->getMessage(), 'code' => 500];
        }
    }

    /**
     * Professor Announcements: Delete
     */
    public static function deleteAnnouncement(PDO $pdo, int $userId, int $announcementId): array {
        $stmt = $pdo->prepare("DELETE FROM `announcements` WHERE id = :id AND author_id = :aid");
        $stmt->execute(['id' => $announcementId, 'aid' => $userId]);
        return ['success' => true, 'message' => 'Announcement deleted successfully.'];
    }

    /**
     * Format byte sizes
     */
    private static function formatBytes(int $bytes): string {
        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return round($bytes / 1024, 2) . ' KB';
        }
        return $bytes . ' B';
    }
}
