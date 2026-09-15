<?php
/**
 * GNCP Academic Portal — Central Database Configuration
 * Provides a secure, single-instance PDO connection supporting both local XAMPP and remote hosting environments.
 */

class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $httpHost = $_SERVER['HTTP_HOST'] ?? 'cli';
        $isCli = (php_sapi_name() === 'cli' || PHP_SAPI === 'cli' || $httpHost === 'cli');
        $isLocal = ($isCli || $httpHost === 'localhost' || str_starts_with($httpHost, '127.0.0.1') || str_starts_with($httpHost, 'localhost:'));

        // Defaults: Local XAMPP
        $host    = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : '127.0.0.1');
        $db      = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : 'gncp_portal');
        $user    = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : 'root');
        $pass    = getenv('DB_PASS') !== false ? getenv('DB_PASS') : (defined('DB_PASS') ? DB_PASS : '');
        $charset = 'utf8mb4';

        // Load custom config override on remote hosting
        $customConfig = __DIR__ . '/db_config.php';
        if (file_exists($customConfig)) {
            $cfg = include $customConfig;
            if (is_array($cfg)) {
                // If on remote hosting OR if custom config explicitly targets local/custom host
                if (!$isLocal || ($cfg['host'] ?? '') === '127.0.0.1' || ($cfg['host'] ?? '') === 'localhost') {
                    $host = $cfg['host'] ?? $host;
                    $db   = $cfg['db'] ?? $db;
                    $user = $cfg['user'] ?? $user;
                    $pass = $cfg['pass'] ?? $pass;
                }
            }
        }

        $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $this->conn = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'data' => null,
                'error' => 'Database connection failed. Please check database configuration and ensure MySQL is running.',
                'timestamp' => date('c')
            ]);
            exit;
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance->conn;
    }
}

