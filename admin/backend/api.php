<?php
/**
 * GNCP Portal — Super Admin API Gateway
 *
 * Handles:
 *   - Super Admin authentication (role: SUPER_ADMIN)
 *   - Station operator account management (CRUD)
 *   - All academic data management (Programs, Subjects, Curriculum,
 *     Academic Periods, Subject Sections, Fee Schedule)
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';

// Enforce session authentication for admin API endpoints
requireAuth(['ADMIN', 'SUPER_ADMIN']);

$action = $_GET['action'] ?? '';

try {
    $pdo = Database::getInstance();

    if ($action === 'fetch_users') {
        $stmt  = $pdo->query("SELECT `id`, `username`, `role`, `name`, `email`, UPPER(COALESCE(NULLIF(`status`, ''), 'ACTIVE')) AS `status`, `must_change_password`, `created_at` FROM `station_users` WHERE `role` NOT IN ('ADMIN','SUPER_ADMIN') ORDER BY `id` DESC");
        $users = $stmt->fetchAll();
        sendResponse(true, $users);

    } elseif ($action === 'update_user_status') {
        $payload  = json_decode(file_get_contents('php://input'), true);
        $userId   = $payload['userId'] ?? null;
        $status   = $payload['status'] ?? null;
        if (!$userId || !$status) sendResponse(false, null, 'User ID and status are required.', 400);

        // Safeguard Super Admin status
        $check = $pdo->prepare("SELECT `role` FROM `station_users` WHERE `id` = :id");
        $check->execute(['id' => (int)$userId]);
        $targetRole = strtoupper((string)$check->fetchColumn());
        if ($targetRole === 'SUPER_ADMIN') {
            sendResponse(false, null, 'Cannot modify status of Super Admin account.', 403);
        }

        $stmt = $pdo->prepare("UPDATE `station_users` SET `status` = :status WHERE `id` = :id");
        $stmt->execute(['status' => strtoupper(trim($status)), 'id' => (int)$userId]);
        sendResponse(true, null, 'Status updated successfully.');

    } elseif ($action === 'delete_user') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $userId  = $payload['userId'] ?? null;
        if (!$userId) sendResponse(false, null, 'User ID is required.', 400);

        // Safeguard Admin accounts
        $check = $pdo->prepare("SELECT `role` FROM `station_users` WHERE `id` = :id");
        $check->execute(['id' => (int)$userId]);
        $targetRole = strtoupper((string)$check->fetchColumn());
        if (in_array($targetRole, ['ADMIN', 'SUPER_ADMIN'])) {
            sendResponse(false, null, 'Cannot delete an Admin or Super Admin account.', 403);
        }

        $stmt = $pdo->prepare("DELETE FROM `station_users` WHERE `id` = :id");
        $stmt->execute(['id' => (int)$userId]);
        sendResponse(true, null, 'User deleted successfully.');

    } elseif ($action === 'reset_operator_password') {
        $payload       = json_decode(file_get_contents('php://input'), true);
        $userId        = $payload['userId'] ?? null;
        $newPassword   = trim($payload['newPassword'] ?? '');
        $overrideEmail = trim($payload['email'] ?? '');
        if (!$userId) sendResponse(false, null, 'User ID is required.', 400);

        $check = $pdo->prepare("SELECT `username`, `name`, `email`, `role` FROM `station_users` WHERE `id` = :id");
        $check->execute(['id' => (int)$userId]);
        $targetUser = $check->fetch();
        if (!$targetUser) sendResponse(false, null, 'User account not found.', 404);

        if (!empty($overrideEmail) && !filter_var($overrideEmail, FILTER_VALIDATE_EMAIL)) {
            sendResponse(false, null, 'Invalid email address format provided.', 400);
        }

        if (empty($newPassword)) {
            $newPassword = 'Gncp#' . rand(1000, 9999) . '!';
        }

        $destinationEmail = !empty($overrideEmail) ? $overrideEmail : ($targetUser['email'] ?? '');
        
        if (!empty($overrideEmail)) {
            $stmt = $pdo->prepare("UPDATE `station_users` SET `password` = :pass, `must_change_password` = 1, `email` = :email WHERE `id` = :id");
            $stmt->execute([
                'pass'  => password_hash($newPassword, PASSWORD_DEFAULT),
                'email' => $overrideEmail,
                'id'    => (int)$userId
            ]);
        } else {
            $stmt = $pdo->prepare("UPDATE `station_users` SET `password` = :pass, `must_change_password` = 1 WHERE `id` = :id");
            $stmt->execute([
                'pass' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id'   => (int)$userId
            ]);
        }

        // Dispatch updated credentials via EmailService if email exists
        require_once __DIR__ . '/../../shared/backend/services/EmailService.php';
        $mailResult = ['success' => false, 'message' => 'No email address registered for this operator.'];
        if (!empty($destinationEmail)) {
            $mailResult = EmailService::sendOperatorPasswordReset(
                $destinationEmail,
                $targetUser['name'],
                $targetUser['username'],
                $newPassword,
                $targetUser['role']
            );
        }

        sendResponse(true, [
            'userId'         => (int)$userId,
            'username'       => $targetUser['username'],
            'tempPassword'   => $newPassword,
            'recipientEmail' => $destinationEmail,
            'emailSent'      => $mailResult['success'],
            'emailMessage'   => $mailResult['message'] ?? ''
        ], 'Operator password reset successfully.');


    } elseif ($action === 'update_operator') {
        $payload = json_decode(file_get_contents('php://input'), true);
        $userId  = $payload['userId'] ?? null;
        $name    = trim($payload['name']  ?? '');
        $email   = trim($payload['email'] ?? '');
        $role    = trim($payload['role']  ?? '');

        if (!$userId || !$name || !$role) {
            sendResponse(false, null, 'User ID, name, and role are required.', 400);
        }

        $check = $pdo->prepare("SELECT `role` FROM `station_users` WHERE `id` = :id");
        $check->execute(['id' => (int)$userId]);
        $targetRole = strtoupper((string)$check->fetchColumn());
        if ($targetRole === 'SUPER_ADMIN' && strtoupper($role) !== 'SUPER_ADMIN') {
            sendResponse(false, null, 'Cannot modify Super Admin role.', 403);
        }
        if (in_array(strtoupper($role), ['ADMIN', 'SUPER_ADMIN']) && !in_array($targetRole, ['ADMIN', 'SUPER_ADMIN'])) {
            sendResponse(false, null, 'Cannot elevate account to Admin via operator editor.', 403);
        }

        $stmt = $pdo->prepare("UPDATE `station_users` SET `name` = :name, `email` = :email, `role` = :role WHERE `id` = :id");
        $stmt->execute([
            'name'  => $name,
            'email' => $email ?: null,
            'role'  => $role,
            'id'    => (int)$userId
        ]);

        sendResponse(true, null, 'Operator account updated successfully.');

    } elseif ($action === 'create_user' || $action === 'save_user') {
        $rawInput = json_decode(file_get_contents('php://input'), true);
        $payload  = $rawInput['user'] ?? $rawInput;

        $username = trim($payload['username'] ?? '');
        $password = trim($payload['password'] ?? '');
        $name     = trim($payload['name']     ?? '');
        $email    = trim($payload['email']    ?? '');
        $role     = trim($payload['role']     ?? '');

        if (!$username || !$name || !$role) {
            sendResponse(false, null, 'Name, username, and role are required.', 400);
        }

        // Auto-generate temp password if blank
        if (empty($password)) {
            $password = 'Gncp#' . rand(1000, 9999) . '!';
        }

        // Block creating another super admin through this form
        if (in_array(strtoupper($role), ['ADMIN', 'SUPER_ADMIN'])) {
            sendResponse(false, null, 'Cannot create an admin account through this form.', 403);
        }

        $check = $pdo->prepare("SELECT COUNT(*) FROM `station_users` WHERE `username` = :user");
        $check->execute(['user' => $username]);
        if ($check->fetchColumn() > 0) {
            sendResponse(false, null, 'Username is already taken.', 400);
        }

        $stmt = $pdo->prepare("INSERT INTO `station_users` (`username`, `password`, `role`, `name`, `email`, `status`, `must_change_password`) VALUES (:user, :pass, :role, :name, :email, 'ACTIVE', 1)");
        $stmt->execute([
            'user'  => $username,
            'pass'  => password_hash($password, PASSWORD_DEFAULT),
            'role'  => $role,
            'name'  => $name,
            'email' => $email ?: null
        ]);

        // Dispatch credentials via EmailService
        require_once __DIR__ . '/../../shared/backend/services/EmailService.php';
        $mailResult = ['success' => false, 'message' => 'No email specified.'];
        if (!empty($email)) {
            $mailResult = EmailService::sendUserCredentials($email, $name, $username, $password, $role);
        }

        sendResponse(true, [
            'username'             => $username,
            'email'                => $email,
            'tempPassword'         => $password,
            'emailSent'            => $mailResult['success'],
            'emailMessage'         => $mailResult['message'] ?? '',
            'must_change_password' => true
        ], 'Operator account created and credentials dispatched.');

    // ──────────────────────────────────────────────────────────────────
    // ACADEMIC DATA — READ ALL
    // ──────────────────────────────────────────────────────────────────
    } elseif ($action === 'fetch_academic_data') {
        // Departments
        $departments = $pdo->query("SELECT * FROM `departments` ORDER BY `id` DESC")->fetchAll();

        // Programs
        $programs = $pdo->query("SELECT * FROM `programs` ORDER BY `id` DESC")->fetchAll();

        // Subjects
        $subsRaw  = $pdo->query("SELECT * FROM `subjects` ORDER BY `id` DESC")->fetchAll();
        $subjects = [];
        foreach ($subsRaw as $r) {
            $subjects[] = [
                'id'           => (int)$r['id'],
                'code'         => $r['code'],
                'title'        => $r['title'],
                'description'  => $r['description'],
                'lectureUnits' => (int)$r['lecture_units'],
                'labUnits'     => (int)$r['lab_units'],
                'labFee'       => (float)$r['lab_fee'],
                'department'   => $r['department'],
                'prerequisites'=> $r['prerequisites']
            ];
        }

        // Curriculum
        $currRaw  = $pdo->query(
            "SELECT c.*, s.code as subject_code, s.lecture_units, s.lab_units, s.lab_fee, s.prerequisites
             FROM `curriculum` c LEFT JOIN `subjects` s ON (c.subject = s.title OR c.subject = s.code) ORDER BY c.id DESC"
        )->fetchAll();
        $curriculum = [];
        foreach ($currRaw as $r) {
            $curriculum[] = [
                'id'           => (int)$r['id'],
                'program'      => $r['program'],
                'subject'      => $r['subject'],
                'subjectCode'  => $r['subject_code'] ?? '',
                'lectureUnits' => (int)($r['lecture_units'] ?? 0),
                'labUnits'     => (int)($r['lab_units'] ?? 0),
                'labFee'       => (float)($r['lab_fee'] ?? 0),
                'prerequisites'=> $r['prerequisites'] ?? 'None',
                'yearLevel'    => $r['year_level'],
                'semester'     => $r['semester'],
                'elective'     => (bool)$r['elective'],
                'curriculumVersion' => $r['curriculum_version'] ?? '2022 Curriculum'
            ];
        }

        // Academic Periods
        $apRaw  = $pdo->query("SELECT * FROM `academic_periods` ORDER BY `id` DESC")->fetchAll();
        $periods = [];
        foreach ($apRaw as $r) {
            $periods[] = [
                'id'              => (int)$r['id'],
                'name'            => $r['name'],
                'academicYear'    => $r['academic_year'],
                'semester'        => $r['semester'],
                'enrollmentStart' => $r['enrollment_start'],
                'enrollmentEnd'   => $r['enrollment_end'],
                'status'          => $r['status']
            ];
        }

        // Section Cohort Blocks
        $secCohortRaw = $pdo->query("SELECT * FROM `sections` ORDER BY `id` DESC")->fetchAll();
        $sections = [];
        foreach ($secCohortRaw as $r) {
            $sections[] = [
                'id'               => (int)$r['id'],
                'code'             => $r['code'],
                'program'          => $r['program'],
                'yearLevel'        => $r['year_level'],
                'academicPeriodId' => (int)$r['academic_period_id'],
                'curriculumVersion'=> $r['curriculum_version'] ?? '2022 Curriculum',
                'capacity'         => (int)$r['capacity'],
                'adviser'          => $r['adviser'] ?? ''
            ];
        }

        // Class Offerings (under subject_sections)
        $secRaw   = $pdo->query("SELECT * FROM `subject_sections` ORDER BY `id` DESC")->fetchAll();
        $classOfferings = [];
        foreach ($secRaw as $r) {
            $classOfferings[] = [
                'id'         => (int)$r['id'],
                'program'    => $r['program'] ?? '',
                'yearLevel'  => $r['year_level'] ?? '',
                'semester'   => $r['semester'] ?? '',
                'subject'    => $r['subject'],
                'code'       => $r['code'],
                'instructor' => $r['instructor'],
                'days'       => $r['days'],
                'time'       => $r['time'],
                'room'       => $r['room'],
                'capacity'   => (int)$r['capacity'],
                'sectionId'  => $r['section_id'] !== null ? (int)$r['section_id'] : null
            ];
        }

        // Fee Schedule
        $feeRaw = $pdo->query("SELECT * FROM `fee_schedule` ORDER BY `id` DESC")->fetchAll();
        $fees   = [];
        foreach ($feeRaw as $r) {
            $fees[] = [
                'id'      => (int)$r['id'],
                'type'    => $r['type'],
                'label'   => $r['label'],
                'amount'  => (float)$r['amount'],
                'perUnit' => (bool)$r['per_unit']
            ];
        }

        // Enrolled Students list (with institutional email for Student Accounts view)
        // Normalize status: ENROLLED/ACTIVE/Active → 'Active'; anything else → 'Inactive'
        $studentsRaw = $pdo->query("SELECT `id`, `name`, `program`, `year_level`, `email`, `status`, `created_at` FROM `students` ORDER BY `name` ASC")->fetchAll();
        $students = [];
        foreach ($studentsRaw as $r) {
            $rawStatus     = strtoupper(trim($r['status'] ?? ''));
            $normalStatus  = in_array($rawStatus, ['ACTIVE', 'ENROLLED']) ? 'Active' : 'Inactive';
            $students[] = [
                'id'        => $r['id'],
                'name'      => $r['name'],
                'program'   => $r['program'],
                'yearLevel' => $r['year_level'],
                'email'     => $r['email'] ?? '',
                'status'    => $normalStatus,
                'createdAt' => $r['created_at']
            ];
        }

        // Academic Milestones
        require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';
        $milestonesRes = MilestoneService::getMilestones($pdo);
        $milestones = $milestonesRes['success'] ? $milestonesRes['data'] : [];

        sendResponse(true, compact('departments', 'programs', 'subjects', 'curriculum', 'periods', 'sections', 'classOfferings', 'fees', 'students', 'milestones'));

    } elseif ($action === 'fetch_dashboard_stats') {
        require_once __DIR__ . '/../../shared/backend/services/AnalyticsService.php';
        $stats = AnalyticsService::getAnalytics($pdo, $_GET);
        sendResponse(true, $stats);

    // ──────────────────────────────────────────────────────────────────
    // ACADEMIC CRUD (MIGRATED TO MODULAR SUBFILES: catalog.php, term.php, scheduling.php)
    // ──────────────────────────────────────────────────────────────────



    } elseif ($action === 'fetch_announcements') {
        require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
        $res = AnnouncementService::getAnnouncements($pdo, ['all' => true]);
        sendResponse($res['success'], $res['data'] ?? [], $res['message'] ?? '');

    } elseif ($action === 'save_announcement') {
        require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $announcement = $payload['announcement'] ?? $payload;
        $res = AnnouncementService::saveAnnouncement($pdo, $announcement);
        sendResponse($res['success'], $res['id'] ?? null, $res['message'] ?? '', $res['code'] ?? 200);

    } elseif ($action === 'delete_announcement') {
        require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $res = AnnouncementService::deleteAnnouncement($pdo, $payload);
        sendResponse($res['success'], null, $res['message'] ?? '', $res['code'] ?? 200);

    } elseif ($action === 'upload_announcement_image') {
        require_once __DIR__ . '/../../shared/backend/services/AnnouncementService.php';
        $res = AnnouncementService::uploadImage();
        sendResponse($res['success'], ['image_url' => $res['image_url'] ?? null, 'url' => $res['image_url'] ?? null], $res['message'] ?? '', $res['code'] ?? 200);

    } elseif ($action === 'fetch_milestones') {
        require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';
        $res = MilestoneService::getMilestones($pdo, $_GET);
        sendResponse($res['success'], $res['data'] ?? [], $res['message'] ?? '');

    } elseif ($action === 'save_milestone') {
        require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $milestone = $payload['milestone'] ?? $payload;
        $res = MilestoneService::saveMilestone($pdo, $milestone);
        sendResponse($res['success'], $res['id'] ?? null, $res['message'] ?? '', $res['code'] ?? 200);

    } elseif ($action === 'delete_milestone') {
        require_once __DIR__ . '/../../shared/backend/services/MilestoneService.php';
        $payload = json_decode(file_get_contents('php://input'), true) ?? [];
        $res = MilestoneService::deleteMilestone($pdo, $payload);
        sendResponse($res['success'], null, $res['message'] ?? '', $res['code'] ?? 200);

    } else {
        // Delegate to modular sub-files
        require_once __DIR__ . '/catalog/catalog.php';
        require_once __DIR__ . '/term/term.php';
        require_once __DIR__ . '/scheduling/scheduling.php';

        // If still not handled, return error
        sendResponse(false, null, 'Invalid action specified.', 400);
    }

} catch (Throwable $e) {
    require_once __DIR__ . '/../../shared/backend/utils/logger.php';
    logAppError('Admin API Error: ' . $e->getMessage(), [
        'action' => $_GET['action'] ?? 'unknown',
        'trace'  => $e->getTraceAsString()
    ]);
    sendResponse(false, null, 'An unexpected issue occurred while processing your administrative request.', 500);
}


