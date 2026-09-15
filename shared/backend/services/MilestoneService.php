<?php
/**
 * MilestoneService — Domain service for academic milestones and calendar deadlines
 */
require_once __DIR__ . '/../utils/logger.php';

class MilestoneService {

    public static function checkAdminAuth(): ?array {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $sessionUser = null;
        if (!empty($_SESSION['gncp_admin_user'])) {
            $raw = $_SESSION['gncp_admin_user'];
            $sessionUser = is_array($raw) ? $raw : json_decode($raw, true);
        } elseif (!empty($_SESSION['gncp_station_user'])) {
            $raw = $_SESSION['gncp_station_user'];
            $stationUser = is_array($raw) ? $raw : json_decode($raw, true);
            if (is_array($stationUser) && in_array(strtoupper($stationUser['role'] ?? ''), ['ADMIN', 'SUPER_ADMIN'])) {
                $sessionUser = $stationUser;
            }
        }
        return is_array($sessionUser) ? $sessionUser : null;
    }

    public static function getMilestones(PDO $pdo, array $filters = []): array {
        try {
            $sql = "SELECT m.id, m.academic_period_id, m.title, m.date_start, m.date_end, 
                           m.date_display, m.status, m.display_order, m.created_at, m.updated_at,
                           p.name AS period_name, p.status AS period_status
                    FROM academic_milestones m
                    LEFT JOIN academic_periods p ON m.academic_period_id = p.id ";
            $where = [];
            $params = [];

            if (!empty($filters['academic_period_id'])) {
                $where[] = "m.academic_period_id = :period_id";
                $params[':period_id'] = (int)$filters['academic_period_id'];
            }

            if (!empty($filters['status'])) {
                $where[] = "m.status = :status";
                $params[':status'] = strtoupper($filters['status']);
            }

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY m.display_order ASC, m.id ASC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $milestones = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Format items cleanly
            $formatted = [];
            foreach ($milestones as $r) {
                $display = $r['date_display'];
                if (empty($display) && (!empty($r['date_start']) || !empty($r['date_end']))) {
                    $display = self::formatDateRange($r['date_start'], $r['date_end']);
                }
                $formatted[] = [
                    'id'                 => (int)$r['id'],
                    'academic_period_id' => $r['academic_period_id'] !== null ? (int)$r['academic_period_id'] : null,
                    'period_name'        => $r['period_name'] ?? 'All Periods',
                    'period_status'      => $r['period_status'] ?? '',
                    'title'              => $r['title'],
                    'date_start'         => $r['date_start'],
                    'date_end'           => $r['date_end'],
                    'date_display'       => $display ?: 'TBA',
                    'status'             => $r['status'],
                    'display_order'      => (int)$r['display_order']
                ];
            }

            return [
                'success' => true,
                'data'    => $formatted,
                'count'   => count($formatted)
            ];
        } catch (Exception $e) {
            logAppError("MilestoneService::getMilestones Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to fetch academic milestones.', 'code' => 500];
        }
    }

    public static function saveMilestone(PDO $pdo, array $payload): array {
        $admin = self::checkAdminAuth();
        if (!$admin) {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrative accounts can manage academic milestones.', 'code' => 403];
        }

        $id          = isset($payload['id']) ? (int)$payload['id'] : 0;
        $periodId    = !empty($payload['academic_period_id']) ? (int)$payload['academic_period_id'] : null;
        $title       = trim($payload['title'] ?? '');
        $dateStart   = !empty($payload['date_start']) ? trim($payload['date_start']) : null;
        $dateEnd     = !empty($payload['date_end']) ? trim($payload['date_end']) : null;
        $dateDisplay = trim($payload['date_display'] ?? '');
        $status      = strtoupper(trim($payload['status'] ?? 'SCHEDULED'));
        $order       = isset($payload['display_order']) ? (int)$payload['display_order'] : 0;

        if (empty($title)) {
            return ['success' => false, 'message' => 'Milestone title is required.', 'code' => 400];
        }

        if (!empty($dateStart) && !empty($dateEnd) && $dateEnd < $dateStart) {
            return ['success' => false, 'message' => 'Milestone end date cannot be earlier than start date.', 'code' => 400];
        }

        $validStatuses = ['ACTIVE', 'UPCOMING', 'SCHEDULED', 'COMPLETED'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'SCHEDULED';
        }

        // Auto-generate date display if empty
        if (empty($dateDisplay) && ($dateStart || $dateEnd)) {
            $dateDisplay = self::formatDateRange($dateStart, $dateEnd);
        }

        try {
            if ($id > 0) {
                $sql = "UPDATE academic_milestones 
                        SET academic_period_id = :period_id, title = :title, date_start = :date_start, 
                            date_end = :date_end, date_display = :date_display, status = :status, 
                            display_order = :display_order, updated_at = NOW() 
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':period_id'     => $periodId,
                    ':title'         => $title,
                    ':date_start'    => $dateStart,
                    ':date_end'      => $dateEnd,
                    ':date_display'  => !empty($dateDisplay) ? $dateDisplay : null,
                    ':status'        => $status,
                    ':display_order' => $order,
                    ':id'            => $id
                ]);
                $msg = 'Academic milestone updated successfully.';
            } else {
                if ($order <= 0) {
                    $stmtMax = $pdo->query("SELECT COALESCE(MAX(display_order), 0) + 1 FROM academic_milestones");
                    $order = (int)$stmtMax->fetchColumn();
                }

                $sql = "INSERT INTO academic_milestones (academic_period_id, title, date_start, date_end, date_display, status, display_order) 
                        VALUES (:period_id, :title, :date_start, :date_end, :date_display, :status, :display_order)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':period_id'     => $periodId,
                    ':title'         => $title,
                    ':date_start'    => $dateStart,
                    ':date_end'      => $dateEnd,
                    ':date_display'  => !empty($dateDisplay) ? $dateDisplay : null,
                    ':status'        => $status,
                    ':display_order' => $order
                ]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Academic milestone created successfully.';
            }

            return ['success' => true, 'message' => $msg, 'id' => $id];
        } catch (Exception $e) {
            logAppError("MilestoneService::saveMilestone Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save academic milestone.', 'code' => 500];
        }
    }

    public static function deleteMilestone(PDO $pdo, array $payload): array {
        $admin = self::checkAdminAuth();
        if (!$admin) {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrative accounts can delete academic milestones.', 'code' => 403];
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'message' => 'Invalid milestone ID.', 'code' => 400];
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM academic_milestones WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return ['success' => true, 'message' => 'Academic milestone deleted successfully.'];
        } catch (Exception $e) {
            logAppError("MilestoneService::deleteMilestone Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete academic milestone.', 'code' => 500];
        }
    }

    public static function formatDateRange(?string $start, ?string $end): string {
        if (!$start && !$end) return '';
        if ($start && !$end) {
            return date('M d, Y', strtotime($start));
        }
        if (!$start && $end) {
            return date('M d, Y', strtotime($end));
        }
        $tsStart = strtotime($start);
        $tsEnd   = strtotime($end);
        if (date('Y-m', $tsStart) === date('Y-m', $tsEnd)) {
            return date('M d', $tsStart) . ' - ' . date('d, Y', $tsEnd);
        }
        if (date('Y', $tsStart) === date('Y', $tsEnd)) {
            return date('M d', $tsStart) . ' - ' . date('M d, Y', $tsEnd);
        }
        return date('M d, Y', $tsStart) . ' - ' . date('M d, Y', $tsEnd);
    }
}
