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
        $tempPin = sprintf('%06d', random_int(100000, 999999));
        
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
            'ccode' => $data['courseCode'] ?? ($data['course_code'] ?? ($data['course'] ?? ($data['program'] ?? 'BSIT'))),
            'year' => $data['yearLevelApplied'] ?? ($data['year_level_applied'] ?? ($data['yearLevel'] ?? ($data['year_level'] ?? '1st Year'))),
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

    public function findApplicantByRef($ref) {
        $stmt = $this->pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref OR `id` = :id LIMIT 1");
        $stmt->execute(['ref' => $ref, 'id' => $ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $row['security_pin'] = $row['temp_pin'] ?? '';
            return $row;
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

    public static function getRequirementKey($title) {
        $text = strtolower(trim((string)$title));
        if (strpos($text, 'form 138') !== false || (strpos($text, 'report card') !== false && strpos($text, 'old high school') === false)) return 'reportCard';
        if (strpos($text, 'psa') !== false || strpos($text, 'birth certificate') !== false) return 'psa';
        if (strpos($text, 'good moral') !== false) return 'goodMoral';
        if (strpos($text, '2x2') !== false || strpos($text, 'picture') !== false) return '2x2_picture';
        return preg_replace('/[^a-z0-9]+/', '_', trim($text, '_'));
    }

    public static function getRequirementDescription($title) {
        $t = strtolower((string)$title);
        if (strpos($t, 'form 138') !== false || strpos($t, 'report card') !== false) {
            return 'Original or certified true copy of Grade 12 Report Card (Form 138) with passing marks.';
        }
        if (strpos($t, 'good moral') !== false) {
            return 'Original Certificate of Good Moral Character issued by the school principal or guidance counselor.';
        }
        if (strpos($t, 'psa') !== false || strpos($t, 'birth certificate') !== false) {
            return 'Clear copy of Philippine Statistics Authority (PSA) authenticated Birth Certificate.';
        }
        if (strpos($t, '2x2') !== false || strpos($t, 'picture') !== false) {
            return 'Two (2) pieces 2x2 color photographs on white background with student name tag.';
        }
        if (strpos($t, 'tor') !== false || strpos($t, 'transcript') !== false || strpos($t, 'grades') !== false) {
            return 'Official Transcript of Records (TOR) or certified copy of grades from previous institution.';
        }
        if (strpos($t, 'honorable dismissal') !== false || strpos($t, 'transfer') !== false) {
            return 'Original Certificate of Honorable Dismissal or Transfer Credentials.';
        }
        if (strpos($t, 'als') !== false) {
            return 'Official Alternative Learning System (ALS) Certificate of Rating / Completion.';
        }
        if (strpos($t, 'clearance') !== false) {
            return 'Institutional student clearance form endorsed from the previous term.';
        }
        return 'Official credential required for institutional enrollment verification.';
    }

    public static function getDefaultRequirementsCatalog($studentType = 'FRESHMAN', $shsTrack = '') {
        if (!function_exists('getRequirementsForType')) {
            require_once __DIR__ . '/../../shared/backend/utils/student.php';
        }

        $titles = function_exists('getRequirementsForType') 
            ? getRequirementsForType($studentType, $shsTrack) 
            : [
                'Form 138 (Original Senior High School Report Card)',
                'Original Certificate of Good Moral Character (with dry seal)',
                'PSA Birth Certificate (Photocopy)',
                '2 pieces recent 2x2 color pictures (white background with name tag)'
            ];

        $catalog = [];
        foreach ($titles as $t) {
            $key = self::getRequirementKey($t);
            $catalog[] = [
                'key'                => $key,
                'title'              => $t,
                'description'        => self::getRequirementDescription($t),
                'required'           => true,
                'status'             => 'NOT_SUBMITTED',
                'submissionMode'     => 'HARDCOPY',
                'isActionable'       => true,
                'softCopyUrl'        => null,
                'fileName'           => null,
                'fileType'           => null,
                'fileSize'           => null,
                'submittedAt'        => null,
                'verifiedAt'         => null,
                'verifiedBy'         => null,
                'isUndertaking'      => false,
                'undertakingReason'  => null,
                'undertakingDeadline'=> null,
                'registrarRemarks'   => null
            ];
        }
        return $catalog;
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

        $studentType = $row['student_type'] ?? 'FRESHMAN';
        $shsTrack = $row['shs_track'] ?? '';

        // Dual-table merge for promoted students
        if ($isOfficial) {
            $refLookup = !empty($row['temp_reference_no']) ? $row['temp_reference_no'] : $row['id'];
            $peStmt = $this->pdo->prepare("SELECT `student_type`, `shs_track`, `requirements_data`, `status`, `roadmap` FROM `pre_enrollments` WHERE `temp_student_id` = :r1 OR `existing_student_id` = :r2 LIMIT 1");
            $peStmt->execute(['r1' => $refLookup, 'r2' => $row['id']]);
            $peRow = $peStmt->fetch(PDO::FETCH_ASSOC);
            if ($peRow) {
                if (empty($studentType) || $studentType === 'FRESHMAN') {
                    $studentType = $peRow['student_type'] ?? $studentType;
                }
                if (empty($shsTrack)) {
                    $shsTrack = $peRow['shs_track'] ?? $shsTrack;
                }
                if (empty($row['requirements_data']) && !empty($peRow['requirements_data'])) {
                    $row['requirements_data'] = $peRow['requirements_data'];
                }
                if (empty($row['roadmap']) && !empty($peRow['roadmap'])) {
                    $row['roadmap'] = $peRow['roadmap'];
                }
            }
        }

        $rawReqData = json_decode((string)($row['requirements_data'] ?? '[]'), true) ?: [];

        // ── Detect Registrar Processing & Approval ────────────────────────────────
        $recordStatus = strtoupper($row['status'] ?? '');
        $reqDataStatus = strtoupper($rawReqData['status'] ?? '');

        $roadmap = json_decode((string)($row['roadmap'] ?? '[]'), true) ?: [];
        $roadmapRegistrarDone = false;
        if (is_array($roadmap)) {
            foreach ($roadmap as $step) {
                $stepId = $step['stepId'] ?? ($step['id'] ?? '');
                if (($stepId === 'registrar_verification' || $stepId == 2) && strtoupper($step['status'] ?? '') === 'COMPLETED') {
                    $roadmapRegistrarDone = true;
                    break;
                }
            }
        }

        // Student is considered processed/approved by Registrar if:
        // - Already promoted to official students table, OR
        // - Status is VERIFIED, APPROVED, ADVISED, MEDICAL_CLEARED, PAID, ENROLLED, ACTIVE, OR
        // - requirements_data.status is VERIFIED, APPROVED, REGISTRAR_APPROVED, COMPLETED, OR
        // - Roadmap registrar verification step is marked COMPLETED
        $isRegistrarProcessed = (
            $isOfficial ||
            in_array($recordStatus, ['VERIFIED', 'APPROVED', 'REGISTRAR_APPROVED', 'ADVISED', 'MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'ACTIVE']) ||
            in_array($reqDataStatus, ['VERIFIED', 'APPROVED', 'REGISTRAR_APPROVED', 'COMPLETED']) ||
            $roadmapRegistrarDone
        );

        // ── Normalized Map Extraction ─────────────────────────────────────────────
        $normalizedMap = [];

        // Format A: registrar object with docs sub-key
        if (!empty($rawReqData['docs']) && is_array($rawReqData['docs'])) {
            foreach ($rawReqData['docs'] as $docKey => $entry) {
                if (!is_array($entry)) {
                    $entry = ['status' => (string)$entry];
                }
                $normalizedMap[$docKey] = $entry;
            }
        } elseif (is_array($rawReqData) && isset($rawReqData[0])) {
            // Format B: flat array of requirement objects
            foreach ($rawReqData as $item) {
                if (is_array($item) && !empty($item['key'])) {
                    $normalizedMap[$item['key']] = $item;
                }
            }
        } elseif (is_array($rawReqData) && !empty($rawReqData)) {
            foreach ($rawReqData as $k => $v) {
                if (is_string($k) && !in_array($k, ['status','notes','verifiedBy','dateVerified','returnReason','returnedBy','dateReturned','files','transmittal'])) {
                    $normalizedMap[$k] = is_array($v) ? $v : ['status' => (string)$v];
                }
            }
        }

        // Merge files metadata if available (from pre-enrollment upload)
        if (!empty($rawReqData['files']) && is_array($rawReqData['files'])) {
            foreach ($rawReqData['files'] as $fKey => $fMeta) {
                if (is_array($fMeta)) {
                    if (!isset($normalizedMap[$fKey])) {
                        $normalizedMap[$fKey] = [];
                    }
                    $normalizedMap[$fKey]['softCopyUrl'] = $fMeta['filePath'] ?? ($fMeta['url'] ?? null);
                    $normalizedMap[$fKey]['fileName']    = $fMeta['fileName'] ?? null;
                    $normalizedMap[$fKey]['fileType']    = $fMeta['fileType'] ?? null;
                    $normalizedMap[$fKey]['fileSize']    = $fMeta['fileSize'] ?? null;
                    $normalizedMap[$fKey]['submittedAt'] = $fMeta['uploadedAt'] ?? null;
                }
            }
        }

        // ── Build Applicable Requirements Catalog ────────────────────────────────
        if (!function_exists('getRequirementsForType')) {
            require_once __DIR__ . '/../../shared/backend/utils/student.php';
        }

        $applicableTitles = function_exists('getRequirementsForType')
            ? getRequirementsForType($studentType, $shsTrack)
            : [
                'Form 138 (Original Senior High School Report Card)',
                'Original Certificate of Good Moral Character (with dry seal)',
                'PSA Birth Certificate (Photocopy)',
                '2 pieces recent 2x2 color pictures (white background with name tag)'
            ];

        $mergedReqs = [];
        $actionableReqs = [];
        $completedReqs = [];
        $undertakingCount = 0;
        $missingCount = 0;

        foreach ($applicableTitles as $title) {
            $key = self::getRequirementKey($title);

            // Find matching entry in normalized map
            $found = $normalizedMap[$key] ?? null;
            if (!$found) {
                foreach ($normalizedMap as $mKey => $mVal) {
                    $lowerMKey = strtolower($mKey);
                    if ($lowerMKey === strtolower($key) ||
                        ($key === 'reportCard' && in_array($lowerMKey, ['form_138', 'report_card', 'form138'])) ||
                        ($key === 'psa' && in_array($lowerMKey, ['psa_birth_cert', 'birth_cert', 'psa_birth_certificate'])) ||
                        ($key === 'goodMoral' && in_array($lowerMKey, ['good_moral', 'moral_character', 'goodmoral'])) ||
                        (strpos($key, '2x2') !== false && (strpos($lowerMKey, '2x2') !== false || strpos($lowerMKey, 'picture') !== false || strpos($lowerMKey, 'id_picture') !== false))) {
                        $found = $mVal;
                        break;
                    }
                }
            }

            $rawStatus = strtoupper(is_array($found) ? ($found['status'] ?? '') : (string)($found ?? ''));
            $isUndertaking = ($rawStatus === 'UNDERTAKING' || !empty($found['isUndertaking']));
            $softCopyUrl = $found['softCopyUrl'] ?? ($found['fileUrl'] ?? ($found['filePath'] ?? null));
            $fileName    = $found['fileName'] ?? ($found['name'] ?? null);

            // Determine canonical portal status and actionability
            if ($isUndertaking) {
                // Specifically marked as Undertaking by Registrar -> actionable until fulfilled
                $status = 'UNDERTAKING';
                $isActionable = true;
                $submissionMode = 'UNDERTAKING';
                $undertakingCount++;
            } elseif ($rawStatus === 'REJECTED') {
                // Registrar rejected requirement -> requires resubmission/action
                $status = 'REJECTED';
                $isActionable = true;
                $submissionMode = $softCopyUrl ? 'DIGITAL_UPLOAD' : 'HARDCOPY';
                $missingCount++;
            } elseif (in_array($rawStatus, ['ORIGINAL', 'PHOTOCOPY', 'VERIFIED', 'APPROVED'])) {
                // Document received and verified by Registrar (Hardcopy or approved softcopy)
                $status = 'VERIFIED';
                $isActionable = false;
                $submissionMode = in_array($rawStatus, ['ORIGINAL', 'PHOTOCOPY']) ? 'HARDCOPY' : ($softCopyUrl ? 'DIGITAL_UPLOAD' : 'HARDCOPY');
            } elseif ($isRegistrarProcessed) {
                // Rule 1 & 2: If the Registrar approved the student, unuploaded documents are Hardcopies
                // received and approved during in-person verification. They are COMPLETE.
                $status = 'VERIFIED';
                $isActionable = false;
                $submissionMode = in_array($rawStatus, ['ORIGINAL', 'PHOTOCOPY']) ? 'HARDCOPY' : ($softCopyUrl ? 'DIGITAL_UPLOAD' : 'HARDCOPY');
            } else {
                // Registrar has NOT yet processed application (PRE_REGISTERED)
                if ($softCopyUrl || in_array($rawStatus, ['SUBMITTED', 'PENDING_REVIEW'])) {
                    $status = 'UNDER_REVIEW';
                    $isActionable = false;
                    $submissionMode = 'DIGITAL_UPLOAD';
                } else {
                    $status = 'NOT_SUBMITTED';
                    $isActionable = true;
                    $submissionMode = 'HARDCOPY';
                    $missingCount++;
                }
            }

            $docObj = [
                'key'                 => $key,
                'title'               => $title,
                'description'         => self::getRequirementDescription($title),
                'required'            => true,
                'status'              => $status,
                'submissionMode'      => $submissionMode,
                'isActionable'        => $isActionable,
                'softCopyUrl'         => $softCopyUrl,
                'fileName'            => $fileName,
                'fileType'            => $found['fileType'] ?? null,
                'fileSize'            => $found['fileSize'] ?? null,
                'submittedAt'         => $found['submittedAt'] ?? ($found['dateUpdated'] ?? null),
                'verifiedAt'          => ($status === 'VERIFIED') ? ($rawReqData['dateVerified'] ?? ($found['dateUpdated'] ?? null)) : null,
                'verifiedBy'          => ($status === 'VERIFIED') ? ($rawReqData['verifiedBy'] ?? 'Registrar Officer') : null,
                'isUndertaking'       => $isUndertaking,
                'undertakingReason'   => $found['remarks'] ?? ($found['undertakingReason'] ?? null),
                'undertakingDeadline' => $found['deadline'] ?? ($found['undertakingDeadline'] ?? null),
                'registrarRemarks'    => $found['remarks'] ?? ($found['registrarRemarks'] ?? null),
            ];

            $mergedReqs[] = $docObj;
            if ($isActionable) {
                $actionableReqs[] = $docObj;
            } else {
                $completedReqs[] = $docObj;
            }
        }

        $studentName = $isOfficial ? ($row['name'] ?? '') : (($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
        $studentId = $isOfficial ? ($row['id'] ?? '') : ($row['temp_student_id'] ?? '');

        return [
            'studentId'             => $studentId,
            'studentName'           => trim($studentName),
            'email'                 => $row['email'] ?? '',
            'program'               => $row['program'] ?? ($row['course_code'] ?? ''),
            'studentType'           => $studentType,
            'isOfficial'            => $isOfficial,
            'isRegistrarProcessed'  => $isRegistrarProcessed,
            'requirements'          => $mergedReqs,
            'pendingRequirements'   => $actionableReqs,
            'completedRequirements' => $completedReqs,
            'stats' => [
                'totalRequired'       => count($mergedReqs),
                'completedCount'      => count($completedReqs),
                'verifiedCount'       => count($completedReqs),
                'pendingReviewCount'  => count(array_filter($mergedReqs, fn($d) => $d['status'] === 'UNDER_REVIEW')),
                'undertakingCount'    => $undertakingCount,
                'actionableCount'     => count($actionableReqs),
                'missingCount'        => $missingCount,
                'isFullyCompliant'    => (count($actionableReqs) === 0),
                'isComplete'          => (count($actionableReqs) === 0)
            ]
        ];
    }

    public function saveStudentDocument($identifier, $docKey, $softCopyUrl, $fileName, $fileType, $fileSize, $isUndertaking = false, $undertakingReason = '', $undertakingDeadline = '') {
        $cleanId = trim((string)$identifier);
        $studentReqs = $this->getStudentRequirements($cleanId);
        if (!$studentReqs) {
            throw new Exception("Student record not found for reference: {$cleanId}");
        }

        // Fetch existing raw requirements_data to maintain original structure
        $rawStmt = $this->pdo->prepare($studentReqs['isOfficial']
            ? "SELECT `requirements_data` FROM `students` WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email LIMIT 1"
            : "SELECT `requirements_data` FROM `pre_enrollments` WHERE `temp_student_id` = :id OR `email` = :email OR `id` = :ref LIMIT 1"
        );
        $rawStmt->execute(['id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        $rawJson = $rawStmt->fetchColumn();
        $rawObj = json_decode((string)$rawJson, true) ?: [];

        if (is_array($rawObj) && (isset($rawObj['docs']) || isset($rawObj['status']))) {
            // Format A: preserve registrar object structure
            if (!isset($rawObj['docs'])) $rawObj['docs'] = [];
            $prevEntry = $rawObj['docs'][$docKey] ?? [];
            if (!is_array($prevEntry)) $prevEntry = ['status' => (string)$prevEntry];

            $rawObj['docs'][$docKey] = array_merge($prevEntry, [
                'status'      => 'UNDER_REVIEW',
                'softCopyUrl' => $softCopyUrl,
                'fileName'    => $fileName,
                'fileType'    => $fileType,
                'fileSize'    => $fileSize,
                'submittedAt' => date('Y-m-d H:i:s')
            ]);
            if ($isUndertaking) {
                $rawObj['docs'][$docKey]['isUndertaking'] = true;
                if ($undertakingReason) $rawObj['docs'][$docKey]['remarks'] = $undertakingReason;
                if ($undertakingDeadline) $rawObj['docs'][$docKey]['deadline'] = $undertakingDeadline;
            }
            $finalPayload = json_encode($rawObj);
        } else {
            // Format B: flat list of requirements
            $reqs = $studentReqs['requirements'];
            $foundIdx = -1;
            foreach ($reqs as $i => $r) {
                if ($r['key'] === $docKey) {
                    $foundIdx = $i;
                    break;
                }
            }
            $newDoc = [
                'key'                 => $docKey,
                'title'               => $foundIdx >= 0 ? $reqs[$foundIdx]['title'] : ucwords(str_replace('_', ' ', $docKey)),
                'description'         => $foundIdx >= 0 ? $reqs[$foundIdx]['description'] : '',
                'required'            => true,
                'status'              => 'UNDER_REVIEW',
                'softCopyUrl'         => $softCopyUrl,
                'fileName'            => $fileName,
                'fileType'            => $fileType,
                'fileSize'            => $fileSize,
                'submittedAt'         => date('Y-m-d H:i:s'),
                'verifiedAt'          => null,
                'verifiedBy'          => null,
                'isUndertaking'       => (bool)$isUndertaking,
                'undertakingReason'   => $undertakingReason ?: null,
                'undertakingDeadline' => $undertakingDeadline ?: null,
                'registrarRemarks'    => null
            ];
            if ($foundIdx >= 0) {
                $reqs[$foundIdx] = $newDoc;
            } else {
                $reqs[] = $newDoc;
            }
            $finalPayload = json_encode($reqs);
        }

        if ($studentReqs['isOfficial']) {
            $stmt = $this->pdo->prepare("UPDATE `students` SET `requirements_data` = :reqs WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email");
            $stmt->execute(['reqs' => $finalPayload, 'id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        }

        $stmtPe = $this->pdo->prepare("UPDATE `pre_enrollments` SET `requirements_data` = :reqs WHERE `temp_student_id` = :ref OR `email` = :email OR `id` = :id");
        $stmtPe->execute(['reqs' => $finalPayload, 'ref' => $cleanId, 'email' => $cleanId, 'id' => $cleanId]);

        return $this->getStudentRequirements($cleanId);
    }

    public function verifyStudentDocument($identifier, $docKey, $status, $remarks = '', $verifiedBy = 'Registrar Officer') {
        $cleanId = trim((string)$identifier);
        $studentReqs = $this->getStudentRequirements($cleanId);
        if (!$studentReqs) {
            throw new Exception("Student record not found for reference: {$cleanId}");
        }

        $rawStmt = $this->pdo->prepare($studentReqs['isOfficial']
            ? "SELECT `requirements_data` FROM `students` WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email LIMIT 1"
            : "SELECT `requirements_data` FROM `pre_enrollments` WHERE `temp_student_id` = :id OR `email` = :email OR `id` = :ref LIMIT 1"
        );
        $rawStmt->execute(['id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        $rawJson = $rawStmt->fetchColumn();
        $rawObj = json_decode((string)$rawJson, true) ?: [];

        if (is_array($rawObj) && (isset($rawObj['docs']) || isset($rawObj['status']))) {
            if (!isset($rawObj['docs'])) $rawObj['docs'] = [];
            $prevEntry = $rawObj['docs'][$docKey] ?? [];
            if (!is_array($prevEntry)) $prevEntry = ['status' => (string)$prevEntry];
            $rawObj['docs'][$docKey] = array_merge($prevEntry, [
                'status'      => strtoupper($status),
                'remarks'     => $remarks ?: null,
                'verifiedAt'  => date('Y-m-d H:i:s'),
                'verifiedBy'  => $verifiedBy,
                'isUndertaking' => (strtoupper($status) === 'UNDERTAKING')
            ]);
            $finalPayload = json_encode($rawObj);
        } else {
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
            $finalPayload = json_encode($reqs);
        }

        if ($studentReqs['isOfficial']) {
            $stmt = $this->pdo->prepare("UPDATE `students` SET `requirements_data` = :reqs WHERE `id` = :id OR `temp_reference_no` = :ref OR `email` = :email");
            $stmt->execute(['reqs' => $finalPayload, 'id' => $cleanId, 'ref' => $cleanId, 'email' => $cleanId]);
        }

        $stmtPe = $this->pdo->prepare("UPDATE `pre_enrollments` SET `requirements_data` = :reqs WHERE `temp_student_id` = :ref OR `email` = :email OR `id` = :id");
        $stmtPe->execute(['reqs' => $finalPayload, 'ref' => $cleanId, 'email' => $cleanId, 'id' => $cleanId]);

        return $this->getStudentRequirements($cleanId);
    }
}
