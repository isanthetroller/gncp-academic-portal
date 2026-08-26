<?php
/**
 * GNCP Workstations — Station-Local API Router (LEGACY)
 * ======================================================
 * ⚠️  ARCHITECTURAL NOTE — DO NOT ADD NEW ACTIONS HERE  ⚠️
 *
 * This file is a legacy station-local router that predates the canonical
 * central REST gateway at `/api/index.php`. It remains functional and
 * has its own session auth guard, but it operates as a PARALLEL path,
 * which means requests routed here bypass the central X-Request-ID chain
 * and are not tracked by the canonical audit trail.
 *
 * CANONICAL ROUTES (use these in all frontend fetch() calls instead):
 *   fetch_queue      → GET  /api/index.php?action=stations/queue
 *   update_student   → POST /api/index.php?action=stations/update
 *   upload_photo     → POST /api/index.php?action=stations/upload_photo
 *
 * If you are adding a new workstation action, add it to:
 *   api/index.php ($routes array) + api/controllers/StationController.php
 *
 * This file is retained for backward compatibility with any station
 * HTML file that still references the legacy endpoints.
 */

require_once __DIR__ . '/../../shared/backend/utils/security_guard.php';
require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/logger.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';

require_once __DIR__ . '/services/QueueService.php';
require_once __DIR__ . '/services/PaymentService.php';
require_once __DIR__ . '/services/EnrollmentService.php';

// Emit X-Request-ID correlation header for all station API responses
$stationReqId = getRequestId();
header('X-Request-ID: ' . $stationReqId);

// Enforce session authentication for workstation endpoints
requireAuth(['REGISTRAR', 'HELPDESK', 'MEDICAL', 'CASHIER', 'IT_CENTER', 'ADMIN', 'SUPER_ADMIN']);

$action = $_GET['action'] ?? '';

try {
    $pdo = Database::getInstance();

    if ($action === 'fetch_queue') {
        $queue = QueueService::fetchQueue($pdo);
        sendResponse(true, $queue);

    } elseif ($action === 'update_student') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendResponse(false, null, 'Method not allowed. Use POST.', 405);
        }

        $rawInput = file_get_contents('php://input');
        $payload = json_decode($rawInput, true);

        if (!$payload || !isset($payload['referenceNumber'])) {
            sendResponse(false, null, 'Invalid payload or missing referenceNumber.', 400);
        }

        $resData = EnrollmentService::updateStudent($pdo, $payload);
        sendResponse(true, $resData, 'Student record updated successfully.');

    } elseif ($action === 'get_next_student_id') {
        $nextId = generateUniqueStudentId($pdo, '2026');
        sendResponse(true, ['nextStudentId' => $nextId]);

    } elseif ($action === 'upload_photo') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            sendResponse(false, null, 'Method not allowed. Use POST.', 405);
        }

        $rawInput = file_get_contents('php://input');
        $payload  = json_decode($rawInput, true);

        $resData = EnrollmentService::uploadPhoto($payload);
        sendResponse(true, $resData);

    } elseif ($action === 'get_enrollment_stats') {
        $stats = QueueService::getEnrollmentStats($pdo);
        sendResponse(true, $stats);

    } elseif ($action === 'fetch_student_accounts') {
        $students = QueueService::fetchStudentAccounts($pdo);
        sendResponse(true, $students);

    } else {
        sendResponse(false, null, 'Invalid action specified.', 400);
    }

} catch (Exception $e) {
    logAppError('Station API Gateway Error: ' . $e->getMessage(), ['action' => $action]);
    sendResponse(false, null, $e->getMessage(), 500);
}
