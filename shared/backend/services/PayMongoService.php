<?php
/**
 * PayMongo Payment Service
 * Handles Checkout Sessions, Webhook Verification, and Payment Settlement.
 * Complies with Philippine financial rounding and Rule-002 payment invariants.
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/AssessmentService.php';

class PayMongoService {
    private static function getConfig(): array {
        static $config = null;
        if ($config === null) {
            $path = __DIR__ . '/../config/paymongo.php';
            $config = file_exists($path) ? require $path : [];
        }
        return $config;
    }

    /**
     * Creates a PayMongo Checkout Session (or simulated session if in simulation mode).
     *
     * @param string $refNo Student reference number
     * @param float $amount Amount in PHP (will be converted to centavos)
     * @param string $description Fee description
     * @param array $studentData Optional student metadata
     * @return array Checkout session payload
     */
    public static function createCheckoutSession(string $refNo, float $amount, string $description = 'GNCP Tuition & Matriculation Fee', array $studentData = []): array {
        $config = self::getConfig();
        $amount = round($amount, 2);
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be greater than zero.');
        }

        // Validate student status and remaining tuition balance
        try {
            $pdo = Database::getInstance();
            $stmt = $pdo->prepare("SELECT `payment_data`, `status` FROM `pre_enrollments` WHERE `temp_student_id` = :ref1 LIMIT 1");
            $stmt->execute([':ref1' => $refNo]);
            $stud = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$stud) {
                $stmt2 = $pdo->prepare("SELECT `payment_data`, `status` FROM `students` WHERE `temp_reference_no` = :ref2 OR `id` = :sid LIMIT 1");
                $stmt2->execute([':ref2' => $refNo, ':sid' => $refNo]);
                $stud = $stmt2->fetch(PDO::FETCH_ASSOC);
            }

            if ($stud) {
                $st = strtoupper(trim($stud['status'] ?? ''));
                if ($st === 'PRE_REGISTERED' || $st === 'REJECTED') {
                    throw new DomainException("Payment rejected: Student status is {$st}. Must be verified and advised before creating checkout.");
                }
                $pData = !empty($stud['payment_data']) ? (is_array($stud['payment_data']) ? $stud['payment_data'] : json_decode($stud['payment_data'], true)) : [];
                if (isset($pData['balance'])) {
                    $maxBal = (float)$pData['balance'];
                    if ($maxBal > 0 && $amount > round($maxBal + 0.01, 2)) {
                        throw new InvalidArgumentException("Payment amount (PHP " . number_format($amount, 2) . ") exceeds remaining balance (PHP " . number_format($maxBal, 2) . ").");
                    }
                }
            }
        } catch (DomainException $de) {
            throw $de;
        } catch (InvalidArgumentException $ie) {
            throw $ie;
        } catch (Exception $e) {
            error_log('[PayMongoService::BalanceCheck] ' . $e->getMessage());
        }

        $amountInCentavos = (int)round($amount * 100);
        $sessionId = 'cs_test_' . substr(md5($refNo . time() . uniqid()), 0, 24);
        $clientKey = 'cs_' . substr(md5(uniqid()), 0, 16) . '_client_secret';
        $txnRef = 'PM-TXN-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

        if (!empty($config['simulation_mode'])) {
            return [
                'success'           => true,
                'mode'              => 'simulation',
                'sessionId'         => $sessionId,
                'clientKey'         => $clientKey,
                'transactionRef'    => $txnRef,
                'referenceNumber'   => $refNo,
                'amount'            => $amount,
                'amountInCentavos'  => $amountInCentavos,
                'currency'          => 'PHP',
                'description'       => $description,
                'paymentMethods'    => ['gcash', 'paymaya', 'card', 'qrph', 'grab_pay'],
                'checkoutUrl'       => (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/systemtest/') !== false ? '/systemtest' : '') . '/shared/paymongo/checkout.html?session_id=' . urlencode($sessionId) . '&ref=' . urlencode($refNo) . '&amount=' . urlencode((string)$amount) . '&desc=' . urlencode($description),
                'qrPhPayload'       => "00020101021226580014ph.paymongo.qr0111{$sessionId}5204581253036085408{$amount}5802PH5910GNCP_COLLEGE6006MANILA62150111{$refNo}6304ABCD",
                'createdAt'         => date('Y-m-d H:i:s'),
                'expiresAt'         => date('Y-m-d H:i:s', time() + 3600)
            ];
        }

        // Live API integration via cURL
        $payload = [
            'data' => [
                'attributes' => [
                    'billing' => [
                        'name'  => $studentData['name'] ?? 'GNCP Student',
                        'email' => $studentData['email'] ?? 'billing@gncp.edu.ph',
                        'phone' => $studentData['contact'] ?? '09123456789'
                    ],
                    'send_email_receipt'   => true,
                    'show_description'     => true,
                    'show_line_items'      => true,
                    'description'          => $description,
                    'line_items'           => [[
                        'amount'      => $amountInCentavos,
                        'currency'    => 'PHP',
                        'name'        => 'Tuition & Academic Fees',
                        'quantity'    => 1,
                        'description' => "Student: {$refNo}"
                    ]],
                    'payment_method_types' => $config['payment_method_types'],
                    'success_url'          => $config['success_url'] . '&ref=' . $refNo,
                    'cancel_url'           => $config['cancel_url'] . '&ref=' . $refNo,
                    'reference_number'     => $txnRef
                ]
            ]
        ];

        $ch = curl_init($config['api_base_url'] . '/checkout_sessions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Basic ' . base64_encode($config['secret_key'] . ':')
            ],
            CURLOPT_TIMEOUT        => 15
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("PayMongo API error (HTTP {$httpCode}): " . ($err ?: $response));
        }

        $resData = json_decode($response, true);
        return [
            'success'          => true,
            'mode'             => 'live',
            'sessionId'        => $resData['data']['id'] ?? $sessionId,
            'checkoutUrl'      => $resData['data']['attributes']['checkout_url'] ?? '',
            'transactionRef'   => $txnRef,
            'amount'           => $amount,
            'amountInCentavos' => $amountInCentavos
        ];
    }

    /**
     * Settles a PayMongo transaction atomically into the student account ledger.
     * Enforces RULE-002 payment eligibility and ACID consistency.
     */
    public static function processPaymentSuccess(string $refNo, float $payAmount, string $channel = 'GCash', string $txnRef = '', string $cashier = 'PayMongo Gateway', string $notes = 'Online settlement via PayMongo'): array {
        $pdo = Database::getInstance();

        $pdo->beginTransaction();
        try {
            // 1. Check student record in pre_enrollments first, then students with row lock
            $stmt = $pdo->prepare("SELECT `id`, `temp_student_id` AS `reference_number`, `status`, `payment_data`, `roadmap`, `first_name`, `last_name` FROM `pre_enrollments` WHERE `temp_student_id` = :ref1 LIMIT 1 FOR UPDATE");
            $stmt->execute([':ref1' => $refNo]);
            $student = $stmt->fetch(PDO::FETCH_ASSOC);
            $table = 'pre_enrollments';

            if (!$student) {
                $stmt2 = $pdo->prepare("SELECT `id`, `temp_reference_no` AS `reference_number`, `status`, `payment_data`, `roadmap`, `name` FROM `students` WHERE `temp_reference_no` = :ref2 OR `id` = :sid LIMIT 1 FOR UPDATE");
                $stmt2->execute([':ref2' => $refNo, ':sid' => $refNo]);
                $student = $stmt2->fetch(PDO::FETCH_ASSOC);
                $table = 'students';
            }

            if (!$student) {
                $pdo->rollBack();
                throw new RuntimeException("Student record not found for reference '{$refNo}'.");
            }

            // RULE-002: Cashier payments CANNOT be accepted for applicants with status PRE_REGISTERED or REJECTED
            $currentStatus = strtoupper(trim($student['status'] ?? ''));
            if ($currentStatus === 'PRE_REGISTERED' || $currentStatus === 'REJECTED') {
                $pdo->rollBack();
                throw new RuntimeException("Payment rejected: Student status is {$currentStatus}. Must be verified and advised before payment.");
            }

            $paymentData = !empty($student['payment_data']) ? (is_array($student['payment_data']) ? $student['payment_data'] : json_decode($student['payment_data'], true)) : [];
            $roadmap     = !empty($student['roadmap']) ? (is_array($student['roadmap']) ? $student['roadmap'] : json_decode($student['roadmap'], true)) : [];

            $totalFee   = (float)($paymentData['totalFee'] ?? $paymentData['total_fee'] ?? 0.00);
            $amountPaid = (float)($paymentData['amountPaid'] ?? $paymentData['amount_paid'] ?? 0.00);
            $currentBal = isset($paymentData['balance']) ? (float)$paymentData['balance'] : max(0.00, $totalFee - $amountPaid);

            $payAmount = round($payAmount, 2);
            if ($payAmount <= 0) {
                $pdo->rollBack();
                throw new InvalidArgumentException("Payment amount must be greater than zero.");
            }

            if (empty($txnRef)) {
                $txnRef = 'PM-' . strtoupper($channel) . '-' . date('Ymd') . '-' . substr(uniqid(), -5);
            }

            $newAmountPaid = round($amountPaid + $payAmount, 2);
            $newBalance    = max(0.00, round($currentBal - $payAmount, 2));
            $newStatus     = ($newBalance <= 0.00) ? 'PAID' : 'PARTIAL';

            // Update payment history ledger
            if (!isset($paymentData['history']) || !is_array($paymentData['history'])) {
                $paymentData['history'] = [];
            }

            $paymentData['history'][] = [
                'date'        => date('c'),
                'amount'      => $payAmount,
                'reference'   => $txnRef,
                'paymentType' => 'PayMongo (' . strtoupper($channel) . ')',
                'cashier'     => $cashier,
                'notes'       => $notes
            ];

            $paymentData['amountPaid']     = $newAmountPaid;
            $paymentData['balance']        = $newBalance;
            $paymentData['status']         = $newStatus;
            $paymentData['paymentType']    = 'PayMongo (' . strtoupper($channel) . ')';
            $paymentData['transactionRef'] = $txnRef;
            $paymentData['dateVerified']   = date('Y-m-d H:i:s');
            $paymentData['verifiedBy']     = $cashier;

            // Advance Roadmap Step
            if (is_array($roadmap)) {
                $cashierIdx = -1;
                foreach ($roadmap as $idx => $step) {
                    if (($step['stepId'] ?? '') === 'cashier_payment') {
                        $cashierIdx = $idx;
                        break;
                    }
                }

                if ($cashierIdx !== -1) {
                    $roadmap[$cashierIdx]['status'] = 'COMPLETED';
                    $roadmap[$cashierIdx]['updatedAt'] = date('c');

                    // Unlock IT Center Step
                    for ($i = $cashierIdx + 1; $i < count($roadmap); $i++) {
                        if (($roadmap[$i]['status'] ?? '') === 'PENDING') {
                            $roadmap[$i]['status'] = 'IN_PROGRESS';
                            break;
                        }
                    }
                }
            }

            $upd = $pdo->prepare("UPDATE `{$table}` SET `payment_data` = :pdata, `roadmap` = :rmap, `status` = :st WHERE `id` = :id");
            $upd->execute([
                ':pdata' => json_encode($paymentData),
                ':rmap'  => json_encode($roadmap),
                ':st'    => $newStatus,
                ':id'    => $student['id']
            ]);

            // Synchronize into relational payments table
            try {
                $insPay = $pdo->prepare("
                    INSERT INTO `payments` 
                        (`student_reference`, `student_id`, `amount`, `payment_method`, `payment_type`, `channel`, `transaction_reference`, `official_receipt_number`, `status`, `cashier_username`, `notes`, `paid_at`)
                    VALUES 
                        (:student_reference, :student_id, :amount, :payment_method, :payment_type, :channel, :transaction_reference, :official_receipt_number, :status, :cashier_username, :notes, NOW())
                ");
                $insPay->execute([
                    ':student_reference'       => $refNo,
                    ':student_id'              => ($table === 'students') ? $student['id'] : null,
                    ':amount'                  => $payAmount,
                    ':payment_method'          => 'PAYMONGO',
                    ':payment_type'            => 'ONLINE_SETTLEMENT',
                    ':channel'                 => strtoupper($channel),
                    ':transaction_reference'   => $txnRef,
                    ':official_receipt_number' => null,
                    ':status'                  => 'COMPLETED',
                    ':cashier_username'        => $cashier,
                    ':notes'                   => $notes
                ]);

                // Synchronize into relational student_clearances table
                $insClearance = $pdo->prepare("
                    INSERT INTO `student_clearances` 
                        (`student_reference`, `station_code`, `status`, `verified_by`, `notes`, `cleared_at`)
                    VALUES 
                        (:ref, 'CASHIER', 'COMPLETED', :vby, :notes, NOW())
                    ON DUPLICATE KEY UPDATE 
                        `status` = 'COMPLETED',
                        `verified_by` = VALUES(`verified_by`),
                        `notes` = VALUES(`notes`),
                        `cleared_at` = NOW()
                ");
                $insClearance->execute([
                    ':ref'   => $refNo,
                    ':vby'   => $cashier,
                    ':notes' => 'PayMongo online settlement'
                ]);
            } catch (Exception $syncEx) {
                error_log('[PayMongoService::RelationalSync] ' . $syncEx->getMessage());
            }

            $pdo->commit();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return [
            'success'        => true,
            'referenceNumber'=> $refNo,
            'transactionRef' => $txnRef,
            'channel'        => $channel,
            'amountPaid'     => $payAmount,
            'totalPaid'      => $newAmountPaid,
            'balance'        => $newBalance,
            'status'         => $newStatus,
            'isFullPayment'  => ($newBalance <= 0.00)
        ];
    }

    /**
     * Verifies the cryptographic HMAC signature of an incoming PayMongo webhook request.
     *
     * @param string $rawPayload Raw request body string
     * @param string $signatureHeader Value of Paymongo-Signature header (format: t=TIMESTAMP,te=TEST_SIG,li=LIVE_SIG)
     * @return bool True if valid HMAC-SHA256 signature matches
     */
    public static function verifyWebhookSignature(string $rawPayload, string $signatureHeader): bool {
        if (empty($rawPayload) || empty($signatureHeader)) {
            return false;
        }

        $config = self::getConfig();
        $secret = $config['webhook_secret'] ?? '';
        if (empty($secret)) {
            return false;
        }

        // Parse signature header key-value pairs (e.g. t=1614761234,te=abc12345,li=...)
        $parts = explode(',', $signatureHeader);
        $parsed = [];
        foreach ($parts as $part) {
            $kv = explode('=', trim($part), 2);
            if (count($kv) === 2) {
                $parsed[$kv[0]] = $kv[1];
            }
        }

        $timestamp = $parsed['t'] ?? '';
        $testSig   = $parsed['te'] ?? '';
        $liveSig   = $parsed['li'] ?? '';
        $sig       = $parsed['s'] ?? ($testSig ?: $liveSig);

        if (empty($timestamp) || empty($sig)) {
            return false;
        }

        // Prevent replay attacks (reject webhooks older than 10 minutes / 600s)
        if (abs(time() - (int)$timestamp) > 600) {
            return false;
        }

        // PayMongo signature formula: HMAC-SHA256(timestamp . "." . rawPayload, webhookSecret)
        $expectedSignature = hash_hmac('sha256', $timestamp . '.' . $rawPayload, $secret);

        return hash_equals($expectedSignature, $sig);
    }
}
