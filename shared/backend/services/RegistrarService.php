<?php
/**
 * Registrar Service — Processes application reviews, requirements verification, and roadmap step progression.
 * Note: promotePreEnrollmentToStudent() is sourced from shared/backend/utils/student.php (loaded below).
 */

require_once __DIR__ . '/../utils/student.php';

class RegistrarService {

    public static function updateApplicationStatus(PDO $pdo, array $inputData) {
        $refNum = $inputData['referenceNumber'] ?? null;
        $status = $inputData['status'] ?? null;
        $notes  = $inputData['registrarNotes'] ?? '';
        $reqData = $inputData['requirementsData'] ?? null;
        $sectionCode = $inputData['sectionCode'] ?? null;

        if (!$refNum || !$status) {
            return ['success' => false, 'message' => 'Reference number and status are required.', 'code' => 400];
        }

        $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNum]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            // Check permanent students directory
            $stmtStud = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :r1 OR `temp_reference_no` = :r2");
            $stmtStud->execute(['r1' => $refNum, 'r2' => $refNum]);
            $studRecord = $stmtStud->fetch(PDO::FETCH_ASSOC);

            if ($studRecord) {
                $studRoadmap = json_decode((string)($studRecord['roadmap'] ?? ''), true) ?: [];
                if (strcasecmp($status, 'Approved') === 0) {
                    foreach ($studRoadmap as &$step) {
                        if (($step['stepId'] ?? '') === 'registrar_verification') {
                            $step['status'] = 'COMPLETED';
                            $step['updatedAt'] = date('c');
                        }
                    }
                }
                $upStmt = $pdo->prepare("UPDATE `students` SET `roadmap` = :roadmap, `requirements_data` = :req_data WHERE `id` = :id");
                $upStmt->execute([
                    'roadmap'  => json_encode($studRoadmap),
                    'req_data' => $reqData ? json_encode($reqData) : $studRecord['requirements_data'],
                    'id'       => $studRecord['id']
                ]);
                return [
                    'success' => true,
                    'data' => [
                        'referenceNumber' => $studRecord['temp_reference_no'] ?? $studRecord['id'],
                        'applicantName'   => $studRecord['name'],
                        'program'         => $studRecord['program'],
                        'status'          => $studRecord['status'],
                        'reviewedToday'   => true,
                        'roadmap'         => $studRoadmap
                    ]
                ];
            }
            return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
        }

        if (strcasecmp($record['status'], 'Rejected') === 0) {
            return ['success' => false, 'message' => 'This application has been permanently rejected and status changes are prohibited.', 'code' => 403];
        }

        $roadmap = json_decode((string)($record['roadmap'] ?? ''), true) ?: [];

        $isApproved = in_array(strtoupper($status), ['APPROVED', 'VERIFIED']);
        if ($isApproved) {
            $status = 'VERIFIED';
            foreach ($roadmap as &$step) {
                $sid = $step['stepId'] ?? '';
                if ($sid === 'online_prereg' || $sid === 'online_registration') {
                    $step['status'] = 'COMPLETED';
                    if (empty($step['updatedAt'])) {
                        $step['updatedAt'] = date('c');
                    }
                }
                if ($sid === 'registrar_verification') {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = date('c');
                }
                if (($sid === 'advising_assessment' || $sid === 'academic_advising') && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED'])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = date('c');
                }
            }
        }

        $roadmapJson = json_encode($roadmap);

        $stmt = $pdo->prepare("UPDATE `pre_enrollments` 
                               SET `status` = :status, `roadmap` = :roadmap, `registrar_notes` = :notes, `requirements_data` = :req_data, `section_code` = :sect_code 
                               WHERE `temp_student_id` = :ref");
        $stmt->execute([
            'status'  => $status,
            'roadmap' => $roadmapJson,
            'notes'   => $notes,
            'req_data'=> $reqData ? json_encode($reqData) : $record['requirements_data'],
            'sect_code'=> $sectionCode !== null ? $sectionCode : $record['section_code'],
            'ref'     => $refNum
        ]);

        $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNum]);
        $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $fullName = trim($updatedRow['first_name'] . ' ' . ($updatedRow['middle_name'] ? $updatedRow['middle_name'] . ' ' : '') . $updatedRow['last_name']);
        $isReviewedToday = in_array($updatedRow['status'], ['Approved', 'Rejected']);
        if (!function_exists('getRequirementsForType')) {
            require_once __DIR__ . '/../utils/student.php';
        }
        $requirements = function_exists('getRequirementsForType')
            ? getRequirementsForType($updatedRow['student_type'] ?? 'FRESHMAN', $updatedRow['shs_track'] ?? '')
            : ['Form 138 / Report Card', 'Certificate of Good Moral Character', 'PSA Birth Certificate', '2x2 Pictures'];

        $updatedReqData = json_decode((string)($updatedRow['requirements_data'] ?? ''), true) ?: [
            'status' => 'PENDING',
            'docs' => ['psa' => 'not-submitted', 'reportCard' => 'not-submitted', 'goodMoral' => 'not-submitted'],
            'notes' => '',
            'verifiedBy' => '',
            'dateVerified' => ''
        ];

        return [
            'success' => true,
            'data' => [
                'referenceNumber' => $updatedRow['temp_student_id'],
                'applicantName'   => $fullName ?: 'New Applicant',
                'program'         => $updatedRow['course_code'],
                'studentType'     => $updatedRow['student_type'] ?? 'FRESHMAN',
                'nstp'            => $updatedRow['nstp'] ?? 'N/A',
                'dateSubmitted'   => date('Y-m-d', strtotime($updatedRow['created_at'])),
                'status'          => $updatedRow['status'],
                'reviewedToday'   => $isReviewedToday,
                'sectionCode'     => $updatedRow['section_code'],
                'personalInfo'    => ['birthDate' => $updatedRow['birth_date'], 'gender' => $updatedRow['gender'], 'address' => $updatedRow['address']],
                'contactInfo'     => ['email' => $updatedRow['email'], 'phone' => $updatedRow['phone'], 'guardian' => $updatedRow['emergency_contact_name']],
                'requirements'    => $requirements,
                'requirementsData'=> $updatedReqData,
                'roadmap'         => $roadmap,
                'registrarNotes'  => $updatedRow['registrar_notes'] ?? ($updatedRow['roadmap'] ? 'Tracking steps established' : 'Awaiting review.')
            ]
        ];
    }

    public static function updateRoadmapStep(PDO $pdo, array $inputData) {
        $refNum = $inputData['referenceNumber'] ?? null;
        $stepId = $inputData['stepId'] ?? null;
        $status = $inputData['status'] ?? null;

        if (!$refNum || !$stepId || !$status) {
            return ['success' => false, 'message' => 'Reference number, step ID, and status are required.', 'code' => 400];
        }

        $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNum]);
        $record = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$record) {
            $stmtStud = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :r1 OR `temp_reference_no` = :r2");
            $stmtStud->execute(['r1' => $refNum, 'r2' => $refNum]);
            $studRecord = $stmtStud->fetch(PDO::FETCH_ASSOC);

            if ($studRecord) {
                $studRoadmap = json_decode((string)($studRecord['roadmap'] ?? ''), true) ?: [];
                foreach ($studRoadmap as &$step) {
                    if (($step['stepId'] ?? '') === $stepId) {
                        $step['status'] = $status;
                        $step['updatedAt'] = date('c');
                    }
                }
                $upStmt = $pdo->prepare("UPDATE `students` SET `roadmap` = :roadmap WHERE `id` = :id");
                $upStmt->execute([
                    'roadmap' => json_encode($studRoadmap),
                    'id'      => $studRecord['id']
                ]);
                return [
                    'success' => true,
                    'data' => [
                        'referenceNumber' => $studRecord['temp_reference_no'] ?? $studRecord['id'],
                        'roadmap'         => $studRoadmap
                    ]
                ];
            }
            return ['success' => false, 'message' => 'Student record not found.', 'code' => 404];
        }

        if (strcasecmp($record['status'], 'Rejected') === 0) {
            return ['success' => false, 'message' => 'This application has been permanently rejected and status changes are prohibited.', 'code' => 403];
        }

        $roadmap = json_decode((string)($record['roadmap'] ?? ''), true) ?: [];
        $allDone = true;
        foreach ($roadmap as &$step) {
            if ($step['stepId'] === $stepId) {
                $step['status'] = $status;
                $step['updatedAt'] = date('c');
            }
            if ($step['status'] !== 'COMPLETED' && $step['status'] !== 'SKIPPED') {
                $allDone = false;
            }
        }

        $roadmapJson = json_encode($roadmap);
        $dbStatus = $record['status'];

        if ($allDone && $dbStatus !== 'ENROLLED') {
            $dbStatus = 'ENROLLED';
            $responseDetails = promotePreEnrollmentToStudent($pdo, $record, $refNum, $roadmapJson);
            return ['success' => true, 'data' => $responseDetails, 'message' => 'Roadmap step updated and student enrollment finalized.'];
        }

        $updateStmt = $pdo->prepare("UPDATE `pre_enrollments` SET `roadmap` = :roadmap, `status` = :status WHERE `temp_student_id` = :ref");
        $updateStmt->execute(['roadmap' => $roadmapJson, 'status' => $dbStatus, 'ref' => $refNum]);

        $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNum]);
        $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $fullName = trim($updatedRow['first_name'] . ' ' . ($updatedRow['middle_name'] ? $updatedRow['middle_name'] . ' ' : '') . $updatedRow['last_name']);
        if (!function_exists('getRequirementsForType')) {
            require_once __DIR__ . '/../utils/student.php';
        }
        $requirements = function_exists('getRequirementsForType')
            ? getRequirementsForType($updatedRow['student_type'] ?? 'FRESHMAN', $updatedRow['shs_track'] ?? '')
            : ['Form 138 / Report Card', 'Certificate of Good Moral Character', 'PSA Birth Certificate', '2x2 Pictures'];

        return [
            'success' => true,
            'data' => [
                'referenceNumber' => $updatedRow['temp_student_id'],
                'applicantName'   => $fullName ?: 'New Applicant',
                'program'         => $updatedRow['course_code'],
                'studentType'     => $updatedRow['student_type'] ?? 'FRESHMAN',
                'nstp'            => $updatedRow['nstp'] ?? 'N/A',
                'dateSubmitted'   => date('Y-m-d', strtotime($updatedRow['created_at'])),
                'status'          => $updatedRow['status'],
                'reviewedToday'   => in_array($updatedRow['status'], ['Approved', 'Rejected']),
                'personalInfo'    => ['birthDate' => $updatedRow['birth_date'], 'gender' => $updatedRow['gender'], 'address' => $updatedRow['address']],
                'contactInfo'     => ['email' => $updatedRow['email'], 'phone' => $updatedRow['phone'], 'guardian' => $updatedRow['emergency_contact_name']],
                'requirements'    => $requirements,
                'roadmap'         => $roadmap,
                'registrarNotes'  => $updatedRow['registrar_notes'] ?? ($updatedRow['roadmap'] ? 'Tracking steps established' : 'Awaiting review.')
            ]
        ];
    }
}
