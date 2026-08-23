<?php
/**
 * Student Model — Handles pre_enrollments and students tables queries
 */
class StudentModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function generateReferenceNumber() {
        $year = date('Y');
        $prefix = "REF-{$year}-";
        $stmt = $this->pdo->prepare("SELECT `temp_student_id` FROM `pre_enrollments` WHERE `temp_student_id` LIKE :prefix ORDER BY `id` DESC LIMIT 1");
        $stmt->execute(['prefix' => "{$prefix}%"]);
        $last = $stmt->fetchColumn();

        if ($last) {
            $num = (int)substr($last, strrpos($last, '-') + 1) + 1;
        } else {
            $num = 1001;
        }
        return $prefix . str_pad($num, 4, '0', STR_PAD_LEFT);
    }

    public function createPreEnrollment($data) {
        $refNo = $this->generateReferenceNumber();
        $tempPin = str_pad((string)rand(0, 9999), 4, '0', STR_PAD_LEFT);
        
        $sql = "INSERT INTO `pre_enrollments` (
            `temp_student_id`, `first_name`, `middle_name`, `last_name`, `email`, `phone`,
            `birth_date`, `gender`, `address`, `student_type`, `shs_track`, `previous_college`, `course_code`, `year_level_applied`,
            `elementary_school`, `junior_high_school`, `senior_high_school`, `honors`,
            `health_status`, `medical_conditions`, `allergies`, `current_medication`, `medication_details`,
            `fitness_participation`, `emergency_contact_name`, `emergency_contact_phone`,
            `payment_mode`, `scholarship`, `temp_pin`, `nstp`, `status`,
            `requirements_data`, `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`, `roadmap`
        ) VALUES (
            :ref, :fn, :mn, :ln, :email, :phone,
            :bd, :gender, :addr, :stype, :track, :prev_college, :ccode, :year,
            :elem, :jhs, :shs, :honors,
            :health_status, :medical_conditions, :allergies, :current_medication, :medication_details,
            :fitness_participation, :emergency_contact_name, :emergency_contact_phone,
            :payment_mode, :scholarship, :pin, :nstp, 'PRE_REGISTERED',
            :reqs, :medical_data, :scholarship_data, :payment_data, :helpdesk_data, :roadmap
        )";

        $defaultRoadmap = json_encode([
            ['id' => 1, 'stepId' => 'online_prereg', 'name' => 'Online Pre-Reg', 'status' => 'COMPLETED'],
            ['id' => 2, 'stepId' => 'registrar_verification', 'name' => 'Registrar Verification', 'status' => 'PENDING'],
            ['id' => 3, 'stepId' => 'advising_assessment', 'name' => 'Academic Advising', 'status' => 'LOCKED'],
            ['id' => 4, 'stepId' => 'clinic_checkup', 'name' => 'Medical Clearance', 'status' => 'LOCKED'],
            ['id' => 5, 'stepId' => 'scholarship_validation', 'name' => 'Scholarship', 'status' => 'LOCKED'],
            ['id' => 6, 'stepId' => 'cashier_payment', 'name' => 'Cashier Payment', 'status' => 'LOCKED'],
            ['id' => 7, 'stepId' => 'it_activation', 'name' => 'IT Center ID', 'status' => 'LOCKED']
        ]);

        $conditionsStr = is_array($data['medicalConditions'] ?? null) 
            ? implode(', ', $data['medicalConditions']) 
            : (string)($data['medicalConditions'] ?? $data['medical_conditions'] ?? '');

        $hasMedication = !empty($data['currentMedication']) || !empty($data['current_medication']) ? 1 : 0;
        $fitnessPart = isset($data['fitnessParticipation']) || isset($data['fitness_participation']) 
            ? (int)($data['fitnessParticipation'] ?? $data['fitness_participation']) 
            : 1;

        $emergencyName = !empty($data['emergencyContactName']) 
            ? $data['emergencyContactName'] 
            : (!empty($data['emergency_contact_name']) ? $data['emergency_contact_name'] : ($data['firstName'] . ' ' . $data['lastName'] . ' Guardian'));

        $emergencyPhone = !empty($data['emergencyContactPhone']) 
            ? $data['emergencyContactPhone'] 
            : (!empty($data['emergency_contact_phone']) ? $data['emergency_contact_phone'] : ($data['phone'] ?? '09170000000'));

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'ref' => $refNo,
            'fn' => $data['firstName'],
            'mn' => $data['middleName'] ?? null,
            'ln' => $data['lastName'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? '',
            'bd' => !empty($data['birthDate']) ? $data['birthDate'] : (!empty($data['birth_date']) ? $data['birth_date'] : '2000-01-01'),
            'gender' => $data['gender'] ?? 'MALE',
            'addr' => $data['address'] ?? 'Campus City',
            'stype' => $data['studentType'] ?? ($data['student_type'] ?? 'FRESHMAN'),
            'track' => $data['shsTrack'] ?? ($data['shs_track'] ?? ''),
            'prev_college' => $data['previousCollege'] ?? ($data['previous_college'] ?? null),
            'ccode' => $data['courseCode'] ?? ($data['course_code'] ?? 'BSIT'),
            'year' => $data['yearLevelApplied'] ?? ($data['year_level_applied'] ?? '1st Year'),
            'elem' => $data['elementarySchool'] ?? ($data['elementary_school'] ?? ''),
            'jhs' => $data['juniorHighSchool'] ?? ($data['junior_high_school'] ?? ''),
            'shs' => $data['seniorHighSchool'] ?? ($data['senior_high_school'] ?? ''),
            'honors' => $data['honors'] ?? null,
            'health_status' => $data['healthStatus'] ?? ($data['health_status'] ?? 'GOOD'),
            'medical_conditions' => $conditionsStr ?: null,
            'allergies' => $data['allergies'] ?? null,
            'current_medication' => $hasMedication,
            'medication_details' => $data['medicationDetails'] ?? ($data['medication_details'] ?? null),
            'fitness_participation' => $fitnessPart,
            'emergency_contact_name' => $emergencyName,
            'emergency_contact_phone' => $emergencyPhone,
            'payment_mode' => $data['paymentMode'] ?? ($data['payment_mode'] ?? 'CASH'),
            'scholarship' => $data['scholarship'] ?? 'NONE',
            'pin' => $tempPin,
            'nstp' => $data['nstp'] ?? 'CWTS',
            'reqs' => json_encode($data['requirements'] ?? []),
            'medical_data' => json_encode($data['medical'] ?? []),
            'scholarship_data' => json_encode($data['scholarship_data'] ?? []),
            'payment_data' => json_encode($data['payment'] ?? []),
            'helpdesk_data' => json_encode($data['helpdesk'] ?? []),
            'roadmap' => $defaultRoadmap
        ]);

        return [
            'referenceNumber' => $refNo,
            'tempPin' => $tempPin
        ];
    }

    public function findByReferenceNumber($refNo) {
        // First check pre_enrollments
        $stmt = $this->pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNo]);
        $pre = $stmt->fetch();
        if ($pre) {
            return $this->formatQueueItem($pre);
        }

        // If promoted, check permanent students table
        $stmt = $this->pdo->prepare("SELECT * FROM `students` WHERE `temp_reference_no` = :ref_temp OR `id` = :ref_id");
        $stmt->execute(['ref_temp' => $refNo, 'ref_id' => $refNo]);
        $stud = $stmt->fetch();
        if ($stud) {
            return $this->formatStudentItem($stud);
        }

        return null;
    }

    public function getQueue() {
        // Delegate to QueueService for dual-table aggregation (pre_enrollments + students),
        // proper status filtering, and N+1-free performance.
        require_once __DIR__ . '/../../stations/backend/services/QueueService.php';
        return QueueService::fetchQueue($this->pdo);
    }

    public function updatePreEnrollment($refNo, $updateData) {
        $stmt = $this->pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $stmt->execute(['ref' => $refNo]);
        $current = $stmt->fetch();
        if (!$current) return false;

        $fields = [];
        $params = ['ref' => $refNo];

        if (isset($updateData['roadmap'])) {
            $fields[] = "`roadmap` = :roadmap";
            $params['roadmap'] = is_string($updateData['roadmap']) ? $updateData['roadmap'] : json_encode($updateData['roadmap']);
        }
        if (isset($updateData['status'])) {
            $fields[] = "`status` = :status";
            $params['status'] = $updateData['status'];
        }
        if (isset($updateData['section_code']) || isset($updateData['sectionCode'])) {
            $sec = $updateData['section_code'] ?? $updateData['sectionCode'];
            $fields[] = "`section_code` = :sec";
            $params['sec'] = $sec;
        }
        if (isset($updateData['requirements'])) {
            $fields[] = "`requirements_data` = :reqs";
            $params['reqs'] = is_string($updateData['requirements']) ? $updateData['requirements'] : json_encode($updateData['requirements']);
        }
        if (isset($updateData['helpdesk'])) {
            $fields[] = "`helpdesk_data` = :helpdesk";
            $params['helpdesk'] = is_string($updateData['helpdesk']) ? $updateData['helpdesk'] : json_encode($updateData['helpdesk']);
        }
        if (isset($updateData['medical'])) {
            $fields[] = "`medical_data` = :medical";
            $params['medical'] = is_string($updateData['medical']) ? $updateData['medical'] : json_encode($updateData['medical']);
        }
        if (isset($updateData['scholarship'])) {
            $fields[] = "`scholarship_data` = :scholarship";
            $params['scholarship'] = is_string($updateData['scholarship']) ? $updateData['scholarship'] : json_encode($updateData['scholarship']);
        }
        if (isset($updateData['payment'])) {
            $fields[] = "`payment_data` = :payment";
            $params['payment'] = is_string($updateData['payment']) ? $updateData['payment'] : json_encode($updateData['payment']);
        }

        if (empty($fields)) return true;

        $sql = "UPDATE `pre_enrollments` SET " . implode(', ', $fields) . " WHERE `temp_student_id` = :ref";
        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute($params);
    }

    private function formatQueueItem($row) {
        $rowId = (int)($row['id'] ?? 0);
        $padId = str_pad((string)($rowId > 0 ? $rowId : rand(1, 999)), 3, '0', STR_PAD_LEFT);
        $parsedRoadmap = json_decode($row['roadmap'] ?? '[]', true) ?: [];

            $queueTickets = [
                'registrar' => 'REG-' . $padId,
                'helpdesk'  => 'ADV-' . $padId,
                'medical'   => 'MED-' . $padId,
                'cashier'   => 'CSH-' . $padId,
                'it'        => 'ITC-' . $padId
            ];

            // Determine active station details and live FIFO position
            $statusVal = strtoupper($row['status'] ?? 'PRE_REGISTERED');
            $activeStationKey = 'registrar';
            $stationName = 'Registrar Verification Desk';
            $stationLocation = 'Room 1109 — Main Building';

            if ($statusVal === 'VERIFIED') {
                $activeStationKey = 'helpdesk';
                $stationName = 'TLC Helpdesk & Academic Advising';
                $stationLocation = 'Room 1107 — Main Building';
            } elseif ($statusVal === 'ADVISED') {
                $activeStationKey = 'medical';
                $stationName = 'School Clinic — Medical Checkup';
                $stationLocation = 'Room 1105 — Ground Floor';
            } elseif ($statusVal === 'MEDICAL_CLEARED') {
                $activeStationKey = 'cashier';
                $stationName = 'Treasury & Cashier Window';
                $stationLocation = 'Room 1111 — Administration Wing';
            } elseif ($statusVal === 'PAID') {
                $activeStationKey = 'it';
                $stationName = 'IT Center — ID & Account Provisioning';
                $stationLocation = 'Room 1201 — 2nd Floor IT Wing';
            } elseif ($statusVal === 'ENROLLED' || $statusVal === 'ACTIVE') {
                $activeStationKey = 'completed';
                $stationName = 'Enrollment Finalized';
                $stationLocation = 'Online Student Portal Active';
            }

            // Calculate live queue rank ahead of this applicant
            $aheadCount = 0;
            if ($activeStationKey !== 'completed') {
                try {
                    $rankStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `pre_enrollments` WHERE `status` = :st AND `id` < :id");
                    $rankStmt->execute(['st' => $row['status'], 'id' => $rowId]);
                    $aheadCount = (int)$rankStmt->fetchColumn();
                } catch (Exception $e) {
                    $aheadCount = 0;
                }
            }
            $queueRank = $aheadCount + 1;
            $currentTicket = $queueTickets[$activeStationKey] ?? ('Q-' . $padId);

            return [
                'id' => (int)$row['id'],
                'referenceNumber' => $row['temp_student_id'] ?? ($row['reference_number'] ?? ''),
                'firstName' => $row['first_name'],
                'middleName' => $row['middle_name'],
                'lastName' => $row['last_name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'birthDate' => $row['birth_date'],
                'gender' => $row['gender'],
                'address' => $row['address'],
                'studentType' => $row['student_type'],
                'shsTrack' => $row['shs_track'],
                'previousCollege' => $row['previous_college'] ?? '',
                'courseCode' => $row['course_code'],
                'yearLevelApplied' => $row['year_level_applied'] ?? '1st Year',
                'sectionCode' => $row['section_code'] ?? '',
                'tempPin' => $row['temp_pin'] ?? '',
                'status' => $row['status'],
                'queueTickets' => $queueTickets,
                'currentTicket' => $currentTicket,
                'activeStation' => $activeStationKey,
                'stationName' => $stationName,
                'stationLocation' => $stationLocation,
                'queueRank' => $queueRank,
                'aheadCount' => $aheadCount,
                'requirements' => json_decode($row['requirements_data'] ?? '[]', true),
                'roadmap' => $parsedRoadmap,
                'helpdesk' => json_decode($row['helpdesk_data'] ?? '{}', true),
                'medical' => json_decode($row['medical_data'] ?? '{}', true),
                'scholarship' => json_decode($row['scholarship_data'] ?? '{}', true),
                'payment' => json_decode($row['payment_data'] ?? '{}', true),
                'created_at' => $row['created_at']
            ];
        }

    private function formatStudentItem($row) {
        $personal = json_decode($row['personal_info'] ?? '{}', true);
        return [
            'id' => $row['id'],
            'referenceNumber' => $row['temp_reference_no'] ?? $row['id'],
            'firstName' => $personal['firstName'] ?? '',
            'middleName' => $personal['middleName'] ?? '',
            'lastName' => $personal['lastName'] ?? $row['name'],
            'email' => $row['email'],
            'program' => $row['program'],
            'yearLevel' => $row['year_level'],
            'status' => 'ENROLLED',
            'photo' => $row['photo'],
            'roadmap' => json_decode($row['roadmap'] ?? '[]', true),
            'requirements' => json_decode($row['requirements_data'] ?? '[]', true),
            'helpdesk' => json_decode($row['helpdesk_data'] ?? '{}', true),
            'medical' => json_decode($row['medical_data'] ?? '{}', true),
            'payment' => json_decode($row['payment_data'] ?? '{}', true),
            'enrollment' => json_decode($row['enrollment_data'] ?? '{}', true)
        ];
    }

    public function deleteTestRecords($pattern = 'test.student.%@gncp.edu.ph') {
        $deleted = 0;
        try {
            $stmt1 = $this->pdo->prepare("DELETE FROM `pre_enrollments` WHERE `email` LIKE :pattern1 OR `email` LIKE 'test.%@gncp.edu.ph' OR `first_name` LIKE 'Test%'");
            $stmt1->execute(['pattern1' => $pattern]);
            $deleted += $stmt1->rowCount();

            $stmt2 = $this->pdo->prepare("DELETE FROM `students` WHERE `email` LIKE :pattern2 OR `email` LIKE 'test.%@gncp.edu.ph' OR `name` LIKE 'Test%'");
            $stmt2->execute(['pattern2' => $pattern]);
            $deleted += $stmt2->rowCount();
        } catch (Exception $e) {
            if (function_exists('logAppError')) {
                logAppError("deleteTestRecords Error: " . $e->getMessage());
            }
        }
        return $deleted;
    }

    public static function getDefaultRequirementsCatalog() {
        return [
            [
                'key' => 'form_138',
                'title' => 'Form 138 / Senior High Report Card',
                'description' => 'Original or certified true copy of Form 138 / Grade 12 Report Card with passing marks.',
                'required' => true,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ],
            [
                'key' => 'psa_birth_cert',
                'title' => 'PSA Authenticated Birth Certificate',
                'description' => 'Clear colored scan or photocopy of Philippine Statistics Authority (PSA) Birth Certificate.',
                'required' => true,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ],
            [
                'key' => 'good_moral',
                'title' => 'Certificate of Good Moral Character',
                'description' => 'Original Good Moral Certificate issued by the high school principal or guidance counselor.',
                'required' => true,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ],
            [
                'key' => 'id_pictures',
                'title' => '2x2 Recent Color ID Pictures',
                'description' => '2 pieces 2x2 color photographs on white background with printed student name tag.',
                'required' => true,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ],
            [
                'key' => 'medical_clearance',
                'title' => 'Campus Medical & Health Clearance',
                'description' => 'Health clearance endorsed by GNCP Medical Services Unit or accredited clinical doctor.',
                'required' => true,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ],
            [
                'key' => 'undertaking_form',
                'title' => 'Conditional Enrollment Undertaking Form',
                'description' => 'Signed commitment agreement by student and guardian for temporary submission waivers.',
                'required' => false,
                'status' => 'NOT_SUBMITTED',
                'softCopyUrl' => null,
                'fileName' => null,
                'fileType' => null,
                'fileSize' => null,
                'submittedAt' => null,
                'verifiedAt' => null,
                'verifiedBy' => null,
                'isUndertaking' => false,
                'undertakingReason' => null,
                'undertakingDeadline' => null,
                'registrarRemarks' => null
            ]
        ];
    }

    public function getStudentRequirements($identifier) {
        $cleanId = trim((string)$identifier);
        if (empty($cleanId)) {
            return null;
        }

        $row = null;
        $isOfficial = false;

        // Check students table first
        $stmt = $this->pdo->prepare("SELECT * FROM `students` WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email LIMIT 1");
        $stmt->execute(['id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $isOfficial = true;
        } else {
            // Check pre_enrollments table
            $stmt = $this->pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref OR `email` = :email OR `id` = :id LIMIT 1");
            $stmt->execute(['ref' => $cleanId, 'email' => $cleanId, 'id' => $cleanId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        }

        if (!$row) {
            return null;
        }

        $existingReqs = json_decode($row['requirements_data'] ?? '[]', true) ?: [];
        $catalog = self::getDefaultRequirementsCatalog();
        $mergedReqs = [];

        foreach ($catalog as $defaultDoc) {
            $found = null;
            // Match by key or title
            foreach ($existingReqs as $item) {
                $itemKey = strtolower($item['key'] ?? ($item['name'] ?? ''));
                $defKey = strtolower($defaultDoc['key']);
                if ($itemKey === $defKey || stripos($itemKey, str_replace('_', '', $defKey)) !== false || stripos($item['title'] ?? '', $defaultDoc['title']) !== false) {
                    $found = $item;
                    break;
                }
            }

            if ($found) {
                $status = strtoupper($found['status'] ?? 'NOT_SUBMITTED');
                $isUndertaking = !empty($found['isUndertaking']) || !empty($found['undertaking']) || $status === 'UNDERTAKING';
                if ($isUndertaking && $status !== 'VERIFIED') {
                    $status = 'UNDERTAKING';
                }

                $mergedReqs[] = [
                    'key' => $defaultDoc['key'],
                    'title' => $defaultDoc['title'],
                    'description' => $defaultDoc['description'],
                    'required' => $defaultDoc['required'],
                    'status' => $status,
                    'softCopyUrl' => $found['softCopyUrl'] ?? ($found['fileUrl'] ?? ($found['url'] ?? null)),
                    'fileName' => $found['fileName'] ?? ($found['name'] ?? null),
                    'fileType' => $found['fileType'] ?? ($found['type'] ?? null),
                    'fileSize' => $found['fileSize'] ?? ($found['size'] ?? null),
                    'submittedAt' => $found['submittedAt'] ?? ($found['uploadedAt'] ?? null),
                    'verifiedAt' => $found['verifiedAt'] ?? null,
                    'verifiedBy' => $found['verifiedBy'] ?? null,
                    'isUndertaking' => $isUndertaking,
                    'undertakingReason' => $found['undertakingReason'] ?? ($found['reason'] ?? null),
                    'undertakingDeadline' => $found['undertakingDeadline'] ?? ($found['deadline'] ?? null),
                    'registrarRemarks' => $found['registrarRemarks'] ?? ($found['remarks'] ?? null)
                ];
            } else {
                $mergedReqs[] = $defaultDoc;
            }
        }

        // Summary calculations
        $totalRequired = 0;
        $verifiedCount = 0;
        $pendingReviewCount = 0;
        $undertakingCount = 0;
        $missingCount = 0;

        foreach ($mergedReqs as $doc) {
            if ($doc['required']) {
                $totalRequired++;
            }
            if ($doc['status'] === 'VERIFIED') {
                $verifiedCount++;
            } elseif ($doc['status'] === 'UNDER_REVIEW' || $doc['status'] === 'SUBMITTED') {
                $pendingReviewCount++;
            } elseif ($doc['status'] === 'UNDERTAKING') {
                $undertakingCount++;
            } else {
                if ($doc['required']) {
                    $missingCount++;
                }
            }
        }

        $studentName = $isOfficial ? ($row['name'] ?? '') : (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $studentId = $isOfficial ? ($row['id'] ?? '') : ($row['temp_student_id'] ?? '');

        return [
            'studentId' => $studentId,
            'studentName' => trim($studentName),
            'email' => $row['email'] ?? '',
            'program' => $row['program'] ?? ($row['course_code'] ?? ''),
            'isOfficial' => $isOfficial,
            'requirements' => $mergedReqs,
            'stats' => [
                'totalRequired' => $totalRequired,
                'verifiedCount' => $verifiedCount,
                'pendingReviewCount' => $pendingReviewCount,
                'undertakingCount' => $undertakingCount,
                'missingCount' => $missingCount,
                'isFullyCompliant' => ($verifiedCount >= $totalRequired && $undertakingCount === 0)
            ]
        ];
    }

    public function saveStudentDocument($identifier, $docKey, $softCopyUrl, $fileName, $fileType, $fileSize, $isUndertaking = false, $undertakingReason = '', $undertakingDeadline = '') {
        $studentReqs = $this->getStudentRequirements($identifier);
        if (!$studentReqs) {
            throw new Exception("Student record not found for reference: {$identifier}");
        }

        $reqs = $studentReqs['requirements'];
        $foundIdx = -1;

        foreach ($reqs as $i => $r) {
            if ($r['key'] === $docKey) {
                $foundIdx = $i;
                break;
            }
        }

        $newDoc = [
            'key' => $docKey,
            'title' => $foundIdx >= 0 ? $reqs[$foundIdx]['title'] : ucwords(str_replace('_', ' ', $docKey)),
            'description' => $foundIdx >= 0 ? $reqs[$foundIdx]['description'] : '',
            'required' => $foundIdx >= 0 ? $reqs[$foundIdx]['required'] : true,
            'status' => 'UNDER_REVIEW',
            'softCopyUrl' => $softCopyUrl,
            'fileName' => $fileName,
            'fileType' => $fileType,
            'fileSize' => $fileSize,
            'submittedAt' => date('Y-m-d H:i:s'),
            'verifiedAt' => null,
            'verifiedBy' => null,
            'isUndertaking' => (bool)$isUndertaking,
            'undertakingReason' => $undertakingReason ?: null,
            'undertakingDeadline' => $undertakingDeadline ?: null,
            'registrarRemarks' => null
        ];

        if ($foundIdx >= 0) {
            $reqs[$foundIdx] = $newDoc;
        } else {
            $reqs[] = $newDoc;
        }

        $jsonReqs = json_encode($reqs);
        $cleanId = trim((string)$identifier);

        if ($studentReqs['isOfficial']) {
            $stmt = $this->pdo->prepare("UPDATE `students` SET `requirements_data` = :reqs WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email");
            $stmt->execute(['reqs' => $jsonReqs, 'id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        }

        // Also update pre_enrollments if existing
        $stmtPe = $this->pdo->prepare("UPDATE `pre_enrollments` SET `requirements_data` = :reqs WHERE `temp_student_id` = :ref OR `email` = :email OR `id` = :id");
        $stmtPe->execute(['reqs' => $jsonReqs, 'ref' => $cleanId, 'email' => $cleanId, 'id' => $cleanId]);

        return $this->getStudentRequirements($identifier);
    }

    public function verifyStudentDocument($identifier, $docKey, $status, $remarks = '', $verifiedBy = 'Registrar Officer') {
        $studentReqs = $this->getStudentRequirements($identifier);
        if (!$studentReqs) {
            throw new Exception("Student record not found for reference: {$identifier}");
        }

        $reqs = $studentReqs['requirements'];
        $foundIdx = -1;

        foreach ($reqs as $i => $r) {
            if ($r['key'] === $docKey) {
                $foundIdx = $i;
                break;
            }
        }

        if ($foundIdx === -1) {
            throw new Exception("Document requirement {$docKey} not found for student.");
        }

        $reqs[$foundIdx]['status'] = strtoupper($status);
        $reqs[$foundIdx]['registrarRemarks'] = $remarks ?: null;
        $reqs[$foundIdx]['verifiedAt'] = date('Y-m-d H:i:s');
        $reqs[$foundIdx]['verifiedBy'] = $verifiedBy;
        if (strtoupper($status) === 'VERIFIED') {
            $reqs[$foundIdx]['isUndertaking'] = false;
        }

        $jsonReqs = json_encode($reqs);
        $cleanId = trim((string)$identifier);

        if ($studentReqs['isOfficial']) {
            $stmt = $this->pdo->prepare("UPDATE `students` SET `requirements_data` = :reqs WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email");
            $stmt->execute(['reqs' => $jsonReqs, 'id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        }

        $stmtPe = $this->pdo->prepare("UPDATE `pre_enrollments` SET `requirements_data` = :reqs WHERE `temp_student_id` = :ref OR `email` = :email OR `id` = :id");
        $stmtPe->execute(['reqs' => $jsonReqs, 'ref' => $cleanId, 'email' => $cleanId, 'id' => $cleanId]);

        return $this->getStudentRequirements($identifier);
    }
}
