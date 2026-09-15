<?php
/**
 * GNCP Workstations — Station History Service
 * Retrieves station audit logs and completed student action histories.
 */

class StationHistoryService {
    public static function fetchStationHistory(PDO $pdo, string $stationRole, int $limit = 200): array {
        $stationRole = strtoupper(trim($stationRole));
        $normalizedStation = $stationRole;
        if ($stationRole === 'CLINIC') $normalizedStation = 'MEDICAL';
        if ($stationRole === 'TREASURY') $normalizedStation = 'CASHIER';
        if ($stationRole === 'IT') $normalizedStation = 'IT_CENTER';

        $history = [];
        $seenRefs = [];

        // 1. Fetch from audit_logs
        try {
            $stmt = $pdo->prepare("
                SELECT 
                    a.id AS auditId,
                    a.reference_number AS referenceNumber,
                    a.operator_username AS operatorUsername,
                    a.station_role AS stationRole,
                    a.action_performed AS actionPerformed,
                    a.previous_state AS previousState,
                    a.new_state AS newState,
                    a.created_at AS completedAt,
                    COALESCE(s.name, CONCAT(p.first_name, ' ', p.last_name)) AS studentName,
                    COALESCE(s.id, p.temp_student_id) AS studentId,
                    COALESCE(s.program, p.course_code) AS program,
                    COALESCE(s.year_level, p.year_level_applied, '1st Year') AS yearLevel,
                    COALESCE(p.section_code, '') AS sectionCode,
                    COALESCE(s.status, p.status) AS currentStatus
                FROM `audit_logs` a
                LEFT JOIN `pre_enrollments` p ON a.reference_number = p.temp_student_id
                LEFT JOIN `students` s ON (a.reference_number = s.id OR a.reference_number = s.temp_reference_no)
                WHERE (:roleAll = 'ALL' OR a.station_role = :roleParam)
                ORDER BY a.created_at DESC, a.id DESC
                LIMIT :limitVal
            ");
            $stmt->bindValue(':roleAll', $normalizedStation === 'ALL' ? 'ALL' : 'SINGLE');
            $stmt->bindValue(':roleParam', $normalizedStation);
            $stmt->bindValue(':limitVal', (int)$limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($rows as $r) {
                $details = json_decode((string)($r['newState'] ?? '{}'), true) ?: [];
                $history[] = [
                    'auditId'          => (int)$r['auditId'],
                    'referenceNumber'  => $r['referenceNumber'],
                    'studentId'        => $r['studentId'] ?: $r['referenceNumber'],
                    'studentName'      => $r['studentName'] ?: 'Applicant',
                    'program'          => $r['program'] ?: '---',
                    'yearLevel'        => $r['yearLevel'] ?: '1st Year',
                    'sectionCode'      => $r['sectionCode'] ?: ($details['section'] ?? ($details['section_code'] ?? '---')),
                    'stationRole'      => $r['stationRole'],
                    'actionPerformed'  => $r['actionPerformed'],
                    'operatorUsername' => $r['operatorUsername'] ?: ($details['operator'] ?? 'Staff'),
                    'completedAt'      => $r['completedAt'],
                    'currentStatus'    => $r['currentStatus'],
                    'notes'            => $details['notes'] ?? ($details['tlcNotes'] ?? ($details['returnReason'] ?? '')),
                    'details'          => $details
                ];
                $seenRefs[$r['referenceNumber']] = true;
            }
        } catch (Exception $e) {
            // Silently continue to fallback
        }

        // 2. Fallback / supplement: for records reviewed before audit_logs or direct mutations
        if (count($history) < $limit) {
            try {
                $statusFilter = [];
                if ($normalizedStation === 'REGISTRAR') {
                    $statusFilter = ['VERIFIED', 'APPROVED', 'REJECTED', 'RETURNED'];
                } elseif ($normalizedStation === 'HELPDESK') {
                    $statusFilter = ['ADVISED', 'MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'PROMOTED'];
                } elseif ($normalizedStation === 'MEDICAL') {
                    $statusFilter = ['MEDICAL_CLEARED', 'PAID', 'ENROLLED', 'PROMOTED'];
                } elseif ($normalizedStation === 'CASHIER') {
                    $statusFilter = ['PAID', 'ENROLLED', 'PROMOTED'];
                } elseif ($normalizedStation === 'IT_CENTER') {
                    $statusFilter = ['ENROLLED', 'PROMOTED', 'ACTIVE'];
                }

                if (!empty($statusFilter)) {
                    $inPlaceholders = implode(',', array_fill(0, count($statusFilter), '?'));
                    $fbStmt = $pdo->prepare("
                        SELECT `id`, `temp_student_id`, `first_name`, `last_name`, `course_code`, `year_level_applied`,
                               `section_code`, `status`, `requirements_data`, `helpdesk_data`, `medical_data`,
                               `payment_data`, `cashier_name`, `or_number`, `enrolled_at`, `created_at`
                        FROM `pre_enrollments`
                        WHERE `status` IN ({$inPlaceholders})
                        ORDER BY `id` DESC
                        LIMIT 50
                    ");
                    $fbStmt->execute($statusFilter);
                    $fbRows = $fbStmt->fetchAll(PDO::FETCH_ASSOC);

                    foreach ($fbRows as $fr) {
                        $ref = $fr['temp_student_id'];
                        if (isset($seenRefs[$ref])) continue;
                        $seenRefs[$ref] = true;

                        $reqData = json_decode((string)($fr['requirements_data'] ?? ''), true) ?: [];
                        $helpData = json_decode((string)($fr['helpdesk_data'] ?? ''), true) ?: [];
                        $medData = json_decode((string)($fr['medical_data'] ?? ''), true) ?: [];
                        $payData = json_decode((string)($fr['payment_data'] ?? ''), true) ?: [];

                        $operator = 'Staff';
                        $completedAt = $fr['created_at'];
                        $action = 'REVIEW_COMPLETED';

                        if ($normalizedStation === 'REGISTRAR') {
                            $operator = $reqData['verifiedBy'] ?? 'Registrar Staff';
                            $completedAt = $reqData['dateVerified'] ?? $fr['created_at'];
                            $action = $fr['status'] === 'REJECTED' ? 'APPLICATION_REJECTED' : 'REQUIREMENTS_VERIFIED';
                        } elseif ($normalizedStation === 'HELPDESK') {
                            $operator = $helpData['advisedBy'] ?? 'Academic Advisor';
                            $completedAt = $helpData['dateAdvised'] ?? $fr['created_at'];
                            $action = 'ACADEMIC_ADVISED';
                        } elseif ($normalizedStation === 'MEDICAL') {
                            $operator = $medData['verifiedBy'] ?? 'Medical Clinic Staff';
                            $completedAt = $medData['dateVerified'] ?? $fr['created_at'];
                            $action = 'MEDICAL_CLEARED';
                        } elseif ($normalizedStation === 'CASHIER') {
                            $operator = $fr['cashier_name'] ?? ($payData['processedBy'] ?? 'Cashier');
                            $completedAt = $fr['enrolled_at'] ?? $fr['created_at'];
                            $action = 'PAYMENT_PROCESSED';
                        } elseif ($normalizedStation === 'IT_CENTER') {
                            $operator = 'IT Administrator';
                            $action = 'ACCOUNT_ACTIVATED';
                        }

                        $history[] = [
                            'auditId'          => 0,
                            'referenceNumber'  => $ref,
                            'studentId'        => $ref,
                            'studentName'      => trim($fr['first_name'] . ' ' . $fr['last_name']),
                            'program'          => $fr['course_code'],
                            'yearLevel'        => $fr['year_level_applied'] ?: '1st Year',
                            'sectionCode'      => $fr['section_code'] ?: ($helpData['section'] ?? '---'),
                            'stationRole'      => $normalizedStation,
                            'actionPerformed'  => $action,
                            'operatorUsername' => $operator,
                            'completedAt'      => $completedAt,
                            'currentStatus'    => $fr['status'],
                            'notes'            => $fr['status'],
                            'details'          => []
                        ];
                    }
                }
            } catch (Exception $e) {}
        }

        // Sort entire combined history list strictly by completedAt descending
        usort($history, function($a, $b) {
            $tA = strtotime($a['completedAt'] ?? 0);
            $tB = strtotime($b['completedAt'] ?? 0);
            return $tB <=> $tA;
        });

        return array_slice($history, 0, $limit);
    }
}
