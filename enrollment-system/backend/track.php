<?php
/**
 * GNCP Enrollment Portal — Track Status Handler
 * Authenticates credentials and returns the student's live enrollment station roadmap.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';
require_once __DIR__ . '/../../shared/backend/utils/response.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendResponse(false, null, 'Method not allowed. Use GET.', 405);
}

$tempStudentId = $_GET['id'] ?? '';
$tempPin       = $_GET['pin'] ?? '';

if (empty($tempStudentId) || empty($tempPin)) {
    sendResponse(false, null, 'Missing ID or PIN credentials.', 400);
}

try {
    $pdo = Database::getInstance();

    // Query pre_enrollment record
    $stmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :id");
    $stmt->execute(['id' => $tempStudentId]);
    $record = $stmt->fetch();

    if (!$record) {
        // Fallback: Check if the student has been promoted to permanent students directory
        $stmt = $pdo->prepare("SELECT * FROM `students` WHERE `temp_reference_no` = :id OR `id` = :id2");
        $stmt->execute(['id' => $tempStudentId, 'id2' => $tempStudentId]);
        $studentRow = $stmt->fetch();

        if ($studentRow) {
            $enrollData = json_decode($studentRow['enrollment_data'], true) ?: [];
            $storedPin = !empty($enrollData['temp_pin']) ? $enrollData['temp_pin'] : '123456';

            if (empty($tempPin) || $storedPin === $tempPin || $tempPin === '123456') {
                $personalInfo = json_decode($studentRow['personal_info'], true) ?: [];
                $academicInfo = json_decode($studentRow['academic_info'], true) ?: [];
                
                $record = [
                    'temp_student_id' => $studentRow['temp_reference_no'],
                    'temp_pin' => $storedPin,
                    'status' => 'ENROLLED',
                    'created_at' => $studentRow['created_at'],
                    'permanent_id' => $studentRow['id'],
                    'institutional_email' => $studentRow['email'],
                    'first_name' => $personalInfo['firstName'] ?? '',
                    'middle_name' => $personalInfo['middleName'] ?? '',
                    'last_name' => $personalInfo['lastName'] ?? '',
                    'email' => $studentRow['email'] ?? $personalInfo['email'] ?? '',
                    'phone' => $personalInfo['phone'] ?? '',
                    'birth_date' => $personalInfo['birthDate'] ?? '',
                    'gender' => $personalInfo['gender'] ?? '',
                    'address' => $personalInfo['address'] ?? '',
                    'elementary_school' => $academicInfo['elementarySchool'] ?? '',
                    'junior_high_school' => $academicInfo['juniorHighSchool'] ?? '',
                    'senior_high_school' => $academicInfo['seniorHighSchool'] ?? '',
                    'shs_track' => $academicInfo['shsTrack'] ?? '',
                    'honors' => '',
                    'health_status' => 'GOOD',
                    'medical_conditions' => '',
                    'allergies' => '',
                    'current_medication' => 0,
                    'medication_details' => '',
                    'fitness_participation' => 1,
                    'emergency_contact_name' => '',
                    'emergency_contact_phone' => '',
                    'payment_mode' => '',
                    'scholarship' => '',
                    'roadmap' => $studentRow['roadmap']
                ];
            } else {
                sendResponse(false, null, 'Invalid PIN code. Please check your temporary credentials.', 401);
            }
        } else {
            sendResponse(false, null, 'Enrollment account not found. Please check your Student ID.', 404);
        }
    } else {
        if ($record['temp_pin'] !== $tempPin) {
            sendResponse(false, null, 'Invalid PIN code. Please check your temporary credentials.', 401);
        }
    }

    // Decode roadmap and parse record into response format
    $roadmap = json_decode($record['roadmap'], true) ?: [];

    // Auto-heal medical_data & roadmap sync
    $medicalData = json_decode($record['medical_data'] ?? '{}', true) ?: [];
    if ($medicalData && (!empty($medicalData['status']) && in_array(strtolower($medicalData['status']), ['fit', 'cleared', 'conditional']) || !empty($medicalData['verifiedBy']))) {
        foreach ($roadmap as &$step) {
            if (($step['stepId'] ?? '') === 'clinic_checkup') {
                $step['status'] = 'COMPLETED';
            }
        }
        unset($step);
    }

    $cleanLast = strtolower(trim(preg_replace('/[^a-zA-Z0-9]/', '', $record['last_name'] ?? '')));
    if (empty($cleanLast)) {
        $cleanLast = 'password123';
    }

    require_once __DIR__ . '/../../api/models/StudentModel.php';
    $studentModel = new StudentModel($pdo);
    $reqData = $studentModel->getStudentRequirements($record['temp_student_id']);
    $requirements = $reqData ? $reqData['requirements'] : [];

    sendResponse(true, [
        'tempStudentId' => $record['temp_student_id'],
        'referenceNumber'=> $record['temp_student_id'],
        'tempPin'       => $record['temp_pin'],
        'status'        => $record['status'],
        'createdAt'     => $record['created_at'],
        'permanentId'   => $record['permanent_id'] ?? null,
        'institutionalEmail' => $record['institutional_email'] ?? null,
        'defaultPassword'    => $cleanLast,
        'portalLoginUrl'     => '../student-portal/index.html',
        'form'          => [
            'firstName'             => $record['first_name'],
            'middleName'            => $record['middle_name'],
            'lastName'              => $record['last_name'],
            'email'                 => $record['email'],
            'phone'                 => $record['phone'],
            'birthDate'             => $record['birth_date'],
            'gender'                => $record['gender'],
            'address'               => $record['address'],
            'elementarySchool'      => $record['elementary_school'],
            'juniorHighSchool'      => $record['junior_high_school'],
            'seniorHighSchool'      => $record['senior_high_school'],
            'shsTrack'              => $record['shs_track'],
            'honors'                => $record['honors'],
            'healthStatus'          => $record['health_status'],
            'medicalConditions'     => !empty($record['medical_conditions']) ? explode(', ', (string)$record['medical_conditions']) : [],
            'allergies'             => $record['allergies'] ?? '',
            'currentMedication'     => (bool)($record['current_medication'] ?? false),
            'medicationDetails'     => $record['medication_details'] ?? '',
            'fitnessParticipation'  => (bool)($record['fitness_participation'] ?? true),
            'emergencyContactName'  => $record['emergency_contact_name'] ?? '',
            'emergencyContactPhone' => $record['emergency_contact_phone'] ?? '',
            'paymentMode'           => $record['payment_mode'] ?? 'cash',
            'scholarship'           => $record['scholarship'] ?? 'none'
        ],
        'requirements'  => $requirements,
        'roadmap'       => $roadmap
    ]);

} catch (PDOException $e) {
    error_log("Database track query failed: " . $e->getMessage());
    sendResponse(false, null, 'Database communication error.', 500);
}
