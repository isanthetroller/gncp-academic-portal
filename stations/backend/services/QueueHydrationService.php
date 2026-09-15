<?php
/**
 * GNCP Workstations — Queue Hydration & Aggregation Service
 * Merges staging pre-enrollments with permanent students directory and builds normalized workstation payloads.
 */

require_once __DIR__ . '/ProspectusScopingService.php';

class QueueHydrationService {
    /**
     * Merges permanent student records into staging pre-enrollment rows,
     * ensuring that returning/enrolled students and live updates are reflected across all stations.
     */
    public static function mergeStudentRecords(array $stagingRows, array $studRows): array {
        $studMap = [];
        $existingRefs = [];

        foreach ($studRows as $sr) {
            if (!empty($sr['temp_reference_no'])) {
                $studMap[$sr['temp_reference_no']] = $sr;
            }
            $studMap[$sr['id']] = $sr;
            if (!empty($sr['email'])) {
                $studMap[strtolower($sr['email'])] = $sr;
            }
        }

        // Merge updated student fields from permanent directory into staging queue rows
        foreach ($stagingRows as &$row) {
            $ref = $row['temp_student_id'];
            $email = strtolower($row['email'] ?? '');
            $sr = $studMap[$ref] ?? ($studMap[$email] ?? null);

            if ($sr) {
                $existingRefs[$ref] = true;
                $existingRefs[$sr['id']] = true;

                if (!empty($sr['medical_data']) && $sr['medical_data'] !== '{}') {
                    $row['medical_data'] = $sr['medical_data'];
                }
                if (!empty($sr['payment_data']) && $sr['payment_data'] !== '{}') {
                    $row['payment_data'] = $sr['payment_data'];
                }
                if (!empty($sr['requirements_data']) && $sr['requirements_data'] !== '{}') {
                    $row['requirements_data'] = $sr['requirements_data'];
                }
                if (!empty($sr['helpdesk_data']) && $sr['helpdesk_data'] !== '{}') {
                    $row['helpdesk_data'] = $sr['helpdesk_data'];
                }
                if (!empty($sr['roadmap']) && $sr['roadmap'] !== '[]') {
                    $row['roadmap'] = $sr['roadmap'];
                }
            }
        }
        unset($row);

        // Append any permanent enrolled students that do not exist in staging pre_enrollments
        foreach ($studRows as $sr) {
            $ref = !empty($sr['temp_reference_no']) ? $sr['temp_reference_no'] : $sr['id'];
            $email = strtolower($sr['email'] ?? '');
            if (!isset($existingRefs[$ref]) && !isset($existingRefs[$sr['id']]) && !isset($existingRefs[$email])) {
                $existingRefs[$ref] = true;
                $existingRefs[$sr['id']] = true;

                $personal = json_decode($sr['personal_info'] ?? '{}', true) ?: [];
                $nameParts = explode(' ', trim($sr['name'] ?? ''));
                $firstName = $personal['firstName'] ?? ($nameParts[0] ?? '');
                $lastName = $personal['lastName'] ?? (end($nameParts) ?: '');
                $middleName = $personal['middleName'] ?? '';
                $recoveredPin = $personal['temp_pin'] ?? $sr['temp_pin'] ?? '——';
                $enrollmentData = json_decode($sr['enrollment_data'] ?? '{}', true) ?: [];
                $helpdeskData = json_decode($sr['helpdesk_data'] ?? '{}', true) ?: [];
                $paymentData = json_decode($sr['payment_data'] ?? '{}', true) ?: [];
                $secCode = !empty($sr['section_code']) ? $sr['section_code'] : ($enrollmentData['assignedSection'] ?? ($helpdeskData['section'] ?? ''));
                $orNum = !empty($sr['or_number']) ? $sr['or_number'] : ($paymentData['orNumber'] ?? ($paymentData['or_number'] ?? null));
                $cashierName = !empty($sr['cashier_name']) ? $sr['cashier_name'] : ($paymentData['cashierName'] ?? ($paymentData['processedBy'] ?? null));

                $stagingRows[] = [
                    'id'                  => $sr['id'],
                    'temp_student_id'     => $ref,
                    'temp_pin'            => $recoveredPin,
                    'first_name'          => $firstName,
                    'middle_name'         => $middleName,
                    'last_name'           => $lastName,
                    'course_code'         => $sr['program'],
                    'student_type'        => 'REGULAR',
                    'phone'               => $personal['phone'] ?? '',
                    'email'               => $sr['email'],
                    'birth_date'          => $personal['birthDate'] ?? '',
                    'gender'              => $personal['gender'] ?? 'Not specified',
                    'address'             => $personal['address'] ?? '',
                    'shs_track'           => $personal['shsTrack'] ?? '',
                    'previous_college'    => $personal['previousCollege'] ?? '',
                    'health_status'       => 'GOOD',
                    'medical_conditions'  => '',
                    'allergies'           => 'None',
                    'current_medication'  => 0,
                    'medication_details'  => '',
                    'fitness_participation' => 1,
                    'emergency_contact_name'  => $personal['emergencyContactName'] ?? '',
                    'emergency_contact_phone' => $personal['emergencyContactPhone'] ?? '',
                    'payment_mode'        => $paymentData['paymentMode'] ?? ($sr['payment_mode'] ?? 'Cash'),
                    'scholarship'         => 'NONE',
                    'registrar_notes'     => '',
                    'roadmap'             => $sr['roadmap'] ?? '[]',
                    'requirements_data'   => $sr['requirements_data'] ?? '{}',
                    'medical_data'        => $sr['medical_data'] ?? '{}',
                    'scholarship_data'    => $sr['scholarship_data'] ?? '{}',
                    'payment_data'        => $sr['payment_data'] ?? '{}',
                    'helpdesk_data'       => $sr['helpdesk_data'] ?? '{}',
                    'enrollment_data'     => $sr['enrollment_data'] ?? '{}',
                    'section_code'        => $secCode,
                    'or_number'           => $orNum,
                    'enrolled_at'         => $sr['created_at'],
                    'cashier_name'        => $cashierName,
                    'nstp'                => $helpdeskData['nstp'] ?? ($personal['nstp'] ?? 'NONE'),
                    'curriculum_version'  => $sr['curriculum_version'] ?? '2022 Curriculum',
                    'created_at'          => $sr['created_at'],
                    'year_level_applied'  => $sr['year_level'],
                    'status'              => $sr['status']
                ];
            }
        }

        return $stagingRows;
    }

    /**
     * Hydrates an aggregated student row into the workstation queue contract format.
     */
    public static function hydrateQueueRow(
        array $row,
        array $progMap,
        array $curriculumCache,
        array $sectionsRaw,
        string $activeSem,
        string $activePeriodYear
    ): array {
        $nameParts = array_filter([$row['first_name'], $row['middle_name'], $row['last_name']]);
        $fullName = implode(' ', $nameParts);

        $medConditionsStr = $row['medical_conditions'] ?? '';
        $medConditionsArr = $medConditionsStr ? array_map('trim', explode(',', $medConditionsStr)) : [];

        $programName = $progMap[$row['course_code'] ?? ''] ?? ($row['course_code'] ?? '');
        $yearLevel = !empty($row['year_level_applied']) ? $row['year_level_applied'] : '1st Year';
        $curriculumVer = !empty($row['curriculum_version']) ? $row['curriculum_version'] : '2022 Curriculum';

        $cacheKey = ($row['course_code'] ?? '') . '|' . $yearLevel . '|' . $curriculumVer;
        $progSubjects = $curriculumCache[$cacheKey] ?? [];
        $subjectTitles = array_column($progSubjects, 'title');

        $matchingSections = ProspectusScopingService::matchSections($sectionsRaw, $subjectTitles, $programName, $yearLevel, $activeSem);

        $rowId = (int)($row['id'] ?? 0);
        $padId = str_pad((string)($rowId > 0 ? $rowId : rand(1, 999)), 3, '0', STR_PAD_LEFT);
        $parsedRoadmap = json_decode((string)($row['roadmap'] ?? ''), true) ?: [];

        $queueTickets = [
            'registrar' => 'REG-' . $padId,
            'helpdesk'  => 'ADV-' . $padId,
            'medical'   => 'MED-' . $padId,
            'cashier'   => 'CSH-' . $padId,
            'it'        => 'ITC-' . $padId
        ];

        $stationArrivals = [
            'registrar' => $row['created_at'] ?? null,
            'helpdesk'  => $parsedRoadmap[1]['updatedAt'] ?? ($parsedRoadmap[0]['updatedAt'] ?? ($row['created_at'] ?? null)),
            'medical'   => $parsedRoadmap[2]['updatedAt'] ?? ($parsedRoadmap[1]['updatedAt'] ?? ($row['created_at'] ?? null)),
            'cashier'   => $parsedRoadmap[3]['updatedAt'] ?? ($parsedRoadmap[2]['updatedAt'] ?? ($row['created_at'] ?? null)),
            'it'        => $parsedRoadmap[4]['updatedAt'] ?? ($parsedRoadmap[3]['updatedAt'] ?? ($row['created_at'] ?? null))
        ];

        $activeStationKey = 'registrar';
        $statusUpper = strtoupper($row['status'] ?? '');
        if (in_array($statusUpper, ['VERIFIED', 'APPROVED'])) {
            $activeStationKey = 'helpdesk';
        } elseif ($statusUpper === 'ADVISED') {
            $activeStationKey = 'medical';
        } elseif ($statusUpper === 'MEDICAL_CLEARED') {
            $activeStationKey = 'cashier';
        } elseif (in_array($statusUpper, ['PAID', 'ENROLLED', 'PROMOTED', 'ACTIVE'])) {
            $activeStationKey = 'it';
        }

        $currentTicket = $queueTickets[$activeStationKey] ?? ('Q-' . $padId);

        return [
            'id'                 => (int)$row['id'],
            'referenceNumber'    => $row['temp_student_id'],
            'tempPin'            => $row['temp_pin'],
            'status'             => $row['status'] ?? 'PRE_REGISTERED',
            'queueTickets'       => $queueTickets,
            'stationArrivals'    => $stationArrivals,
            'currentTicket'      => $currentTicket,
            'activeStation'      => $activeStationKey,
            'lastName'           => $row['last_name'],
            'firstName'          => $row['first_name'],
            'middleName'         => $row['middle_name'] ?? '',
            'name'               => $fullName,
            'studentName'        => $fullName,
            'program'            => $row['course_code'],
            'studentType'        => $row['student_type'],
            'phone'              => $row['phone'],
            'email'              => $row['email'],
            'gender'             => $row['gender'] ?? 'Not specified',
            'birthDate'          => $row['birth_date'] ?? '',
            'address'            => $row['address'],
            'nstp'               => $row['nstp'] ?? '',
            'emergencyContactName'  => $row['emergency_contact_name'] ?? '',
            'emergencyContactPhone' => $row['emergency_contact_phone'] ?? '',
            'fitnessParticipation'  => (bool)($row['fitness_participation'] ?? true),
            'healthStatus'       => $row['health_status'] ?? 'GOOD',
            'medicalConditions'  => $medConditionsArr,
            'allergies'          => $row['allergies'] ?? 'None',
            'currentMedication'  => (bool)($row['current_medication'] ?? false),
            'medicationDetails'  => $row['medication_details'] ?? '',
            'paymentMode'        => $row['payment_mode'] ?? 'Cash',
            'datePreRegistered'  => date('F j, Y', strtotime($row['created_at'])),
            'createdAt'          => $row['created_at'],
            'seniorHighSchool'   => $row['senior_high_school'] ?? '',
            'shsTrack'           => $row['shs_track'] ?? '',
            'orNumber'           => $row['or_number'] ?? null,
            'enrolledAt'         => $row['enrolled_at'] ?? null,
            'cashierName'        => $row['cashier_name'] ?? null,
            'form'               => [
                'healthStatus'          => $row['health_status'] ?? 'GOOD',
                'medicalConditions'     => $medConditionsArr,
                'allergies'             => $row['allergies'] ?? 'None',
                'currentMedication'     => (bool)($row['current_medication'] ?? false),
                'medicationDetails'     => $row['medication_details'] ?? '',
                'fitnessParticipation'  => (bool)($row['fitness_participation'] ?? true),
                'emergencyContactName'  => $row['emergency_contact_name'] ?? '',
                'emergencyContactPhone' => $row['emergency_contact_phone'] ?? ''
            ],
            'roadmap'            => $parsedRoadmap,
            'sectionCode'        => !empty($row['section_code']) ? $row['section_code'] : '',
            'section_code'       => !empty($row['section_code']) ? $row['section_code'] : '',
            'section'            => !empty($row['section_code']) ? $row['section_code'] : '',
            'requirements'       => json_decode((string)($row['requirements_data'] ?? ''), true) ?: new stdClass(),
            'medical'            => json_decode((string)($row['medical_data'] ?? ''), true) ?: new stdClass(),
            'scholarship'        => json_decode((string)($row['scholarship_data'] ?? ''), true) ?: new stdClass(),
            'payment'            => json_decode((string)($row['payment_data'] ?? ''), true) ?: new stdClass(),
            'helpdesk'           => array_merge(
                ['scholarshipName' => $row['scholarship'] ?? 'NONE'],
                json_decode((string)($row['helpdesk_data'] ?? ''), true) ?: []
            ),
            'enrollment'         => json_decode((string)($row['enrollment_data'] ?? ''), true) ?: new stdClass(),
            'prospectusSubjects' => $progSubjects,
            'availableSections'  => $matchingSections,
            'activeSemester'     => $activeSem,
            'academicYear'       => $activePeriodYear,
            'curriculumVersion'  => $curriculumVer
        ];
    }
}
