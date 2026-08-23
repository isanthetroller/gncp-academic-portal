<?php
/**
 * GNCP Central REST API Engine Router v2.0
 * Unified Gateway with X-Request-ID Tracking & Modular Service Delegation
 */
require_once __DIR__ . '/../shared/backend/utils/logger.php';
require_once __DIR__ . '/../shared/backend/utils/rate_limit.php';

$reqId = getRequestId();
header('Content-Type: application/json; charset=utf-8');

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowedHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
if (!empty($origin) && (str_contains($origin, $allowedHost) || str_contains($origin, 'localhost') || str_contains($origin, '127.0.0.1'))) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Request-ID');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('X-Request-ID: ' . $reqId);

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/BaseStationService.php';
require_once __DIR__ . '/controllers/AuthController.php';
require_once __DIR__ . '/controllers/StudentController.php';
require_once __DIR__ . '/controllers/StationController.php';
require_once __DIR__ . '/controllers/AdminController.php';
require_once __DIR__ . '/controllers/StudentPortalController.php';

require_once __DIR__ . '/../shared/backend/services/CatalogService.php';
require_once __DIR__ . '/../shared/backend/services/SectionService.php';
require_once __DIR__ . '/../shared/backend/services/RegistrarService.php';

try {
    $pdo = Database::getInstance();
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_GET['route'] ?? '';
    
    $rawInput = file_get_contents('php://input');
    $payload = !empty($rawInput) ? json_decode($rawInput, true) : [];

    $response = ['success' => false, 'message' => 'Route not found.', 'code' => 404];

    $routes = [
        'auth/login'              => fn($p) => (new AuthController($pdo))->login($p),
        'auth/logout'             => fn($p) => (new AuthController($pdo))->logout(),
        'auth/check'              => fn($p) => (new AuthController($pdo))->checkSession(),
        'auth/change_password'    => fn($p) => (new AuthController($pdo))->changePassword($p),
        'auth/profile'            => fn($p) => (new AuthController($pdo))->getProfile(),
        'auth/update_profile'     => fn($p) => (new AuthController($pdo))->updateProfile($p),
        'auth/upload_avatar'      => fn($p) => (new AuthController($pdo))->uploadAvatar($p),
        'stations/upload_photo'   => fn($p) => (new AuthController($pdo))->uploadAvatar($p),

        // Canonical Student Portal Routes
        'student_portal/login'               => fn($p) => (new StudentPortalController($pdo))->login($p),
        'student_portal/dashboard'           => fn($p) => (new StudentPortalController($pdo))->getDashboard($_GET),
        'student_portal/update_profile'      => fn($p) => (new StudentPortalController($pdo))->updateProfile($p),
        'student_portal/change_password'     => fn($p) => (new StudentPortalController($pdo))->changePassword($p),
        'student_portal/request_password_reset' => fn($p) => (new StudentPortalController($pdo))->requestPasswordReset($p),
        'student_portal/reset_password_with_code' => fn($p) => (new StudentPortalController($pdo))->resetPasswordWithCode($p),
        'student_portal/documents'           => fn($p) => (new StudentPortalController($pdo))->getDocuments($_GET),
        'student_portal/upload_document'     => fn($p) => (new StudentPortalController($pdo))->uploadDocument($p),
        'student_portal/logout'              => fn($p) => (new StudentPortalController($pdo))->logout(),

        'student/register'        => function($p) use ($pdo) {
            // Rate limit: max 5 registration attempts per IP per 60 seconds
            checkRateLimit('student_register', 5, 60);
            pruneRateLimitFiles(); // Opportunistic cleanup
            return (new StudentController($pdo))->register($p);
        },
        'student/track'           => function($p) use ($pdo) {
            // Rate limit: max 20 lookups per IP per 60 seconds
            checkRateLimit('student_track', 20, 60);
            return (new StudentController($pdo))->track($_GET['ref'] ?? $_GET['referenceNumber'] ?? ($p['referenceNumber'] ?? ''));
        },
        'student/documents'       => fn($p) => (new StudentController($pdo))->getDocuments($_GET['identifier'] ?? ($_GET['ref'] ?? ($_GET['studentId'] ?? ($p['identifier'] ?? ($p['studentId'] ?? ''))))),
        'student/upload_document' => fn($p) => (new StudentController($pdo))->uploadDocument($p),
        'registrar/verify_document'=> fn($p) => (new StudentController($pdo))->verifyDocument($p),
        'student/cleanup_test_records'=> fn($p) => (new StudentController($pdo))->cleanupTestRecords($p),

        'stations/update'         => fn($p) => (new StationController($pdo))->updateStudent($p),
        'update_student'          => fn($p) => (new StationController($pdo))->updateStudent($p),
        'stations/next_student_id'=> function() use ($pdo) {
            require_once __DIR__ . '/../shared/backend/utils/student.php';
            return ['success' => true, 'data' => ['nextStudentId' => generateUniqueStudentId($pdo, '2026')]];
        },
        'get_next_student_id'     => function() use ($pdo) {
            require_once __DIR__ . '/../shared/backend/utils/student.php';
            return ['success' => true, 'data' => ['nextStudentId' => generateUniqueStudentId($pdo, '2026')]];
        },
        'upload_photo'            => fn($p) => (new AuthController($pdo))->uploadAvatar($p),
        'stations/stats'          => function() use ($pdo) {
            require_once __DIR__ . '/../stations/backend/services/QueueService.php';
            return ['success' => true, 'data' => QueueService::getEnrollmentStats($pdo)];
        },
        'get_enrollment_stats'    => function() use ($pdo) {
            require_once __DIR__ . '/../stations/backend/services/QueueService.php';
            return ['success' => true, 'data' => QueueService::getEnrollmentStats($pdo)];
        },
        'stations/student_accounts'=> function() use ($pdo) {
            require_once __DIR__ . '/../stations/backend/services/QueueService.php';
            return ['success' => true, 'data' => QueueService::fetchStudentAccounts($pdo)];
        },
        'fetch_student_accounts'  => function() use ($pdo) {
            require_once __DIR__ . '/../stations/backend/services/QueueService.php';
            return ['success' => true, 'data' => QueueService::fetchStudentAccounts($pdo)];
        },

        'registrar/update_status' => function($p) use ($pdo) {
            require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
            requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);
            return RegistrarService::updateApplicationStatus($pdo, $p);
        },
        'registrar/update_step'   => function($p) use ($pdo) {
            require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
            requireAuth(['REGISTRAR', 'ADMIN', 'SUPER_ADMIN']);
            return RegistrarService::updateRoadmapStep($pdo, $p);
        },
        'registrar/sections'      => fn($p) => SectionService::getSectionsForProgram($pdo, $_GET['program'] ?? ($p['program'] ?? ''), $_GET['year_level'] ?? ($p['year_level'] ?? '1st Year'), $_GET['semester'] ?? ($p['semester'] ?? '1st Semester')),

        'admin/catalog'           => fn($p) => (new AdminController($pdo))->getCatalog(),
        'admin/sections'          => fn($p) => (new AdminController($pdo))->getSections(),
        'admin/terms'             => fn($p) => (new AdminController($pdo))->getTerms(),
        'admin/users'             => fn($p) => (new AdminController($pdo))->getUsers(),
        'admin/save_program'      => fn($p) => (new AdminController($pdo))->saveProgram($p),
        'admin/save_subject'      => fn($p) => (new AdminController($pdo))->saveSubject($p),
        'admin/save_section'      => fn($p) => (new AdminController($pdo))->saveSection($p),
        'admin/save_term'         => fn($p) => (new AdminController($pdo))->saveTerm($p),
        'admin/save_user'         => fn($p) => (new AdminController($pdo))->saveUser($p),
        'admin/cleanup_test_users'=> fn($p) => (new AdminController($pdo))->cleanupTestUsers($p),

        'announcements/list'              => fn($p) => (new AdminController($pdo))->getAnnouncements($_GET),
        'admin/save_announcement'         => fn($p) => (new AdminController($pdo))->saveAnnouncement($p),
        'admin/delete_announcement'       => fn($p) => (new AdminController($pdo))->deleteAnnouncement($p),
        'admin/upload_announcement_image' => fn($p) => (new AdminController($pdo))->uploadAnnouncementImage(),

        'milestones/list'                 => fn($p) => (new AdminController($pdo))->getMilestones($_GET),
        'admin/save_milestone'            => fn($p) => (new AdminController($pdo))->saveMilestone($p),
        'admin/delete_milestone'          => fn($p) => (new AdminController($pdo))->deleteMilestone($p),

        // PayMongo Payment Gateway Integration Endpoints
        'payments/paymongo_create_checkout' => function($p) {
            require_once __DIR__ . '/../shared/backend/services/PayMongoService.php';
            $refNo  = trim($p['referenceNumber'] ?? ($p['ref'] ?? ''));
            $amount = (float)($p['amount'] ?? 0);
            $desc   = trim($p['description'] ?? 'GNCP Academic Tuition Assessment');
            if (empty($refNo) || $amount <= 0) {
                return ['success' => false, 'message' => 'Valid student reference number and payment amount are required.', 'code' => 400];
            }
            try {
                $session = PayMongoService::createCheckoutSession($refNo, $amount, $desc, $p['studentData'] ?? []);
                return ['success' => true, 'data' => $session];
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage(), 'code' => 400];
            }
        },
        'payments/paymongo_simulate_paid' => function($p) {
            require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
            $user = requireAuth(['CASHIER', 'ADMIN', 'SUPER_ADMIN']);
            require_once __DIR__ . '/../shared/backend/services/PayMongoService.php';
            $refNo   = trim($p['referenceNumber'] ?? '');
            $amount  = (float)($p['amount'] ?? 0);
            $channel = trim($p['channel'] ?? 'GCash');
            $txnRef  = trim($p['transactionRef'] ?? '');
            $cashier = trim($p['cashier'] ?? ($user['name'] ?? 'Cashier Officer'));
            $notes   = trim($p['notes'] ?? 'Online payment via PayMongo simulation');

            if (empty($refNo) || $amount <= 0) {
                return ['success' => false, 'message' => 'Valid reference number and amount are required to simulate payment.', 'code' => 400];
            }
            try {
                $result = PayMongoService::processPaymentSuccess($refNo, $amount, $channel, $txnRef, $cashier, $notes);
                return ['success' => true, 'data' => $result];
            } catch (Exception $e) {
                return ['success' => false, 'message' => $e->getMessage(), 'code' => 400];
            }
        },
        'payments/paymongo_webhook' => function($p) {
            require_once __DIR__ . '/../shared/backend/services/PayMongoService.php';
            // Live webhook signature handler
            $rawPayload = file_get_contents('php://input');
            $event = json_decode($rawPayload, true);
            $eventType = $event['data']['attributes']['type'] ?? '';

            if ($eventType === 'checkout_session.payment.paid') {
                $sessionData = $event['data']['attributes']['data']['attributes'] ?? [];
                $refNo = $sessionData['line_items'][0]['description'] ?? '';
                $amountInCentavos = (int)($sessionData['payments'][0]['attributes']['amount'] ?? 0);
                $amount = $amountInCentavos / 100.0;
                $channel = $sessionData['payments'][0]['attributes']['source']['type'] ?? 'GCash';
                $txnRef = $sessionData['payments'][0]['id'] ?? ('PM-' . time());

                if (!empty($refNo) && $amount > 0) {
                    PayMongoService::processPaymentSuccess($refNo, $amount, $channel, $txnRef, 'PayMongo Webhook');
                }
            }

            return ['success' => true, 'message' => 'Webhook received'];
        }
    ];

    if ($action === 'stations/queue' || $action === 'fetch_queue') {
        require_once __DIR__ . '/../stations/backend/services/QueueService.php';
        $etag = QueueService::getQueueHash($pdo);
        header('ETag: ' . $etag);
        header('Cache-Control: no-cache, must-revalidate');

        $ifNoneMatch = $_SERVER['HTTP_IF_NONE_MATCH'] ?? '';
        if ($ifNoneMatch && (trim($ifNoneMatch) === trim($etag) || trim($ifNoneMatch, '"') === trim($etag, '"'))) {
            http_response_code(304);
            exit;
        }

        $ctrl = new StationController($pdo);
        $response = $ctrl->getQueue();
    } elseif (isset($routes[$action])) {
        $response = $routes[$action]($payload);
    }


    $httpCode = $response['code'] ?? ($response['success'] ? 200 : 400);
    http_response_code($httpCode);
    echo json_encode(array_merge($response, ['requestId' => $reqId]), JSON_PRETTY_PRINT);

} catch (Exception $e) {
    logAppError("Central API Error: " . $e->getMessage(), ['action' => $action, 'trace' => $e->getTraceAsString()]);
    http_response_code(500);
    echo json_encode([
        'success'   => false,
        'message'   => 'An unexpected issue occurred while processing your request. Please review your information or try again in a few moments.',
        'requestId' => $reqId,
        'timestamp' => date('c')
    ]);
}
