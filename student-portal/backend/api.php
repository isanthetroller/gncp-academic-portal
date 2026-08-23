<?php
/**
 * GNCP Student Portal — Backward-Compatibility API Adapter
 * =======================================================
 * This file acts as a compatibility adapter proxying legacy requests
 * into the unified StudentPortalController and StudentPortalService.
 *
 * Canonical REST routes should be consumed via `/api/index.php?action=student_portal/...`.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/logger.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../api/controllers/StudentPortalController.php';
require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';

$reqId = getRequestId();
header('X-Request-ID: ' . $reqId);

try {
    $pdo = Database::getInstance();
    $controller = new StudentPortalController($pdo);

    $action = $_GET['action'] ?? '';
    $rawInput = file_get_contents('php://input');
    $payload = !empty($rawInput) ? json_decode($rawInput, true) : [];

    switch ($action) {
        case 'login':
        case 'login_student':
            $res = $controller->login($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 401));
            break;

        case 'get_student_dashboard':
            $res = $controller->getDashboard($_GET);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'update_student_profile':
            $res = $controller->updateProfile($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'change_student_password':
            $res = $controller->changePassword($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'request_password_reset':
            $res = $controller->requestPasswordReset($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'reset_password_with_code':
            $res = $controller->resetPasswordWithCode($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'fetch_announcements':
            $res = AnnouncementService::getAnnouncements($pdo, ['status' => 'PUBLISHED', 'target_audience' => 'STUDENTS']);
            sendResponse($res['success'], $res['data'] ?? [], $res['message'] ?? '');
            break;

        case 'fetch_milestones':
            $res = MilestoneService::getMilestones($pdo, $_GET);
            sendResponse($res['success'], $res['data'] ?? [], $res['message'] ?? '');
            break;

        case 'get_student_documents':
        case 'student_documents':
            $res = $controller->getDocuments($_GET);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'upload_student_document':
            $res = $controller->uploadDocument($payload);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', $res['code'] ?? ($res['success'] ? 200 : 400));
            break;

        case 'logout':
            $res = $controller->logout();
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? '', 200);
            break;

        default:
            sendResponse(false, null, 'Invalid action specified.', 400);
            break;
    }

} catch (Exception $e) {
    logAppError("Student Portal Adapter Error: " . $e->getMessage(), ['action' => $action ?? 'unknown']);
    sendResponse(false, null, 'An unexpected issue occurred while processing your request.', 500);
}
