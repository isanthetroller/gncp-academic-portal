<?php
/**
 * GNCP Registrar Portal — Central API Controller Wrapper (Thin Delegation Shim)
 * ============================================================================
 * Canonical routes are hosted in /api/index.php. This file delegates directly to
 * RegistrarAdminController and central services for full backward compatibility.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../shared/backend/utils/logger.php';
require_once __DIR__ . '/../../shared/backend/services/SectionService.php';
require_once __DIR__ . '/../../shared/backend/services/RegistrarService.php';
require_once __DIR__ . '/../../api/controllers/RegistrarAdminController.php';

requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);

$action = $_GET['action'] ?? null;
$rawInput = file_get_contents('php://input');
$inputData = $rawInput ? json_decode($rawInput, true) : [];

if ($rawInput && json_last_error() !== JSON_ERROR_NONE) {
    sendResponse(false, null, 'Invalid JSON payload received.', 400);
}

if (!$action && isset($inputData['action'])) {
    $action = $inputData['action'];
}

if (!$action) {
    sendResponse(false, null, 'Action parameter is required.', 400);
}

try {
    $pdo = Database::getInstance();
    $controller = new RegistrarAdminController($pdo);

    switch ($action) {
        case 'fetch_all_data':
            $res = $controller->fetchAllData();
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'update_application_status':
            $res = $controller->updateApplicationStatus($inputData);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'update_roadmap_step':
            $res = $controller->updateRoadmapStep($inputData);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'get_sections_for_program':
            $prog = $_GET['program'] ?? ($inputData['program'] ?? '');
            $year = isset($_GET['year_level']) ? $_GET['year_level'] : ($inputData['year_level'] ?? null);
            $sem  = isset($_GET['semester']) ? $_GET['semester'] : ($inputData['semester'] ?? null);
            $res = SectionService::getSectionsForProgram($pdo, $prog, $year, $sem);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200, ['allSections' => $res['allSections'] ?? []]);
            break;

        case 'save_program':
        case 'delete_program':
        case 'save_subject':
        case 'delete_subject':
        case 'save_curriculum':
        case 'delete_curriculum':
            include __DIR__ . '/../../admin/backend/catalog/catalog.php';
            break;

        case 'save_academic_period':
        case 'delete_academic_period':
        case 'clone_previous_term':
        case 'save_section':
        case 'delete_section':
        case 'save_fee':
        case 'delete_fee':
            include __DIR__ . '/../../admin/backend/term/term.php';
            break;

        case 'save_block_section':
        case 'save_subject_section':
        case 'delete_subject_section':
        case 'bulk_generate_sections':
            include __DIR__ . '/../../admin/backend/scheduling/scheduling.php';
            break;

        default:
            sendResponse(false, null, 'Unknown action specified.', 400);
            break;
    }

} catch (PDOException $e) {
    logAppError("Registrar API error: " . $e->getMessage(), ['action' => $action]);
    sendResponse(false, null, 'Database operation error occurred.', 500);
}
