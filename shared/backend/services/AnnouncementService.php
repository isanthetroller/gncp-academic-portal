<?php
/**
 * AnnouncementService — Domain service for campus announcements & media handling
 */
require_once __DIR__ . '/../utils/logger.php';

class AnnouncementService {

    public static function checkAdminAuth(): ?array {
        // session_write_close() in the API gateway closes write access but not the session data.
        // Re-opening with session_start() re-establishes read access safely.
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

    public static function getAnnouncements(PDO $pdo, array $filters = []): array {
        try {
            $sql = "SELECT id, title, category, content, image_url, author_id, author_name, target_audience, is_pinned, status, created_at, updated_at 
                    FROM announcements ";
            $where = [];
            $params = [];

            if (!empty($filters['status'])) {
                $where[] = "status = :status";
                $params[':status'] = $filters['status'];
            } else {
                if (empty($filters['all'])) {
                    $where[] = "status = 'PUBLISHED'";
                }
            }

            if (!empty($filters['category']) && $filters['category'] !== 'ALL') {
                $where[] = "category = :category";
                $params[':category'] = $filters['category'];
            }

            if (!empty($where)) {
                $sql .= " WHERE " . implode(" AND ", $where);
            }

            $sql .= " ORDER BY is_pinned DESC, created_at DESC";

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data'    => $announcements,
                'count'   => count($announcements)
            ];
        } catch (Exception $e) {
            logAppError("AnnouncementService::getAnnouncements Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to fetch announcements.', 'code' => 500];
        }
    }

    /**
     * Sanitizes rich text HTML content to eliminate XSS vectors while preserving allowed formatting tags.
     */
    public static function sanitizeHtmlContent(string $raw): string {
        if (empty($raw)) return '';

        // Remove script tags and contents
        $clean = preg_replace('/<script\b[^>]*>(.*?)<\/script>/is', '', $raw);
        // Remove iframe, object, embed, form, style, svg tags
        $clean = preg_replace('/<(iframe|object|embed|form|input|button|style|link|meta|svg|math)\b[^>]*>(.*?)<\/\1>/is', '', $clean);
        $clean = preg_replace('/<(iframe|object|embed|form|input|button|style|link|meta|svg|math)\b[^>]*\/?>/is', '', $clean);

        // Strip all dangerous tags except safe whitelisted formatting tags
        $allowedTags = '<p><br><strong><b><em><i><u><ul><ol><li><a><span><h1><h2><h3><h4><h5><h6><blockquote>';
        $clean = strip_tags($clean, $allowedTags);

        // Strip all inline javascript: / data: / vbscript: URI schemes
        $clean = preg_replace('/href\s*=\s*["\']\s*(javascript|data|vbscript):[^"\']*["\']/i', 'href="#"', $clean);

        // Strip any on* event handler attributes (onerror, onclick, onload, etc.)
        $clean = preg_replace('/\s*on[a-zA-Z]+\s*=\s*(["\'][^"\']*["\']|[^\s>]+)/i', '', $clean);

        return trim($clean);
    }

    public static function saveAnnouncement(PDO $pdo, array $payload): array {
        $admin = self::checkAdminAuth();
        if (!$admin) {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrative accounts can post announcements.', 'code' => 403];
        }

        $id             = isset($payload['id']) ? (int)$payload['id'] : 0;
        $title          = trim($payload['title'] ?? '');
        $category       = strtoupper(trim($payload['category'] ?? 'GENERAL'));
        $content        = self::sanitizeHtmlContent($payload['content'] ?? '');
        $imageUrl       = trim($payload['image_url'] ?? '');
        $targetAudience = strtoupper(trim($payload['target_audience'] ?? 'ALL'));
        $isPinned       = !empty($payload['is_pinned']) ? 1 : 0;
        $status         = strtoupper(trim($payload['status'] ?? 'PUBLISHED'));
        $authorName     = !empty($payload['author_name']) ? trim($payload['author_name']) : (!empty($admin['name']) ? $admin['name'] : ($admin['username'] ?? 'Office of Academic Affairs'));
        $authorId       = $admin['id'] ?? null;

        $textOnly = trim(strip_tags(str_replace('&nbsp;', ' ', $content)));
        if (mb_strlen($title) < 3) {
            return ['success' => false, 'message' => 'Announcement title must be at least 3 characters long.', 'code' => 400];
        }
        if (mb_strlen($title) > 150) {
            return ['success' => false, 'message' => 'Announcement title cannot exceed 150 characters.', 'code' => 400];
        }
        if (empty($textOnly) && empty($imageUrl)) {
            return ['success' => false, 'message' => 'Announcement content body or attached banner image is required.', 'code' => 400];
        }

        $allowedCategories = ['GENERAL', 'ACADEMIC', 'FINANCIAL', 'EVENT', 'URGENT', 'FACILITIES'];
        if (!in_array($category, $allowedCategories, true)) {
            $category = 'GENERAL';
        }

        $allowedAudiences = ['ALL', 'STUDENTS', 'OPERATORS', 'FRESHMEN'];
        if (!in_array($targetAudience, $allowedAudiences, true)) {
            $targetAudience = 'ALL';
        }

        try {
            if ($id > 0) {
                $sql = "UPDATE announcements 
                        SET title = :title, category = :category, content = :content, image_url = :image_url, 
                            author_name = :author_name,
                            target_audience = :target_audience, is_pinned = :is_pinned, status = :status, updated_at = NOW() 
                        WHERE id = :id";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':title'           => $title,
                    ':category'        => $category,
                    ':content'         => $content,
                    ':image_url'       => !empty($imageUrl) ? $imageUrl : null,
                    ':author_name'     => $authorName,
                    ':target_audience' => $targetAudience,
                    ':is_pinned'       => $isPinned,
                    ':status'          => $status,
                    ':id'              => $id
                ]);
                $msg = 'Announcement updated successfully.';
            } else {
                $sql = "INSERT INTO announcements (title, category, content, image_url, author_id, author_name, target_audience, is_pinned, status) 
                        VALUES (:title, :category, :content, :image_url, :author_id, :author_name, :target_audience, :is_pinned, :status)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    ':title'           => $title,
                    ':category'        => $category,
                    ':content'         => $content,
                    ':image_url'       => !empty($imageUrl) ? $imageUrl : null,
                    ':author_id'       => $authorId,
                    ':author_name'     => $authorName,
                    ':target_audience' => $targetAudience,
                    ':is_pinned'       => $isPinned,
                    ':status'          => $status
                ]);
                $id = (int)$pdo->lastInsertId();
                $msg = 'Announcement published successfully.';
            }

            return ['success' => true, 'message' => $msg, 'id' => $id];
        } catch (Exception $e) {
            logAppError("AnnouncementService::saveAnnouncement Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to save announcement.', 'code' => 500];
        }
    }

    public static function deleteAnnouncement(PDO $pdo, array $payload): array {
        $admin = self::checkAdminAuth();
        if (!$admin) {
            return ['success' => false, 'message' => 'Unauthorized. Only Administrative accounts can delete announcements.', 'code' => 403];
        }

        $id = isset($payload['id']) ? (int)$payload['id'] : 0;
        if ($id <= 0) {
            return ['success' => false, 'message' => 'Invalid announcement ID.', 'code' => 400];
        }

        try {
            $stmt = $pdo->prepare("DELETE FROM announcements WHERE id = :id");
            $stmt->execute([':id' => $id]);
            return ['success' => true, 'message' => 'Announcement deleted successfully.'];
        } catch (Exception $e) {
            logAppError("AnnouncementService::deleteAnnouncement Error: " . $e->getMessage());
            return ['success' => false, 'message' => 'Failed to delete announcement.', 'code' => 500];
        }
    }

    public static function uploadImage(): array {
        $admin = self::checkAdminAuth();
        if (!$admin) {
            return ['success' => false, 'message' => 'Unauthorized. Admin session required.', 'code' => 403];
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'message' => 'No image file uploaded or upload error occurred.', 'code' => 400];
        }

        $file = $_FILES['image'];
        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($file['size'] > $maxSize) {
            return ['success' => false, 'message' => 'Image size exceeds maximum 5MB limit.', 'code' => 400];
        }

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mime, $allowedMimes)) {
            return ['success' => false, 'message' => 'Invalid image format. Allowed formats: JPG, PNG, WEBP, GIF.', 'code' => 400];
        }

        $mimeToExt = [
            'image/jpeg' => 'jpg',
            'image/png'  => 'png',
            'image/webp' => 'webp',
            'image/gif'  => 'gif'
        ];
        $ext = $mimeToExt[$mime] ?? 'jpg';

        // Polyglot / Embedded Executable Script Check
        $rawBytes = @file_get_contents($file['tmp_name']);
        if ($rawBytes && preg_match('/<\?php|<\?=|<script\b|eval\s*\(|base64_decode\s*\(/i', $rawBytes)) {
            return ['success' => false, 'message' => 'Security Error: Executable script patterns detected inside image file.', 'code' => 400];
        }

        $uploadDir = __DIR__ . '/../../uploads/announcements/';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $filename = 'post_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            $relativePath = 'uploads/announcements/' . $filename;
            return [
                'success'   => true,
                'message'   => 'Image uploaded successfully.',
                'image_url' => $relativePath
            ];
        }

        return ['success' => false, 'message' => 'Failed to move uploaded file.', 'code' => 500];
    }
}
