<?php
/**
 * Student Controller — Handles public pre-registration, application tracking, and student lookup
 */
require_once __DIR__ . '/../models/StudentModel.php';

class StudentController {
    private $pdo;
    private $studentModel;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->studentModel = new StudentModel($pdo);
    }

    public function register($payload) {
        if (empty($payload['firstName']) || empty($payload['lastName']) || empty($payload['courseCode'])) {
            return ['success' => false, 'message' => 'First name, last name, and course choice are required.', 'code' => 400];
        }

        try {
            $res = $this->studentModel->createPreEnrollment($payload);
            return [
                'success' => true,
                'data' => $res,
                'message' => 'Application submitted successfully.'
            ];
        } catch (Exception $e) {
            logAppError("Student Registration Error: " . $e->getMessage(), ['payload' => $payload]);
            return ['success' => false, 'message' => 'We encountered an issue submitting your application. Please check your details or try again shortly.', 'code' => 500];
        }
    }

    public function track($refNo) {
        if (!$refNo) {
            return ['success' => false, 'message' => 'Reference number is required.', 'code' => 400];
        }

        $student = $this->studentModel->findByReferenceNumber($refNo);
        if (!$student) {
            return ['success' => false, 'message' => 'Application record not found.', 'code' => 404];
        }

        // Sensitive field protection: Require staff session, matching student, or valid PIN for full PII
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        initSession();
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? '') : '';
        $isStaff = ($adminSess !== null || $stationSess !== null);
        $isMatchingStudent = (!empty($sessStudentId) && (strcasecmp($sessStudentId, (string)($student['id'] ?? '')) === 0 || strcasecmp($sessStudentId, (string)($student['referenceNumber'] ?? '')) === 0));
        session_write_close();

        $reqPin = trim($_GET['pin'] ?? ($_POST['pin'] ?? ''));
        $storedPin = (string)($student['tempPin'] ?? '');
        $isVerifiedPin = (!empty($reqPin) && !empty($storedPin) && hash_equals($storedPin, $reqPin));

        $hasFullAccess = ($isStaff || $isMatchingStudent || $isVerifiedPin);

        if (!$hasFullAccess) {
            // Data Minimization for public tracking:
            // Mask applicant name so scrapers cannot harvest PII
            $firstName = $student['firstName'] ?? '';
            $lastName = $student['lastName'] ?? '';
            $maskedFirst = $firstName ? (substr($firstName, 0, 1) . str_repeat('*', max(1, strlen($firstName) - 1))) : '***';
            $maskedLast = $lastName ? (substr($lastName, 0, 1) . str_repeat('*', max(1, strlen($lastName) - 1))) : '***';

            // Sanitize roadmap to only show high-level stage progress without internal IDs or payloads
            $sanitizedRoadmap = [];
            foreach ($student['roadmap'] ?? [] as $step) {
                $sanitizedRoadmap[] = [
                    'id' => $step['id'] ?? null,
                    'stepId' => $step['stepId'] ?? null,
                    'name' => $step['name'] ?? null,
                    'status' => $step['status'] ?? 'PENDING'
                ];
            }

            $sanitized = [
                'referenceNumber'       => $student['referenceNumber'] ?? $refNo,
                'name'                  => $maskedFirst . ' ' . $maskedLast,
                'firstName'             => $maskedFirst,
                'lastName'              => $maskedLast,
                'courseCode'            => $student['courseCode'] ?? ($student['program'] ?? ''),
                'program'               => $student['program'] ?? ($student['courseCode'] ?? ''),
                'yearLevelApplied'      => $student['yearLevelApplied'] ?? ($student['yearLevel'] ?? '1st Year'),
                'sectionCode'           => !empty($student['sectionCode']) ? 'Assigned' : 'Pending',
                'status'                => $student['status'] ?? 'PENDING',
                'activeStation'         => $student['activeStation'] ?? 'registrar',
                'stationName'           => $student['stationName'] ?? 'Registrar Office',
                'stationLocation'       => $student['stationLocation'] ?? 'Ground Floor',
                'queueRank'             => $student['queueRank'] ?? 1,
                'aheadCount'            => $student['aheadCount'] ?? 0,
                'currentTicket'         => $student['currentTicket'] ?? 'Q-001',
                'roadmap'               => $sanitizedRoadmap,
                'isPublicView'          => true,
                'requiresPinForDetails' => true
            ];

            return [
                'success' => true,
                'data' => $sanitized
            ];
        }

        return [
            'success' => true,
            'data' => $student
        ];
    }

    public function getDocuments($identifier, $pin = '') {
        if (empty($identifier)) {
            return ['success' => false, 'message' => 'Student identifier or reference number is required.', 'code' => 400];
        }

        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        initSession();
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? ($studentSess['username'] ?? '')) : '';

        $isStaff = ($adminSess !== null || $stationSess !== null);
        $isMatchingStudent = (!empty($sessStudentId) && strcasecmp($sessStudentId, $identifier) === 0);

        if (!$isStaff && !$isMatchingStudent) {
            $reqPin = trim($pin ?: ($_GET['pin'] ?? ($_POST['pin'] ?? '')));
            if (empty($reqPin)) {
                session_write_close();
                return ['success' => false, 'message' => 'Unauthorized: Authentication or valid security PIN is required to access student documents.', 'code' => 401];
            }
            $peCheck = $this->pdo->prepare("SELECT `temp_pin` FROM `pre_enrollments` WHERE LOWER(`temp_student_id`) = LOWER(:id1) OR LOWER(COALESCE(`existing_student_id`, '')) = LOWER(:id2) LIMIT 1");
            $peCheck->execute(['id1' => $identifier, 'id2' => $identifier]);
            $storedPin = (string)$peCheck->fetchColumn();
            if (empty($storedPin) || $storedPin !== $reqPin) {
                session_write_close();
                return ['success' => false, 'message' => 'Unauthorized: Invalid security PIN credentials.', 'code' => 401];
            }
        }
        session_write_close();

        try {
            $data = $this->studentModel->getStudentRequirements($identifier);
            if (!$data) {
                return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
            }
            return [
                'success' => true,
                'data' => $data
            ];
        } catch (Exception $e) {
            logAppError("Get Documents Error: " . $e->getMessage(), ['identifier' => $identifier]);
            return ['success' => false, 'message' => 'Unable to retrieve document records: ' . $e->getMessage(), 'code' => 500];
        }
    }

    public function uploadDocument($payload) {
        $identifier = trim($payload['studentId'] ?? ($payload['referenceNumber'] ?? ($payload['email'] ?? '')));
        $docKey = trim($payload['docKey'] ?? ($payload['key'] ?? ''));
        $fileName = trim($payload['fileName'] ?? '');
        $fileType = trim($payload['fileType'] ?? '');
        $fileData = $payload['fileData'] ?? ($payload['fileBase64'] ?? ($payload['dataUri'] ?? null));
        $isUndertaking = !empty($payload['isUndertaking']);
        $undertakingReason = trim($payload['undertakingReason'] ?? '');
        $undertakingDeadline = trim($payload['undertakingDeadline'] ?? '');

        if (empty($identifier) || empty($docKey)) {
            return ['success' => false, 'message' => 'Student identifier and document requirement key are required.', 'code' => 400];
        }

        // Authorization check: Must be staff, matching student session, or valid applicant with verified PIN
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        initSession();
        $adminSess   = $_SESSION['gncp_admin_user'] ?? null;
        $stationSess = $_SESSION['gncp_station_user'] ?? null;
        $studentSess = $_SESSION['gncp_student'] ?? null;
        $sessStudentId = is_array($studentSess) ? ($studentSess['id'] ?? ($studentSess['username'] ?? '')) : '';

        $isStaff = ($adminSess !== null || $stationSess !== null);
        $isMatchingStudent = (!empty($sessStudentId) && strcasecmp($sessStudentId, $identifier) === 0);

        if (!$isStaff && !$isMatchingStudent) {
            $reqPin = trim($payload['pin'] ?? ($payload['tempPin'] ?? ($_GET['pin'] ?? ($_POST['pin'] ?? ''))));
            if (empty($reqPin)) {
                session_write_close();
                return ['success' => false, 'message' => 'Unauthorized: Valid security PIN is required to upload student documents.', 'code' => 401];
            }
            $peCheck = $this->pdo->prepare("SELECT `temp_pin` FROM `pre_enrollments` WHERE LOWER(`temp_student_id`) = LOWER(:id1) OR LOWER(COALESCE(`existing_student_id`, '')) = LOWER(:id2) LIMIT 1");
            $peCheck->execute(['id1' => $identifier, 'id2' => $identifier]);
            $storedPin = (string)$peCheck->fetchColumn();
            if (empty($storedPin) || !hash_equals($storedPin, $reqPin)) {
                session_write_close();
                return ['success' => false, 'message' => 'Unauthorized: Invalid security PIN credentials for document upload.', 'code' => 401];
            }
        }
        session_write_close();

        $uploadDir = __DIR__ . '/../../uploads/documents';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $softCopyUrl = null;
        $fileSize = 0;
        $maxPdfSize = 10 * 1024 * 1024; // 10MB limit

        // 1. Process Base64 payload
        if (!empty($fileData)) {
            if (preg_match('/^data:([a-zA-Z0-9\/+-]+);base64,(.+)$/', $fileData, $matches)) {
                $fileType = $matches[1];
                $binaryData = base64_decode($matches[2]);
            } else {
                $binaryData = base64_decode($fileData);
            }

            if ($binaryData === false || strlen($binaryData) === 0) {
                return ['success' => false, 'message' => 'Invalid base64 document content.', 'code' => 400];
            }

            if (strlen($binaryData) > $maxPdfSize) {
                return ['success' => false, 'message' => 'Uploaded document exceeds maximum allowed size of 10MB.', 'code' => 400];
            }

            // Strict PDF Header Magic-Byte Validation (%PDF-)
            if (strncmp($binaryData, "%PDF-", 5) !== 0) {
                return ['success' => false, 'message' => 'Security Error: Document requirements must be legitimate PDF files (missing PDF header signature).', 'code' => 400];
            }

            // Polyglot / Embedded Executable Payload Protection
            if (preg_match('/<\?php|<\?=|<script\b|eval\s*\(|base64_decode\s*\(/i', $binaryData)) {
                return ['success' => false, 'message' => 'Security Error: Malicious executable or script patterns detected inside PDF payload.', 'code' => 400];
            }

            // MIME Type Verification via finfo
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_buffer($finfo, $binaryData);
            finfo_close($finfo);

            if ($detectedMime !== 'application/pdf') {
                return ['success' => false, 'message' => 'Security Error: Uploaded file is not a valid PDF document (detected: ' . htmlspecialchars($detectedMime) . ').', 'code' => 400];
            }

            $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $docKey);
            $cleanStudent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
            $randomToken = bin2hex(random_bytes(8));
            $targetName = "doc_{$cleanKey}_{$cleanStudent}_{$randomToken}.pdf";
            $targetPath = $uploadDir . '/' . $targetName;

            if (file_put_contents($targetPath, $binaryData) === false) {
                return ['success' => false, 'message' => 'Failed to write uploaded file to disk.', 'code' => 500];
            }

            $appBase = (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/systemtest/') !== false) ? '/systemtest' : '';
            $softCopyUrl = "{$appBase}/uploads/documents/{$targetName}";
            $fileSize = strlen($binaryData);
            $fileType = 'application/pdf';
            if (empty($fileName)) {
                $fileName = $targetName;
            }
        } elseif (!empty($_FILES['file']['tmp_name'])) {
            // 2. Process Multipart Upload
            $file = $_FILES['file'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                return ['success' => false, 'message' => 'File upload error occurred.', 'code' => 400];
            }

            if ($file['size'] > $maxPdfSize) {
                return ['success' => false, 'message' => 'Uploaded document exceeds maximum allowed size of 10MB.', 'code' => 400];
            }

            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                return ['success' => false, 'message' => 'Security Error: Document requirements must accept ONLY PDF files (.pdf extension required).', 'code' => 400];
            }

            // Verify MIME type using finfo_file
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $detectedMime = finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);

            if ($detectedMime !== 'application/pdf') {
                return ['success' => false, 'message' => 'Security Error: Uploaded file is not a valid PDF document (detected: ' . htmlspecialchars($detectedMime) . ').', 'code' => 400];
            }

            // Verify Magic Bytes (%PDF-)
            $handle = @fopen($file['tmp_name'], 'rb');
            $header = $handle ? @fread($handle, 5) : '';
            if ($handle) @fclose($handle);

            if ($header !== '%PDF-') {
                return ['success' => false, 'message' => 'Security Error: Document requirements must be legitimate PDF files (missing %PDF- signature).', 'code' => 400];
            }

            // Polyglot / Embedded Executable Payload Protection
            $rawContent = @file_get_contents($file['tmp_name']);
            if ($rawContent && preg_match('/<\?php|<\?=|<script\b|eval\s*\(|base64_decode\s*\(/i', $rawContent)) {
                return ['success' => false, 'message' => 'Security Error: Malicious executable or script patterns detected inside PDF file.', 'code' => 400];
            }

            $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '_', $docKey);
            $cleanStudent = preg_replace('/[^a-zA-Z0-9_-]/', '_', $identifier);
            $randomToken = bin2hex(random_bytes(8));
            $targetName = "doc_{$cleanKey}_{$cleanStudent}_{$randomToken}.pdf";
            $targetPath = $uploadDir . '/' . $targetName;

            if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
                return ['success' => false, 'message' => 'Failed to move uploaded document.', 'code' => 500];
            }

            $appBase = (isset($_SERVER['SCRIPT_NAME']) && strpos($_SERVER['SCRIPT_NAME'], '/systemtest/') !== false) ? '/systemtest' : '';
            $softCopyUrl = "{$appBase}/uploads/documents/{$targetName}";
            $fileName = basename($file['name']);
            $fileType = 'application/pdf';
            $fileSize = $file['size'];
        } elseif (!$isUndertaking) {
            return ['success' => false, 'message' => 'No document file data provided.', 'code' => 400];
        }

        try {
            $updated = $this->studentModel->saveStudentDocument(
                $identifier,
                $docKey,
                $softCopyUrl,
                $fileName,
                $fileType,
                $fileSize,
                $isUndertaking,
                $undertakingReason,
                $undertakingDeadline
            );

            return [
                'success' => true,
                'data' => $updated,
                'message' => 'Document uploaded successfully and submitted for Registrar review.'
            ];
        } catch (Exception $e) {
            logAppError("Upload Document Error: " . $e->getMessage(), ['identifier' => $identifier, 'docKey' => $docKey]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    public function verifyDocument($payload) {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);

        $identifier = trim($payload['studentId'] ?? ($payload['referenceNumber'] ?? ''));
        $docKey = trim($payload['docKey'] ?? ($payload['key'] ?? ''));
        $status = trim($payload['status'] ?? 'VERIFIED');
        $remarks = trim($payload['remarks'] ?? '');
        $verifiedBy = trim($payload['verifiedBy'] ?? 'Registrar Officer');

        if (empty($identifier) || empty($docKey)) {
            return ['success' => false, 'message' => 'Student reference and document key are required.', 'code' => 400];
        }

        try {
            $updated = $this->studentModel->verifyStudentDocument($identifier, $docKey, $status, $remarks, $verifiedBy);
            return [
                'success' => true,
                'data' => $updated,
                'message' => "Document requirement {$docKey} marked as {$status}."
            ];
        } catch (Exception $e) {
            logAppError("Verify Document Error: " . $e->getMessage(), ['identifier' => $identifier, 'docKey' => $docKey]);
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }

    public function cleanupTestRecords($payload) {
        require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
        requireAuth(['ADMIN', 'SUPER_ADMIN']);

        $pattern = $payload['email_pattern'] ?? 'test.student.%@gncp.edu.ph';
        try {
            $deleted = $this->studentModel->deleteTestRecords($pattern);
            return [
                'success' => true,
                'data' => ['deleted' => $deleted],
                'message' => "Successfully purged {$deleted} test records."
            ];
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError("Cleanup Test Records Error: " . $e->getMessage());
            }
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }
}
