<?php
/**
 * Migration: Migrate JSON Financial & Clearance Data to Relational Tables
 *
 * Parses existing `payment_data`, `roadmap`, `medical_data`, `requirements_data`,
 * and `helpdesk_data` JSON records from `pre_enrollments` and `students`.
 * Idempotently populates `payments`, `official_receipts`, and `student_clearances`.
 */

require_once __DIR__ . '/../../shared/backend/config/database.php';

try {
    $pdo = Database::getInstance();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Starting Data Migration: migrate_financial_ledger...\n";

    $pdo->beginTransaction();

    // 1. Fetch all pre_enrollments records with payment_data or roadmap
    $peStmt = $pdo->query("
        SELECT `id`, `temp_student_id`, `existing_student_id`, `first_name`, `last_name`, 
               `payment_data`, `roadmap`, `requirements_data`, `medical_data`, 
               `helpdesk_data`, `or_number`, `cashier_name`, `created_at`
        FROM `pre_enrollments`
    ");
    $peRows = $peStmt->fetchAll(PDO::FETCH_ASSOC);

    $migratedPayments = 0;
    $migratedReceipts = 0;
    $migratedClearances = 0;

    $insPay = $pdo->prepare("
        INSERT INTO `payments` 
            (`student_reference`, `student_id`, `amount`, `payment_method`, `payment_type`, `channel`, `transaction_reference`, `official_receipt_number`, `status`, `cashier_username`, `notes`, `paid_at`)
        VALUES 
            (:student_reference, :student_id, :amount, :payment_method, :payment_type, :channel, :transaction_reference, :official_receipt_number, :status, :cashier_username, :notes, :paid_at)
    ");

    $insOr = $pdo->prepare("
        INSERT INTO `official_receipts` 
            (`or_number`, `student_reference`, `student_name`, `amount`, `payment_id`, `cashier_username`, `issued_at`)
        VALUES 
            (:or_number, :student_reference, :student_name, :amount, :payment_id, :cashier_username, :issued_at)
        ON DUPLICATE KEY UPDATE `amount` = VALUES(`amount`)
    ");

    $insClearance = $pdo->prepare("
        INSERT INTO `student_clearances` 
            (`student_reference`, `station_code`, `status`, `verified_by`, `notes`, `clearance_data`, `cleared_at`)
        VALUES 
            (:student_reference, :station_code, :status, :verified_by, :notes, :clearance_data, :cleared_at)
        ON DUPLICATE KEY UPDATE 
            `status` = VALUES(`status`),
            `verified_by` = VALUES(`verified_by`),
            `notes` = VALUES(`notes`),
            `clearance_data` = VALUES(`clearance_data`),
            `cleared_at` = VALUES(`cleared_at`)
    ");

    foreach ($peRows as $row) {
        $ref = $row['temp_student_id'];
        $sid = $row['existing_student_id'] ?: null;
        $name = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));

        // Process payments from payment_data
        if (!empty($row['payment_data'])) {
            $pData = json_decode($row['payment_data'], true);
            if ($pData && !empty($pData['history']) && is_array($pData['history'])) {
                foreach ($pData['history'] as $idx => $txn) {
                    $amount = (float)($txn['amountPaid'] ?? ($txn['amount'] ?? 0.00));
                    if ($amount <= 0) continue;

                    $orNo = $txn['orNumber'] ?? ($pData['orNumber'] ?? ($row['or_number'] ?? null));
                    $txRef = $txn['reference'] ?? ($txn['txRef'] ?? ($ref . '-TXN-' . ($idx + 1)));
                    $method = $txn['paymentMethod'] ?? ($pData['paymentMethod'] ?? 'CASH');
                    $channel = $txn['channel'] ?? ($txn['paymentMethod'] ?? 'CASH');
                    $plan = $txn['plan'] ?? ($pData['plan'] ?? 'DOWNPAYMENT');
                    $cashier = $txn['cashier'] ?? ($txn['processedBy'] ?? ($row['cashier_name'] ?? 'cashier'));
                    $paidAt = $txn['date'] ?? ($txn['paidAt'] ?? ($row['created_at'] ?? date('Y-m-d H:i:s')));

                    // Check if payment already exists
                    $chk = $pdo->prepare("SELECT `id` FROM `payments` WHERE `student_reference` = :ref AND `transaction_reference` = :txref LIMIT 1");
                    $chk->execute(['ref' => $ref, 'txref' => $txRef]);
                    $existingPayId = $chk->fetchColumn();

                    $paymentId = $existingPayId;
                    if (!$existingPayId) {
                        $insPay->execute([
                            'student_reference'       => $ref,
                            'student_id'              => $sid,
                            'amount'                  => $amount,
                            'payment_method'          => $method,
                            'payment_type'            => $plan,
                            'channel'                 => $channel,
                            'transaction_reference'   => $txRef,
                            'official_receipt_number' => $orNo,
                            'status'                  => 'COMPLETED',
                            'cashier_username'        => $cashier,
                            'notes'                   => 'Migrated from payment_data JSON snapshot',
                            'paid_at'                 => $paidAt
                        ]);
                        $paymentId = $pdo->lastInsertId();
                        $migratedPayments++;
                    }

                    // Insert official receipt if OR number present
                    if ($orNo) {
                        $insOr->execute([
                            'or_number'        => $orNo,
                            'student_reference'=> $ref,
                            'student_name'     => $name ?: 'Student ' . $ref,
                            'amount'           => $amount,
                            'payment_id'       => $paymentId,
                            'cashier_username' => $cashier,
                            'issued_at'        => $paidAt
                        ]);
                        $migratedReceipts++;
                    }
                }
            }
        }

        // Process clearances from roadmap
        if (!empty($row['roadmap'])) {
            $roadmap = json_decode($row['roadmap'], true);
            if (is_array($roadmap)) {
                $stationMap = [
                    'registrar_verification' => 'REGISTRAR',
                    'clinic_checkup'         => 'MEDICAL',
                    'helpdesk_unit_eval'     => 'HELPDESK',
                    'cashier_payment'        => 'CASHIER',
                    'it_center_enrollment'   => 'IT_CENTER'
                ];

                foreach ($roadmap as $step) {
                    $stepId = $step['stepId'] ?? '';
                    if (!isset($stationMap[$stepId])) continue;

                    $stationCode = $stationMap[$stepId];
                    $status = $step['status'] ?? 'PENDING';
                    $clearedAt = $step['updatedAt'] ?? ($step['completedAt'] ?? null);

                    $clearancePayload = null;
                    if ($stationCode === 'MEDICAL' && !empty($row['medical_data'])) {
                        $clearancePayload = $row['medical_data'];
                    } elseif ($stationCode === 'HELPDESK' && !empty($row['helpdesk_data'])) {
                        $clearancePayload = $row['helpdesk_data'];
                    } elseif ($stationCode === 'REGISTRAR' && !empty($row['requirements_data'])) {
                        $clearancePayload = $row['requirements_data'];
                    }

                    $insClearance->execute([
                        'student_reference' => $ref,
                        'station_code'      => $stationCode,
                        'status'            => $status,
                        'verified_by'       => $step['verifiedBy'] ?? null,
                        'notes'             => $step['notes'] ?? null,
                        'clearance_data'    => $clearancePayload,
                        'cleared_at'        => $clearedAt
                    ]);
                    $migratedClearances++;
                }
            }
        }
    }

    $pdo->commit();

    echo "Data Migration Completed Successfully!\n";
    echo "  ✓ Migrated Payments: {$migratedPayments}\n";
    echo "  ✓ Migrated Receipts: {$migratedReceipts}\n";
    echo "  ✓ Migrated Clearances: {$migratedClearances}\n\n";

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "❌ Migration failed and rolled back: " . $e->getMessage() . "\n";
    exit(1);
}
