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
        $notes  = $inputData['registrarNotes'] ?? ($inputData['returnReason'] ?? ($inputData['notes'] ?? ''));
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
                $enrollData = json_decode((string)($studRecord['enrollment_data'] ?? ''), true) ?: [];
                if ($sectionCode !== null && $sectionCode !== '') {
                    $enrollData['assignedSection'] = $sectionCode;
                }
                if (strcasecmp($status, 'Approved') === 0) {
                    foreach ($studRoadmap as &$step) {
                        if (($step['stepId'] ?? '') === 'registrar_verification') {
                            $step['status'] = 'COMPLETED';
                            $step['updatedAt'] = date('c');
                        }
                    }
                }
                $upStmt = $pdo->prepare("UPDATE `students` SET `roadmap` = :roadmap, `requirements_data` = :req_data, `enrollment_data` = :enroll_data WHERE `id` = :id");
                $upStmt->execute([
                    'roadmap'     => json_encode($studRoadmap),
                    'req_data'    => $reqData ? json_encode($reqData) : $studRecord['requirements_data'],
                    'enroll_data' => json_encode($enrollData),
                    'id'          => $studRecord['id']
                ]);

                // Also update pre_enrollments section_code if temp_reference_no matches
                if (!empty($studRecord['temp_reference_no']) && $sectionCode) {
                    $upPre = $pdo->prepare("UPDATE `pre_enrollments` SET `section_code` = :sc WHERE `temp_student_id` = :tref");
                    $upPre->execute(['sc' => $sectionCode, 'tref' => $studRecord['temp_reference_no']]);
                }

                return [
                    'success' => true,
                    'data' => [
                        'referenceNumber' => $studRecord['temp_reference_no'] ?? $studRecord['id'],
                        'applicantName'   => $studRecord['name'],
                        'program'         => $studRecord['program'],
                        'yearLevel'       => $studRecord['year_level'] ?? '1st Year',
                        'status'          => $studRecord['status'],
                        'sectionCode'     => $sectionCode ?: ($enrollData['assignedSection'] ?? null),
                        'assignedSection' => $sectionCode ?: ($enrollData['assignedSection'] ?? null),
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

        $operator = $_SESSION['gncp_station_user']['name'] ?? $_SESSION['gncp_admin_user']['name'] ?? ($_SESSION['gncp_station_user']['username'] ?? ($_SESSION['gncp_admin_user']['username'] ?? 'Registrar Staff'));
        $operatorUsername = $_SESSION['gncp_station_user']['username'] ?? $_SESSION['gncp_admin_user']['username'] ?? 'registrar_officer';

        $isApproved = in_array(strtoupper($status), ['APPROVED', 'VERIFIED']);
        $isReturned = in_array(strtoupper($status), ['RETURNED', 'NEEDS_CORRECTION']);
        $isRejected = in_array(strtoupper($status), ['REJECTED', 'DISAPPROVED']);

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
                if ($sid === 'registrar_verification' || $sid === 'registrar_review') {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = date('c');
                    $step['operator'] = $operator;
                }
                if (($sid === 'advising_assessment' || $sid === 'academic_advising') && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', 'RETURNED'])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = date('c');
                }
            }
            unset($step);
        } elseif ($isReturned) {
            $status = 'RETURNED';
            foreach ($roadmap as &$step) {
                $sid = $step['stepId'] ?? '';
                if ($sid === 'registrar_verification' || $sid === 'registrar_review') {
                    $step['status'] = 'RETURNED';
                    $step['updatedAt'] = date('c');
                    $step['notes'] = $notes;
                    $step['operator'] = $operator;
                }
            }
            unset($step);
        } elseif ($isRejected) {
            $status = 'REJECTED';
            foreach ($roadmap as &$step) {
                $sid = $step['stepId'] ?? '';
                if ($sid === 'registrar_verification' || $sid === 'registrar_review') {
                    $step['status'] = 'FLAGGED';
                    $step['updatedAt'] = date('c');
                    $step['notes'] = $notes;
                    $step['operator'] = $operator;
                }
            }
            unset($step);
        }

        // Requirements payload handling
        // ── Format-aware merge to prevent Format A (registrar object with docs:{}) ──
        // from clobbering Format B (student-portal array with softCopyUrl data).
        // Strategy: if incoming reqData is Format A and stored data contains Format B
        // file entries, preserve the softCopyUrl, fileName, etc. per-doc by injecting
        // them into the Format A docs entries before saving.
        $currentReqData = json_decode((string)($record['requirements_data'] ?? ''), true) ?: [];

        if ($reqData && is_array($reqData)) {
            if (!empty($reqData['docs']) && is_array($reqData['docs'])) {
                // Incoming is Format A. Preserve any softcopy file data from prior Format B entries.
                $priorFileMap = [];
                if (isset($currentReqData[0])) {
                    // Current stored is Format B — build file map by key
                    foreach ($currentReqData as $priorItem) {
                        if (!empty($priorItem['key']) && !empty($priorItem['softCopyUrl'])) {
                            $priorFileMap[$priorItem['key']] = [
                                'softCopyUrl' => $priorItem['softCopyUrl'],
                                'fileName'    => $priorItem['fileName'] ?? null,
                                'fileType'    => $priorItem['fileType'] ?? null,
                                'fileSize'    => $priorItem['fileSize'] ?? null,
                                'submittedAt' => $priorItem['submittedAt'] ?? null,
                            ];
                        }
                    }
                } elseif (!empty($currentReqData['docs']) && is_array($currentReqData['docs'])) {
                    // Current stored is Format A — check each doc for embedded file data
                    foreach ($currentReqData['docs'] as $priorKey => $priorEntry) {
                        if (is_array($priorEntry) && !empty($priorEntry['softCopyUrl'])) {
                            $priorFileMap[$priorKey] = [
                                'softCopyUrl' => $priorEntry['softCopyUrl'],
                                'fileName'    => $priorEntry['fileName'] ?? null,
                                'fileType'    => $priorEntry['fileType'] ?? null,
                                'fileSize'    => $priorEntry['fileSize'] ?? null,
                                'submittedAt' => $priorEntry['submittedAt'] ?? null,
                            ];
                        }
                    }
                }

                // Merge prior file data into incoming Format A docs (non-destructive)
                foreach ($reqData['docs'] as $dKey => &$dEntry) {
                    if (!is_array($dEntry)) $dEntry = ['status' => (string)$dEntry];
                    if (!empty($priorFileMap[$dKey])) {
                        $dEntry = array_merge($priorFileMap[$dKey], $dEntry);
                    }
                }
                unset($dEntry);
            }
            $currentReqData = array_merge($currentReqData, $reqData);
        }
        $currentReqData['status'] = $status;
        if ($isApproved) {
            $currentReqData['verifiedBy'] = $operator;
            $currentReqData['dateVerified'] = date('c');
        } elseif ($isReturned) {
            $currentReqData['returnReason'] = $notes;
            $currentReqData['returnedBy'] = $operator;
            $currentReqData['dateReturned'] = date('c');
        }
        if (!empty($notes)) {
            $currentReqData['notes'] = $notes;
        }

        $roadmapJson = json_encode($roadmap);

        $stmt = $pdo->prepare("UPDATE `pre_enrollments` 
                               SET `status` = :status, `roadmap` = :roadmap, `registrar_notes` = :notes, `requirements_data` = :req_data, `section_code` = :sect_code 
                               WHERE `temp_student_id` = :ref");
        $stmt->execute([
            'status'  => $status,
            'roadmap' => $roadmapJson,
            'notes'   => $notes,
            'req_data'=> json_encode($currentReqData),
            'sect_code'=> $sectionCode !== null ? $sectionCode : $record['section_code'],
            'ref'     => $refNum
        ]);

        // Audit Trail: Record mutation in audit_logs
        try {
            $actionName = $isApproved ? 'REQUIREMENTS_VERIFIED' : ($isReturned ? 'RETURNED_FOR_CORRECTION' : ($isRejected ? 'APPLICATION_REJECTED' : 'STATUS_UPDATE'));
            $auditStmt = $pdo->prepare("
                INSERT INTO `audit_logs` (`reference_number`, `operator_username`, `station_role`, `action_performed`, `previous_state`, `new_state`)
                VALUES (:ref, :operator, 'REGISTRAR', :action, :prev, :new)
            ");
            $auditStmt->execute([
                'ref'      => $refNum,
                'operator' => $operatorUsername,
                'action'   => $actionName,
                'prev'     => json_encode(['status' => $record['status'] ?? 'PRE_REGISTERED']),
                'new'      => json_encode(['status' => $status, 'notes' => $notes, 'operator' => $operator, 'timestamp' => date('c')])
            ]);
        } catch (Exception $e) {
            // Non-blocking error
        }

        $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNum]);
        $updatedRow = $stmt->fetch(PDO::FETCH_ASSOC);

        $fullName = trim($updatedRow['first_name'] . ' ' . ($updatedRow['middle_name'] ? $updatedRow['middle_name'] . ' ' : '') . $updatedRow['last_name']);
        $isReviewedToday = in_array($updatedRow['status'], ['Approved', 'Rejected', 'VERIFIED', 'RETURNED']);
        if (!function_exists('getRequirementsForType')) {
            require_once __DIR__ . '/../utils/student.php';
        }
        $requirements = function_exists('getRequirementsForType')
            ? getRequirementsForType($updatedRow['student_type'] ?? 'FRESHMAN', $updatedRow['shs_track'] ?? '')
            : ['Form 138 / Report Card', 'Certificate of Good Moral Character', 'PSA Birth Certificate', '2x2 Pictures'];

        $updatedReqData = json_decode((string)($updatedRow['requirements_data'] ?? ''), true) ?: $currentReqData;

        return [
            'success' => true,
            'data' => [
                'referenceNumber' => $updatedRow['temp_student_id'],
                'applicantName'   => $fullName ?: 'New Applicant',
                'program'         => $updatedRow['course_code'],
                'yearLevel'       => !empty($updatedRow['year_level_applied']) ? $updatedRow['year_level_applied'] : '1st Year',
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
