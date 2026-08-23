<?php
/**
 * GNCP Registrar Portal — Central API Controller Wrapper
 * Clean delegation router to RegistrarService, CatalogService, SectionService, and QueueService.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';
require_once __DIR__ . '/../../shared/backend/utils/session_guard.php';
require_once __DIR__ . '/../../shared/backend/utils/student.php';
require_once __DIR__ . '/../../shared/backend/utils/logger.php';

require_once __DIR__ . '/../../shared/backend/services/CatalogService.php';
require_once __DIR__ . '/../../shared/backend/services/SectionService.php';
require_once __DIR__ . '/../../shared/backend/services/RegistrarService.php';
require_once __DIR__ . '/../../stations/backend/services/QueueService.php';

// Enforce session authentication for registrar API endpoints
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

    switch ($action) {
        
        case 'fetch_all_data':
            try {
                $pdo->query("UPDATE `pre_enrollments` SET `status` = 'EXPIRED' WHERE `status` = 'PRE_REGISTERED' AND `created_at` < NOW() - INTERVAL 90 DAY");
            } catch (Exception $ex) {}

            $catalogData = CatalogService::fetchCatalogData($pdo);
            $sectionData = SectionService::fetchSections($pdo);

            $studentsRaw = $pdo->query("SELECT * FROM `students` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);
            $students = array_map(function($s) {
                return [
                    'id'                => $s['id'],
                    'name'              => $s['name'],
                    'program'           => $s['program'],
                    'email'             => $s['email'],
                    'photo'             => $s['photo'],
                    'year_level'        => $s['year_level'],
                    'status'            => $s['status'],
                    'temp_reference_no' => $s['temp_reference_no'],
                    'personalInfo'      => json_decode((string)($s['personal_info'] ?? ''), true) ?: null,
                    'academicInfo'      => json_decode((string)($s['academic_info'] ?? ''), true) ?: null,
                    'roadmap'           => json_decode((string)($s['roadmap'] ?? ''), true) ?: [],
                    'requirementsData'  => json_decode((string)($s['requirements_data'] ?? ''), true) ?: null,
                    'medicalData'       => json_decode((string)($s['medical_data'] ?? ''), true) ?: null,
                    'scholarshipData'   => json_decode((string)($s['scholarship_data'] ?? ''), true) ?: null,
                    'paymentData'       => json_decode((string)($s['payment_data'] ?? ''), true) ?: null,
                    'helpdeskData'      => json_decode((string)($s['helpdesk_data'] ?? ''), true) ?: null
                ];
            }, $studentsRaw);

            $enrollments = $pdo->query("SELECT * FROM `enrollments` ORDER BY `id` DESC")->fetchAll(PDO::FETCH_ASSOC);

            $preEnrollments = $pdo->query("SELECT * FROM `pre_enrollments` ORDER BY `created_at` ASC")->fetchAll(PDO::FETCH_ASSOC);
            $pendingApplications = array_map(function($row) {
                $fullName = trim($row['first_name'] . ' ' . ($row['middle_name'] ? $row['middle_name'] . ' ' : '') . $row['last_name']);
                $requirements = getRequirementsForType($row['student_type'] ?? 'FRESHMAN', $row['shs_track'] ?? '');
                $requirementsData = json_decode((string)($row['requirements_data'] ?? ''), true) ?: [
                    'status' => 'PENDING',
                    'docs' => ['psa' => 'not-submitted', 'reportCard' => 'not-submitted', 'goodMoral' => 'not-submitted'],
                    'notes' => '', 'verifiedBy' => '', 'dateVerified' => ''
                ];
                $rowId = (int)($row['id'] ?? 0);
                $padId = str_pad((string)($rowId > 0 ? $rowId : rand(1, 999)), 3, '0', STR_PAD_LEFT);

                return [
                    'id'              => $rowId,
                    'queueTicket'     => 'REG-' . $padId,
                    'referenceNumber' => $row['temp_student_id'],
                    'tempPin'         => $row['temp_pin'],
                    'applicantName'   => $fullName ?: 'New Applicant',
                    'program'         => $row['course_code'],
                    'yearLevel'       => $row['year_level_applied'] ?? '1st Year',
                    'studentType'     => $row['student_type'] ?? 'FRESHMAN',
                    'shsTrack'        => $row['shs_track'] ?? '',
                    'academicInfo'    => [
                        'elementary'  => $row['elementary_school'] ?? '',
                        'juniorHigh'  => $row['junior_high_school'] ?? '',
                        'seniorHigh'  => $row['senior_high_school'] ?? '',
                        'shsTrack'    => $row['shs_track'] ?? '',
                        'honors'      => $row['honors'] ?? ''
                    ],
                    'previousCollege' => $row['previous_college'] ?? null,
                    'nstp'            => $row['nstp'] ?? 'N/A',
                    'dateSubmitted'   => date('Y-m-d', strtotime($row['created_at'])),
                    'createdAt'       => $row['created_at'],
                    'status'          => $row['status'],
                    'reviewedToday'   => in_array($row['status'], ['Approved', 'Rejected', 'VERIFIED']),
                    'sectionCode'     => $row['section_code'] ?? null,
                    'personalInfo'    => ['birthDate' => $row['birth_date'], 'gender' => $row['gender'], 'address' => $row['address']],
                    'contactInfo'     => ['email' => $row['email'], 'phone' => $row['phone'], 'guardian' => $row['emergency_contact_name']],
                    'requirements'    => $requirements,
                    'requirementsData'=> $requirementsData,
                    'roadmap'         => json_decode((string)($row['roadmap'] ?? ''), true) ?: [],
                    'registrarNotes'  => $row['registrar_notes'] ?? ($row['roadmap'] ? 'Tracking steps established' : 'Awaiting review.')
                ];
            }, $preEnrollments);

            sendResponse(true, array_merge([
                'courses'             => [],
                'students'            => $students,
                'sections'            => $sectionData['sections'],
                'enrollments'         => $enrollments,
                'pendingApplications' => $pendingApplications,
                'subjectSections'     => $sectionData['subjectSections']
            ], $catalogData));
            break;

        case 'update_application_status':
            $res = RegistrarService::updateApplicationStatus($pdo, $inputData);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'update_roadmap_step':
            $res = RegistrarService::updateRoadmapStep($pdo, $inputData);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'get_sections_for_program':
            $prog = $_GET['program'] ?? ($inputData['program'] ?? '');
            $year = $_GET['year_level'] ?? ($inputData['year_level'] ?? '1st Year');
            $sem  = $_GET['semester']   ?? ($inputData['semester']   ?? '1st Semester');
            $res = SectionService::getSectionsForProgram($pdo, $prog, $year, $sem);
            sendResponse($res['success'], $res['data'] ?? null, $res['message'] ?? null, $res['code'] ?? 200);
            break;

        case 'save_program':
        case 'delete_program':
        case 'save_subject':
        case 'delete_subject':
        case 'save_curriculum':
        case 'delete_curriculum':
        case 'save_academic_period':
        case 'delete_academic_period':
        case 'clone_previous_term':
        case 'save_section':
        case 'delete_section':
        case 'save_subject_section':
        case 'delete_subject_section':
        case 'bulk_generate_sections':
        case 'save_fee':
        case 'delete_fee':
            $submodules = [
                __DIR__ . '/../../admin/backend/catalog/catalog.php',
                __DIR__ . '/../../admin/backend/term/term.php',
                __DIR__ . '/../../admin/backend/scheduling/scheduling.php'
            ];
            foreach ($submodules as $sub) {
                if (file_exists($sub)) {
                    include $sub;
                }
            }
            break;

        default:
            sendResponse(false, null, 'Unknown action specified.', 400);
            break;
    }

} catch (PDOException $e) {
    logAppError("Registrar API error: " . $e->getMessage(), ['action' => $action]);
    sendResponse(false, null, 'Database operation error occurred.', 500);
}
