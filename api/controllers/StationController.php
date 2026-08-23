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

    public function updateStudent($payload) {
        $user = requireAuth(['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'SUPER_ADMIN']);
        $userRole = strtoupper($user['role'] ?? '');
        $refNo = $payload['referenceNumber'] ?? '';
        $updateData = $payload['updateData'] ?? [];

        if (!$refNo || empty($updateData)) {
            return ['success' => false, 'message' => 'Reference number or update details missing.', 'code' => 400];
        }

        // Fine-grained Station RBAC Validation
        if (!in_array($userRole, ['ADMIN', 'SUPER_ADMIN'], true)) {
            if (isset($updateData['payment']) || !empty($updateData['or_number'])) {
                if ($userRole !== 'CASHIER') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Cashier officers may process payment updates or issue Official Receipts.', 'code' => 403];
                }
            }
            if (isset($updateData['medical'])) {
                if ($userRole !== 'MEDICAL') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Medical officers may update medical examination and clearance data.', 'code' => 403];
                }
            }
            if (isset($updateData['helpdesk']) || isset($updateData['scholarship'])) {
                if ($userRole !== 'HELPDESK') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Helpdesk officers may update academic advising, course subjects, or scholarships.', 'code' => 403];
                }
            }
            if (isset($updateData['requirements'])) {
                if ($userRole !== 'REGISTRAR') {
                    return ['success' => false, 'message' => 'Unauthorized: Only Registrar officers may verify applicant requirements.', 'code' => 403];
                }
            }
            if (isset($updateData['enrollment']) || (isset($updateData['status']) && strtoupper($updateData['status']) === 'ENROLLED')) {
                if ($userRole !== 'IT_CENTER') {
                    return ['success' => false, 'message' => 'Unauthorized: Only IT Center officers may activate student portal accounts and promote enrollment status.', 'code' => 403];
                }
            }
        }

        try {
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
