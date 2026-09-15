<?php
/**
 * GNCP Workstations — Enrollment Service
 * Handles transactional student updates, IT center finalization, and photo uploads.
 */

require_once __DIR__ . '/../../../shared/backend/services/AssessmentService.php';
require_once __DIR__ . '/PaymentService.php';

class EnrollmentService {
    public static function updateStudent(PDO $pdo, array $payload) {
        $refNo = $payload['referenceNumber'] ?? '';
        $updateData = $payload['updateData'] ?? null;

        if (!$refNo || !$updateData) {
            throw new InvalidArgumentException('Invalid payload or missing update details.');
        }

        // Fetch existing record first
        $checkExist = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $checkExist->execute(['ref' => $refNo]);
        $existingRecord = $checkExist->fetch(PDO::FETCH_ASSOC);

        if (!$existingRecord) {
            // Check permanent students directory with row lock
            $pdo->beginTransaction();
            try {
                $checkStudent = $pdo->prepare("SELECT * FROM `students` WHERE `id` = :r1 OR `temp_reference_no` = :r2 FOR UPDATE");
                $checkStudent->execute(['r1' => $refNo, 'r2' => $refNo]);
                $studentInfo = $checkStudent->fetch(PDO::FETCH_ASSOC);

                if ($studentInfo) {
                    $studentSets = [];
                    $studentParams = ['ref1' => $refNo, 'ref2' => $refNo];

                    if (isset($updateData['roadmap'])) {
                        $studentSets[] = "`roadmap` = :roadmap";
                        $studentParams['roadmap'] = json_encode($updateData['roadmap']);
                    }
                    if (isset($updateData['medical'])) {
                        $studentSets[] = "`medical_data` = :medical_data";
                        $studentParams['medical_data'] = json_encode($updateData['medical']);
                    }
                    if (isset($updateData['requirements'])) {
                        $studentSets[] = "`requirements_data` = :requirements_data";
                        $studentParams['requirements_data'] = json_encode($updateData['requirements']);
                    }
                    if (isset($updateData['payment'])) {
                        $studentSets[] = "`payment_data` = :payment_data";
                        $studentParams['payment_data'] = json_encode($updateData['payment']);
                    }
                    if (isset($updateData['helpdesk'])) {
                        $studentSets[] = "`helpdesk_data` = :helpdesk_data";
                        $studentParams['helpdesk_data'] = json_encode($updateData['helpdesk']);
                    }
                    if (isset($updateData['enrollment'])) {
                        $studentSets[] = "`enrollment_data` = :enrollment_data";
                        $studentParams['enrollment_data'] = json_encode($updateData['enrollment']);
                    }

                    if (!empty($studentSets)) {
                        $sqlStud = "UPDATE `students` SET " . implode(', ', $studentSets) . " WHERE `temp_reference_no` = :ref1 OR `id` = :ref2";
                        $stmtStud = $pdo->prepare($sqlStud);
                        $stmtStud->execute($studentParams);
                    }
                    $pdo->commit();
                    return ['referenceNumber' => $refNo, 'status' => $studentInfo['status']];
                }
                $pdo->rollBack();
            } catch (Exception $studEx) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $studEx;
            }
            throw new RuntimeException("Student record not found for: $refNo");
        }

        if (strcasecmp($existingRecord['status'], 'Rejected') === 0) {
            throw new DomainException('This application has been permanently rejected.');
        }

        // Validate payment eligibility if actual cashier payment transaction is being processed
        if (isset($updateData['payment'])) {
            $pPayload = $updateData['payment'];
            $isActualPayment = (isset($pPayload['amountPaid']) && (float)$pPayload['amountPaid'] > 0) ||
                               in_array(strtoupper($pPayload['status'] ?? ''), ['PAID', 'PARTIAL', 'COMPLETED'], true) ||
                               !empty($updateData['or_number']);
            if ($isActualPayment) {
                PaymentService::validatePaymentEligibility($existingRecord);
            }
        }

        // Roadmap Normalization and Sequential Validation
        $roadmapSteps = $updateData['roadmap'] ?? json_decode($existingRecord['roadmap'] ?? '[]', true) ?? [];
        $incomingStatus = $updateData['status'] ?? ($updateData['enrollment']['status'] ?? (isset($updateData['enrollment']) ? 'ENROLLED' : null));
        $currentDbStatus = strtoupper($existingRecord['status'] ?? '');
        $targetStatus = !empty($incomingStatus) ? strtoupper($incomingStatus) : $currentDbStatus;

        // Auto-complete preceding steps and unlock downstream steps based on stage advancement
        $stageOrder = ['PRE_REGISTERED' => 0, 'VERIFIED' => 1, 'APPROVED' => 1, 'ADVISED' => 2, 'MEDICAL_CLEARED' => 3, 'PAID' => 4, 'ENROLLED' => 5];
        $currentStageLevel = $stageOrder[$targetStatus] ?? 0;

        foreach ($roadmapSteps as &$step) {
            $sid = $step['stepId'] ?? '';
            // Online Pre-Registration
            if (in_array($sid, ['online_prereg', 'online_registration']) && $currentStageLevel >= 1) {
                if (($step['status'] ?? '') !== 'COMPLETED') {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
            // Registrar Verification
            if ($sid === 'registrar_verification') {
                if ($currentStageLevel >= 1 && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', ''])) {
                    $step['status'] = $currentStageLevel >= 2 ? 'COMPLETED' : 'IN_PROGRESS';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                } elseif ($currentStageLevel >= 2) {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
            // Academic Advising
            if (in_array($sid, ['advising_assessment', 'academic_advising'])) {
                if ($currentStageLevel === 1 && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', ''])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                } elseif ($currentStageLevel >= 2) {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
            // Clinic Medical Clearance
            if (in_array($sid, ['clinic_checkup', 'medical_checkup'])) {
                if ($currentStageLevel === 2 && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', ''])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                } elseif ($currentStageLevel >= 3) {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
            // Cashier Payment
            if (in_array($sid, ['cashier_payment'])) {
                if ($currentStageLevel === 3 && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', ''])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                } elseif ($currentStageLevel >= 4) {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
            // IT Center Account Activation
            if (in_array($sid, ['it_activation', 'id_email_final'])) {
                if ($currentStageLevel === 4 && in_array(strtoupper($step['status'] ?? ''), ['PENDING', 'LOCKED', ''])) {
                    $step['status'] = 'IN_PROGRESS';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                } elseif ($currentStageLevel >= 5) {
                    $step['status'] = 'COMPLETED';
                    $step['updatedAt'] = $step['updatedAt'] ?? date('c');
                }
            }
        }
        unset($step);

        $allDone = !empty($roadmapSteps);
        foreach ($roadmapSteps as $step) {
            $statusVal = strtoupper($step['status'] ?? '');
            if ($statusVal !== 'COMPLETED' && $statusVal !== 'SKIPPED') {
                $allDone = false;
                break;
            }
        }

        $overallStatus = $existingRecord['status'];
        if (!empty($incomingStatus)) {
            $overallStatus = $incomingStatus;
        }
        if ($targetStatus === 'ENROLLED' || (isset($updateData['enrollment']) && in_array(strtoupper($updateData['enrollment']['status'] ?? ''), ['ENROLLED', 'ACTIVE'], true))) {
            $overallStatus = 'ENROLLED';
        }

        $roadmapJson = !empty($roadmapSteps) ? json_encode($roadmapSteps) : $existingRecord['roadmap'];
        $enrollmentJson = isset($updateData['enrollment']) ? json_encode($updateData['enrollment']) : $existingRecord['enrollment_data'];

        // Begin atomic PDO transaction
        $pdo->beginTransaction();

        try {
            // Row-level lock to prevent concurrent update race conditions
            $lockStmt = $pdo->prepare("SELECT `id` FROM `pre_enrollments` WHERE `temp_student_id` = :lock_ref FOR UPDATE");
            $lockStmt->execute(['lock_ref' => $refNo]);

            $sets = [];
            $params = ['ref' => $refNo];

            if (isset($updateData['roadmap'])) {
                $sets[] = "`roadmap` = :roadmap";
                $params['roadmap'] = $roadmapJson;
            }
            if (isset($updateData['requirements'])) {
                $sets[] = "`requirements_data` = :requirements_data";
                $params['requirements_data'] = json_encode($updateData['requirements']);
            }
            if (isset($updateData['medical'])) {
                $sets[] = "`medical_data` = :medical_data";
                $params['medical_data'] = json_encode($updateData['medical']);
            }
            if (isset($updateData['scholarship'])) {
                $sets[] = "`scholarship_data` = :scholarship_data";
                $params['scholarship_data'] = json_encode($updateData['scholarship']);
            }

            $paymentDataToSave = null;
            if (isset($updateData['payment'])) {
                $paymentPayload = $updateData['payment'];
                if (empty($paymentPayload['assessmentSnapshot'])) {
                    $helpdesk = json_decode($existingRecord['helpdesk_data'] ?? '{}', true) ?: [];
                    $rawAdvised = $helpdesk['advisedSubjects'] ?? [];
                    $cleanAdvised = [];
                    $seenCodes = [];
                    foreach ($rawAdvised as $sub) {
                        $code = strtoupper(trim($sub['code'] ?? $sub['subject'] ?? ''));
                        if ($code !== '' && !isset($seenCodes[$code])) {
                            $seenCodes[$code] = true;
                            $cleanAdvised[] = $sub;
                        } elseif ($code === '') {
                            $cleanAdvised[] = $sub;
                        }
                    }
                    $advisedSubjects = $cleanAdvised;
                    $nstp = strtoupper($existingRecord['nstp'] ?? 'NONE');
                    $scholarshipData = json_decode($existingRecord['scholarship_data'] ?? '{}', true) ?: [];
                    $discount = (float)($scholarshipData['discount'] ?? 0.00);

                    $paymentPayload['assessmentSnapshot'] = AssessmentService::calculateAssessment($pdo, $advisedSubjects, $nstp, $discount);
                    $updateData['payment'] = $paymentPayload;
                }
                $paymentDataToSave = $updateData['payment'];
            }
            if (isset($updateData['helpdesk'])) {
                $rawAdvised = $updateData['helpdesk']['advisedSubjects'] ?? [];
                $cleanAdvised = [];
                $seenCodes = [];
                foreach ($rawAdvised as $sub) {
                    $code = strtoupper(trim($sub['code'] ?? $sub['subject'] ?? ''));
                    if ($code !== '' && !isset($seenCodes[$code])) {
                        $seenCodes[$code] = true;
                        $cleanAdvised[] = $sub;
                    } elseif ($code === '') {
                        $cleanAdvised[] = $sub;
                    }
                }
                $updateData['helpdesk']['advisedSubjects'] = $cleanAdvised;

                $sets[] = "`helpdesk_data` = :helpdesk_data";
                $params['helpdesk_data'] = json_encode($updateData['helpdesk']);
                
                $scholarshipName = $updateData['helpdesk']['scholarshipName'] ?? 'NONE';
                $sets[] = "`scholarship` = :scholarship";
                $params['scholarship'] = $scholarshipName;

                // Freeze assessment snapshot immediately upon Academic Advising (Pre-Payment Protection)
                $advisedSubjects = $cleanAdvised;
                $nstp = strtoupper($existingRecord['nstp'] ?? 'NONE');
                $scholarshipData = json_decode($existingRecord['scholarship_data'] ?? '{}', true) ?: [];
                $discount = (float)($scholarshipData['discount'] ?? 0.00);

                if ($paymentDataToSave === null) {
                    $existingPayment = json_decode($existingRecord['payment_data'] ?? '{}', true) ?: [];
                    if (empty($existingPayment['assessmentSnapshot']) && !empty($advisedSubjects)) {
                        $existingPayment['assessmentSnapshot'] = AssessmentService::calculateAssessment($pdo, $advisedSubjects, $nstp, $discount);
                        $paymentDataToSave = $existingPayment;
                    }
                }
            }
            if ($paymentDataToSave !== null) {
                $sets[] = "`payment_data` = :payment_data";
                $params['payment_data'] = json_encode($paymentDataToSave);
            }
            if (isset($updateData['enrollment'])) {
                $sets[] = "`enrollment_data` = :enrollment_data";
                $params['enrollment_data'] = $enrollmentJson;
            }
            if (isset($updateData['section_code'])) {
                $sets[] = "`section_code` = :section_code";
                $params['section_code'] = $updateData['section_code'];
            }
            if (isset($updateData['status'])) {
                $sets[] = "`status` = :status";
                $params['status'] = $overallStatus;
            } elseif ($allDone) {
                $sets[] = "`status` = :status";
                $params['status'] = 'ENROLLED';
            }

            if (!empty($sets)) {
                $sql = "UPDATE `pre_enrollments` SET " . implode(', ', $sets) . " WHERE `temp_student_id` = :ref";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                // Synchronize updates to permanent students directory if student record exists
                $studentSets = [];
                $studentParams = ['ref1' => $refNo, 'ref2' => $refNo];

                if (isset($updateData['roadmap'])) {
                    $studentSets[] = "`roadmap` = :roadmap";
                    $studentParams['roadmap'] = $roadmapJson;
                }
                if (isset($updateData['medical'])) {
                    $studentSets[] = "`medical_data` = :medical_data";
                    $studentParams['medical_data'] = json_encode($updateData['medical']);
                }
                if (isset($updateData['requirements'])) {
                    $studentSets[] = "`requirements_data` = :requirements_data";
                    $studentParams['requirements_data'] = json_encode($updateData['requirements']);
                }
                if (isset($updateData['payment'])) {
                    $studentSets[] = "`payment_data` = :payment_data";
                    $studentParams['payment_data'] = json_encode($updateData['payment']);
                }
                if (isset($updateData['helpdesk'])) {
                    $studentSets[] = "`helpdesk_data` = :helpdesk_data";
                    $studentParams['helpdesk_data'] = json_encode($updateData['helpdesk']);
                }

                if (!empty($studentSets)) {
                    $sqlStud = "UPDATE `students` SET " . implode(', ', $studentSets) . " WHERE `temp_reference_no` = :ref1 OR `id` = :ref2";
                    $stmtStud = $pdo->prepare($sqlStud);
                    $stmtStud->execute($studentParams);
                }
            }

            // Automatically create or update permanent student directory record upon IT Center activation
            $resData = ['referenceNumber' => $refNo, 'status' => $overallStatus];
            if ($overallStatus === 'ENROLLED') {
                $fetchStmt = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
                $fetchStmt->execute(['ref' => $refNo]);
                $appDetails = $fetchStmt->fetch(PDO::FETCH_ASSOC);

                if ($appDetails) {
                    $itData = json_decode((string)($enrollmentJson ?? ''), true) ?: [];
                    $promoResult = promotePreEnrollmentToStudent($pdo, $appDetails, $refNo, $roadmapJson, $itData);

                    $wasAlreadyEnrolled = ($existingRecord && $existingRecord['status'] === 'ENROLLED');

                    if (!$wasAlreadyEnrolled) {
                        $assignedSections = $itData['sections'] ?? [];
                        if (!empty($assignedSections) && is_array($assignedSections)) {
                            foreach ($assignedSections as $secCode) {
                                $upSecStmt = $pdo->prepare("UPDATE `subject_sections` SET `capacity` = GREATEST(0, `capacity` - 1) WHERE `code` = :code");
                                $upSecStmt->execute(['code' => $secCode]);
                            }
                        }
                    }

                    $resData['permanentId']        = $promoResult['permanentId'] ?? '';
                    $resData['institutionalEmail'] = $promoResult['institutionalEmail'] ?? '';
                    $resData['password']           = $promoResult['password'] ?? '';
                }
            }

            // Audit Trail: Record workstation mutation in audit_logs table
            $sessionUser = $_SESSION['gncp_admin_user']['username'] ?? $_SESSION['gncp_station_user']['username'] ?? 'SYSTEM';
            $sessionRole = $_SESSION['gncp_admin_user']['role'] ?? $_SESSION['gncp_station_user']['role'] ?? 'WORKSTATION';
            $auditStmt = $pdo->prepare("
                INSERT INTO `audit_logs` (`reference_number`, `operator_username`, `station_role`, `action_performed`, `previous_state`, `new_state`)
                VALUES (:ref, :operator, :role, :action, :prev, :new)
            ");
            $auditStmt->execute([
                'ref'      => $refNo,
                'operator' => $sessionUser,
                'role'     => $sessionRole,
                'action'   => 'UPDATE_STUDENT_MILESTONE',
                'prev'     => json_encode($existingRecord['status'] ?? 'UNKNOWN'),
                'new'      => json_encode($resData)
            ]);

            // Synchronize Relational Financial & Clearance Ledgers
            try {
                if (isset($updateData['payment'])) {
                    $pInfo = $updateData['payment'];
                    $latestTxn = null;
                    if (!empty($pInfo['history']) && is_array($pInfo['history'])) {
                        $latestTxn = end($pInfo['history']);
                    }
                    $payAmt = (float)($latestTxn['amountPaid'] ?? ($latestTxn['amount'] ?? ($pInfo['amountPaid'] ?? 0.00)));
                    $orNo = $pInfo['orNumber'] ?? ($pInfo['or_number'] ?? ($latestTxn['orNumber'] ?? null));
                    $method = $pInfo['paymentMethod'] ?? ($latestTxn['paymentMethod'] ?? 'CASH');
                    $channel = $pInfo['channel'] ?? ($latestTxn['channel'] ?? $method);
                    $plan = $pInfo['plan'] ?? ($latestTxn['plan'] ?? 'DOWNPAYMENT');
                    $cashierUser = $pInfo['processedBy'] ?? ($latestTxn['cashier'] ?? $sessionUser);
                    $txRef = $latestTxn['reference'] ?? ($pInfo['transactionRef'] ?? ('OTC-' . date('Ymd') . '-' . substr(uniqid(), -5)));

                    if ($payAmt > 0) {
                        $insPayStmt = $pdo->prepare("
                            INSERT INTO `payments` 
                                (`student_reference`, `student_id`, `amount`, `payment_method`, `payment_type`, `channel`, `transaction_reference`, `official_receipt_number`, `status`, `cashier_username`, `notes`, `paid_at`)
                            VALUES 
                                (:ref, :sid, :amt, :method, :plan, :channel, :txref, :or_no, 'COMPLETED', :cashier, 'OTC Cashier Payment', NOW())
                        ");
                        $insPayStmt->execute([
                            ':ref'     => $refNo,
                            ':sid'     => $resData['permanentId'] ?? null,
                            ':amt'     => $payAmt,
                            ':method'  => $method,
                            ':plan'    => $plan,
                            ':channel' => $channel,
                            ':txref'   => $txRef,
                            ':or_no'   => $orNo,
                            ':cashier' => $cashierUser
                        ]);
                        $newPayId = $pdo->lastInsertId();

                        if ($orNo) {
                            $studName = trim(($existingRecord['first_name'] ?? '') . ' ' . ($existingRecord['last_name'] ?? ''));
                            $insOrStmt = $pdo->prepare("
                                INSERT INTO `official_receipts` 
                                    (`or_number`, `student_reference`, `student_name`, `amount`, `payment_id`, `cashier_username`, `issued_at`)
                                VALUES 
                                    (:or_no, :ref, :name, :amt, :pay_id, :cashier, NOW())
                                ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`)
                            ");
                            $insOrStmt->execute([
                                ':or_no'   => $orNo,
                                ':ref'     => $refNo,
                                ':name'    => $studName ?: 'Student ' . $refNo,
                                ':amt'     => $payAmt,
                                ':pay_id'  => $newPayId,
                                ':cashier' => $cashierUser
                            ]);
                        }
                    }

                    // Cashier clearance
                    $insClear = $pdo->prepare("
                        INSERT INTO `student_clearances` (`student_reference`, `station_code`, `status`, `verified_by`, `cleared_at`)
                        VALUES (:ref, 'CASHIER', 'COMPLETED', :vby, NOW())
                        ON DUPLICATE KEY UPDATE `status` = 'COMPLETED', `verified_by` = VALUES(`verified_by`), `cleared_at` = NOW()
                    ");
                    $insClear->execute([':ref' => $refNo, ':vby' => $cashierUser]);
                }

                if (isset($updateData['medical'])) {
                    $mInfo = $updateData['medical'];
                    $vBy = $mInfo['verifiedBy'] ?? $sessionUser;
                    $insClear = $pdo->prepare("
                        INSERT INTO `student_clearances` (`student_reference`, `station_code`, `status`, `verified_by`, `clearance_data`, `cleared_at`)
                        VALUES (:ref, 'MEDICAL', 'COMPLETED', :vby, :cdata, NOW())
                        ON DUPLICATE KEY UPDATE `status` = 'COMPLETED', `verified_by` = VALUES(`verified_by`), `clearance_data` = VALUES(`clearance_data`), `cleared_at` = NOW()
                    ");
                    $insClear->execute([':ref' => $refNo, ':vby' => $vBy, ':cdata' => json_encode($mInfo)]);
                }

                if (isset($updateData['helpdesk'])) {
                    $hInfo = $updateData['helpdesk'];
                    $vBy = $hInfo['verifiedBy'] ?? $sessionUser;
                    $insClear = $pdo->prepare("
                        INSERT INTO `student_clearances` (`student_reference`, `station_code`, `status`, `verified_by`, `clearance_data`, `cleared_at`)
                        VALUES (:ref, 'HELPDESK', 'COMPLETED', :vby, :cdata, NOW())
                        ON DUPLICATE KEY UPDATE `status` = 'COMPLETED', `verified_by` = VALUES(`verified_by`), `clearance_data` = VALUES(`clearance_data`), `cleared_at` = NOW()
                    ");
                    $insClear->execute([':ref' => $refNo, ':vby' => $vBy, ':cdata' => json_encode($hInfo)]);
                }

                if (isset($updateData['requirements'])) {
                    $rInfo = $updateData['requirements'];
                    $vBy = $rInfo['verifiedBy'] ?? $sessionUser;
                    $insClear = $pdo->prepare("
                        INSERT INTO `student_clearances` (`student_reference`, `station_code`, `status`, `verified_by`, `clearance_data`, `cleared_at`)
                        VALUES (:ref, 'REGISTRAR', 'COMPLETED', :vby, :cdata, NOW())
                        ON DUPLICATE KEY UPDATE `status` = 'COMPLETED', `verified_by` = VALUES(`verified_by`), `clearance_data` = VALUES(`clearance_data`), `cleared_at` = NOW()
                    ");
                    $insClear->execute([':ref' => $refNo, ':vby' => $vBy, ':cdata' => json_encode($rInfo)]);
                }

                if ($overallStatus === 'ENROLLED') {
                    $insClear = $pdo->prepare("
                        INSERT INTO `student_clearances` (`student_reference`, `station_code`, `status`, `verified_by`, `cleared_at`)
                        VALUES (:ref, 'IT_CENTER', 'COMPLETED', :vby, NOW())
                        ON DUPLICATE KEY UPDATE `status` = 'COMPLETED', `verified_by` = VALUES(`verified_by`), `cleared_at` = NOW()
                    ");
                    $insClear->execute([':ref' => $refNo, ':vby' => $sessionUser]);
                }
            } catch (Exception $syncEx) {
                error_log('[EnrollmentService::RelationalSync] ' . $syncEx->getMessage());
            }

            // Commit atomic transaction
            $pdo->commit();
            return $resData;


        } catch (Exception $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function uploadPhoto(array $payload) {
        $refNo       = trim($payload['referenceNumber'] ?? '');
        $base64Data  = $payload['photoData'] ?? '';
        $fileName    = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $payload['fileName'] ?? 'portrait.png');

        if (!$refNo || !$base64Data) {
            throw new InvalidArgumentException('referenceNumber and photoData are required.');
        }

        if (preg_match('/^data:image\/\w+;base64,/', $base64Data)) {
            $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $base64Data);
        }

        $imageData = base64_decode($base64Data);
        if ($imageData === false || strlen($imageData) === 0) {
            throw new InvalidArgumentException('Invalid base64 image data.');
        }

        // Maximum size limit: 5MB
        if (strlen($imageData) > 5 * 1024 * 1024) {
            throw new InvalidArgumentException('Portrait image exceeds maximum allowed size of 5MB.');
        }

        // Validate image signature & MIME
        $imgInfo = @getimagesizefromstring($imageData);
        if (!$imgInfo || !in_array($imgInfo[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            throw new InvalidArgumentException('Invalid image format. Only legitimate PNG, JPEG, or WebP images are permitted.');
        }

        // Detect embedded polyglot or executable script patterns
        if (preg_match('/<\?php|<\?=|<script\b|eval\s*\(|base64_decode\s*\(/i', $imageData)) {
            throw new RuntimeException('Security Error: Malicious executable or script patterns detected inside image.');
        }

        $uploadDir = __DIR__ . '/../../../uploads/portraits/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $safeRef   = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $refNo);
        $finalName = 'portrait_' . $safeRef . '_' . time() . '.png';
        $filePath  = $uploadDir . $finalName;

        if (file_put_contents($filePath, $imageData) === false) {
            throw new RuntimeException('Failed to write portrait file to disk.');
        }

        $appBase = (isset($_SERVER['SCRIPT_NAME']) && preg_match('#^/([^/]+)#', $_SERVER['SCRIPT_NAME'], $m)) ? '/' . $m[1] : '';
        $webPath = $appBase . '/uploads/portraits/' . $finalName;

        return [
            'referenceNumber' => $refNo,
            'fileName'        => $finalName,
            'webPath'         => $webPath
        ];
    }
}
