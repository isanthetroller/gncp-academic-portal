<?php
/**
 * GNCP REST API — Registrar Admin Controller
 * Central controller handling Registrar portal state, requirements verification, and section scoping.
 */

require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';
require_once __DIR__ . '/../../shared/backend/utils/logger.php';
require_once __DIR__ . '/../../shared/backend/services/CatalogService.php';
require_once __DIR__ . '/../../shared/backend/services/SectionService.php';
require_once __DIR__ . '/../../shared/backend/services/RegistrarService.php';
require_once __DIR__ . '/../../stations/backend/services/QueueService.php';

class RegistrarAdminController {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Fetch complete registrar initial dataset including ETag HTTP 304 caching
     */
    public function fetchAllData(): array {
        requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);

        // 1. Throttle 90-day expiration cleanup to run at most once per 24 hours
        $cleanupFlag = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'gncp_prereg_cleanup.lock';
        if (!file_exists($cleanupFlag) || (time() - filemtime($cleanupFlag) > 86400)) {
            try {
                $this->pdo->query("UPDATE `pre_enrollments` SET `status` = 'EXPIRED' WHERE `status` = 'PRE_REGISTERED' AND `created_at` < NOW() - INTERVAL 90 DAY");
                @touch($cleanupFlag);
            } catch (Exception $ex) {}
        }

        // 2. Compute lightweight table checksum for ETag validation (<1ms)
        $peChecksum = $this->pdo->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(`id`), 0) AS max_id, COALESCE(SUM(CRC32(CONCAT(`id`, `status`, IFNULL(SUBSTRING(`requirements_data`, 1, 60), '')))), 0) AS cs FROM `pre_enrollments`")->fetch(PDO::FETCH_ASSOC);
        $stChecksum = $this->pdo->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(`id`), 0) AS max_id FROM `students`")->fetch(PDO::FETCH_ASSOC);
        $subChecksum = $this->pdo->query("SELECT COUNT(*) AS cnt, COALESCE(MAX(`id`), 0) AS max_id FROM `subjects`")->fetch(PDO::FETCH_ASSOC);
        $hashSeed = sprintf("pe:%d:%d:%u|st:%d:%s|sub:%d:%d",
            $peChecksum['cnt'] ?? 0, $peChecksum['max_id'] ?? 0, $peChecksum['cs'] ?? 0,
            $stChecksum['cnt'] ?? 0, $stChecksum['max_id'] ?? 0,
            $subChecksum['cnt'] ?? 0, $subChecksum['max_id'] ?? 0
        );
        $etag = '"' . md5($hashSeed) . '"';
        header('ETag: ' . $etag);
        header('Cache-Control: no-cache, must-revalidate');

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch && (trim($ifNoneMatch) === trim($etag) || trim($ifNoneMatch, '"') === trim($etag, '"'))) {
            http_response_code(304);
            exit;
        }

        $catalogData = CatalogService::fetchCatalogData($this->pdo);
        $sectionData = SectionService::fetchSections($this->pdo);

        $studentsRaw = $this->pdo->query("SELECT * FROM `students` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
        $students = array_map(function($s) {
            $enrollmentData = json_decode((string)($s['enrollment_data'] ?? ''), true) ?: [];
            $helpdeskData   = json_decode((string)($s['helpdesk_data'] ?? ''), true) ?: [];
            $assignedSec    = $enrollmentData['assignedSection'] ?? $helpdeskData['section'] ?? null;
            return [
                'id'                => $s['id'],
                'name'              => $s['name'],
                'program'           => $s['program'],
                'email'             => $s['email'],
                'photo'             => $s['photo'],
                'year_level'        => !empty($s['year_level']) ? $s['year_level'] : '1st Year',
                'status'            => $s['status'],
                'temp_reference_no' => $s['temp_reference_no'],
                'assignedSection'   => $assignedSec,
                'sectionCode'       => $assignedSec,
                'personalInfo'      => json_decode((string)($s['personal_info'] ?? ''), true) ?: null,
                'academicInfo'      => json_decode((string)($s['academic_info'] ?? ''), true) ?: null,
                'roadmap'           => json_decode((string)($s['roadmap'] ?? ''), true) ?: [],
                'requirementsData'  => json_decode((string)($s['requirements_data'] ?? ''), true) ?: null,
                'medicalData'       => json_decode((string)($s['medical_data'] ?? ''), true) ?: null,
                'scholarshipData'   => json_decode((string)($s['scholarship_data'] ?? ''), true) ?: null,
                'paymentData'       => json_decode((string)($s['payment_data'] ?? ''), true) ?: null,
                'helpdeskData'      => $helpdeskData ?: null,
                'enrollmentData'    => $enrollmentData ?: null
            ];
        }, $studentsRaw);

        $enrollments = $this->pdo->query("SELECT * FROM `enrollments` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);

        $preEnrollments = $this->pdo->query("SELECT * FROM `pre_enrollments` WHERE `status` IN ('PRE_REGISTERED', 'PENDING', 'RETURNED', 'NEEDS_CORRECTION') ORDER BY `created_at` ASC")->fetchAll(PDO::FETCH_ASSOC);
        
        $pendingApplications = array_map(function($row) {
            $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
            $requirements = function_exists('getRequirementsForType')
                ? getRequirementsForType($row['student_type'] ?? 'FRESHMAN', $row['shs_track'] ?? '')
                : ['Form 138 / Report Card', 'Certificate of Good Moral Character', 'PSA Birth Certificate', '2x2 Pictures'];
            $requirementsData = json_decode((string)($row['requirements_data'] ?? ''), true) ?: [
                'status' => 'PENDING',
                'docs' => ['psa' => 'not-submitted', 'reportCard' => 'not-submitted', 'goodMoral' => 'not-submitted'],
                'notes' => '', 'verifiedBy' => '', 'dateVerified' => ''
            ];
            $rowId = (int)($row['id'] ?? 0);
            $padId = str_pad((string)($rowId > 0 ? $rowId : rand(1, 999)), 3, '0', STR_PAD_LEFT);

            return [
                'id'              => $rowId,
                'queueTicket'     => 'REG-' . $padId,
                'referenceNumber' => $row['temp_student_id'],
                'tempPin'         => $row['temp_pin'],
                'applicantName'   => $fullName ?: 'New Applicant',
                'program'         => $row['course_code'],
                'yearLevel'       => !empty($row['year_level_applied']) ? $row['year_level_applied'] : '1st Year',
                'studentType'     => $row['student_type'] ?? 'FRESHMAN',
                'shsTrack'        => $row['shs_track'] ?? '',
                'academicInfo'    => [
                    'elementary'  => $row['elementary_school'] ?? '',
                    'juniorHigh'  => $row['junior_high_school'] ?? '',
                    'seniorHigh'  => $row['senior_high_school'] ?? '',
                    'shsTrack'    => $row['shs_track'] ?? '',
                    'honors'      => $row['honors'] ?? ''
                ],
                'previousCollege' => $row['previous_college'] ?? null,
                'nstp'            => $row['nstp'] ?? 'N/A',
                'dateSubmitted'   => date('Y-m-d', strtotime($row['created_at'])),
                'createdAt'       => $row['created_at'],
                'status'          => $row['status'],
                'reviewedToday'   => in_array($row['status'], ['Approved', 'Rejected', 'VERIFIED']),
                'sectionCode'     => $row['section_code'] ?? null,
                'personalInfo'    => ['birthDate' => $row['birth_date'], 'gender' => $row['gender'], 'address' => $row['address']],
                'contactInfo'     => ['email' => $row['email'], 'phone' => $row['phone'], 'guardian' => $row['emergency_contact_name']],
                'requirements'    => $requirements,
                'requirementsData'=> $requirementsData,
                'roadmap'         => json_decode((string)($row['roadmap'] ?? ''), true) ?: [],
                'registrarNotes'  => $row['registrar_notes'] ?? ($row['roadmap'] ? 'Tracking steps established' : 'Awaiting review.')
            ];
        }, $preEnrollments);

        return [
            'success' => true,
            'data'    => array_merge([
                'courses'             => [],
                'students'            => $students,
                'sections'            => $sectionData['sections'],
                'enrollments'         => $enrollments,
                'pendingApplications' => $pendingApplications,
                'subjectSections'     => $sectionData['subjectSections']
            ], $catalogData)
        ];
    }

    /**
     * Update application status and requirements verification
     */
    public function updateApplicationStatus(array $payload): array {
        requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);
        return RegistrarService::updateApplicationStatus($this->pdo, $payload);
    }

    /**
     * Update individual roadmap step progression
     */
    public function updateRoadmapStep(array $payload): array {
        requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);
        return RegistrarService::updateRoadmapStep($this->pdo, $payload);
    }

    /**
     * Get block sections scoped for a program
     */
    public function getSectionsForProgram(array $params): array {
        requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);
        $prog = $params['program'] ?? '';
        $year = $params['year_level'] ?? null;
        $sem  = $params['semester'] ?? null;
        return SectionService::getSectionsForProgram($this->pdo, $prog, $year, $sem);
    }
}
