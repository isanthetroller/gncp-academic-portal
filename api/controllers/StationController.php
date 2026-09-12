<?php
/**
 * Station Controller — Handles live queue board and workstation data sync directly with MySQL
 */
require_once __DIR__ . '/../models/StudentModel.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../stations/backend/services/EnrollmentService.php';

class StationController {
    private $studentModel;
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->studentModel = new StudentModel($pdo);
    }

    public function getQueue() {
        requireAuth(['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'SUPER_ADMIN']);
        return [
            'success' => true,
            'data' => $this->studentModel->getQueue()
        ];
    }

    public function getHistory($stationRole = null) {
        $user = requireAuth(['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'SUPER_ADMIN']);
        $role = $stationRole ?: ($user['role'] ?? 'ALL');
        require_once __DIR__ . '/../../stations/backend/services/QueueService.php';
        return [
            'success' => true,
            'data'    => QueueService::fetchStationHistory($this->pdo, $role)
        ];
    }

    public function updateStudent($payload) {
        $user = requireAuth(['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'SUPER_ADMIN']);
        $userRole = strtoupper($user['role'] ?? '');
        $refNo = $payload['referenceNumber'] ?? ($payload['studentId'] ?? ($payload['temp_student_id'] ?? ''));
        $updateData = $payload['updateData'] ?? [];

        if (empty($updateData)) {
            if (isset($payload['helpdeskData']) || isset($payload['helpdesk'])) {
                $updateData['helpdesk'] = $payload['helpdeskData'] ?? $payload['helpdesk'];
            }
            if (isset($payload['medicalData']) || isset($payload['medical'])) {
                $updateData['medical'] = $payload['medicalData'] ?? $payload['medical'];
            }
            if (isset($payload['paymentData']) || isset($payload['payment'])) {
                $updateData['payment'] = $payload['paymentData'] ?? $payload['payment'];
            }
            if (isset($payload['studentId']) || isset($payload['enrollment'])) {
                $updateData['enrollment'] = $payload['enrollment'] ?? [
                    'studentId' => $payload['studentId'] ?? null,
                    'status'    => 'ENROLLED'
                ];
            }
        }

        if (!$refNo || empty($updateData)) {
            return ['success' => false, 'message' => 'Reference number or update details missing.', 'code' => 400];
        }

        // Fine-grained Station RBAC Validation
        if (!in_array($userRole, ['ADMIN', 'SUPER_ADMIN'], true)) {
            // 1. Target Status Transition Ownership
            if (isset($updateData['status'])) {
                $targetSt = strtoupper(trim($updateData['status']));
                if (in_array($targetSt, ['VERIFIED', 'REJECTED'], true) && $userRole !== 'REGISTRAR') {
                    return ['success' => false, 'message' => "Unauthorized: Only Registrar officers may transition application status to {$targetSt}.", 'code' => 403];
                }
                if ($targetSt === 'ADVISED' && $userRole !== 'HELPDESK') {
                    return ['success' => false, 'message' => "Unauthorized: Only Helpdesk officers may transition status to ADVISED.", 'code' => 403];
                }
                if ($targetSt === 'MEDICAL_CLEARED' && $userRole !== 'MEDICAL') {
                    return ['success' => false, 'message' => "Unauthorized: Only Medical officers may transition status to MEDICAL_CLEARED.", 'code' => 403];
                }
                if ($targetSt === 'PAID' && $userRole !== 'CASHIER') {
                    return ['success' => false, 'message' => "Unauthorized: Only Cashier officers may transition status to PAID.", 'code' => 403];
                }
                if ($targetSt === 'ENROLLED' && $userRole !== 'IT_CENTER') {
                    return ['success' => false, 'message' => "Unauthorized: Only IT Center officers may promote enrollment status to ENROLLED.", 'code' => 403];
                }
            }

            // 2. Active Domain Mutation Ownership
            if (isset($updateData['enrollment']) && $userRole !== 'IT_CENTER') {
                return ['success' => false, 'message' => 'Unauthorized: Only IT Center officers may activate student portal accounts.', 'code' => 403];
            }

            if (isset($updateData['requirements']) && $userRole !== 'REGISTRAR') {
                return ['success' => false, 'message' => 'Unauthorized: Only Registrar officers may verify or update application requirements.', 'code' => 403];
            }

            if (isset($updateData['payment']) || !empty($updateData['or_number'])) {
                $pPayload = $updateData['payment'] ?? [];
                $isActualPayment = (isset($pPayload['amountPaid']) && (float)$pPayload['amountPaid'] > 0) ||
                                   in_array(strtoupper($pPayload['status'] ?? ''), ['PAID', 'PARTIAL', 'COMPLETED'], true) ||
                                   !empty($updateData['or_number']);
                if ($isActualPayment && $userRole !== 'CASHIER') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Cashier officers may process payment updates or issue Official Receipts.', 'code' => 403];
                }
            }

            if (isset($updateData['medical'])) {
                $mPayload = $updateData['medical'] ?? [];
                $isActualMedical = in_array(strtoupper($mPayload['status'] ?? ''), ['FIT', 'UNFIT', 'CONDITIONAL', 'COMPLETED'], true) ||
                                   !empty($mPayload['verifiedBy']) || !empty($mPayload['notes']) || !empty($mPayload['vitalSigns']);
                if ($isActualMedical && $userRole !== 'MEDICAL') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Medical officers may update medical examination and clearance data.', 'code' => 403];
                }
            }

            if (isset($updateData['helpdesk']) || isset($updateData['scholarship'])) {
                $hPayload = $updateData['helpdesk'] ?? [];
                $isActualHelpdesk = in_array(strtoupper($hPayload['status'] ?? ''), ['COMPLETED', 'ADVISED', 'FLAGGED'], true) ||
                                   !empty($hPayload['advisedSubjects']) || !empty($hPayload['section']);
                if ($isActualHelpdesk && $userRole !== 'HELPDESK') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Helpdesk officers may update academic advising, course subjects, or scholarships.', 'code' => 403];
                }
            }
        }

        try {
            $payload['updateData'] = $updateData;
            $payload['referenceNumber'] = $refNo;
            $resData = EnrollmentService::updateStudent($this->pdo, $payload);
            return [
                'success' => true,
                'message' => 'Student record updated successfully.',
                'data'    => $resData
            ];
        } catch (InvalidArgumentException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 400];
        } catch (DomainException $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 403];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage(), 'code' => 500];
        }
    }
}
