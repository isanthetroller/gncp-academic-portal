<?php
/**
 * GNCP Academic & Enrollment System — InfinityFree Web Database Installer & Synchronizer
 * Enables one-click database schema creation and credentials configuration on remote web hosting.
 */

$dbConfigFile = __DIR__ . '/../shared/backend/config/db_config.php';
$schemaFile   = __DIR__ . '/schema.sql';
$currentConfig = file_exists($dbConfigFile) ? (include $dbConfigFile) : [];

require_once __DIR__ . '/../shared/backend/utils/session_guard.php';
initSession();
$isSuperAdmin = false;
$adminSess = $_SESSION['gncp_admin_user'] ?? null;
if ($adminSess) {
    $admin = is_array($adminSess) ? $adminSess : (is_string($adminSess) ? json_decode($adminSess, true) : []);
    if (strtoupper($admin['role'] ?? '') === 'SUPER_ADMIN') {
        $isSuperAdmin = true;
    }
}

$isInstalled = (!empty($currentConfig) && !empty($currentConfig['host']) && !empty($currentConfig['db']));
if (php_sapi_name() !== 'cli' && $isInstalled && !$isSuperAdmin) {
    http_response_code(403);
    die("<!DOCTYPE html><html><head><title>403 Forbidden</title><style>body{background:#0b1512;color:#fff;font-family:sans-serif;text-align:center;padding:60px;}a{color:#D4AF37;text-decoration:none;font-weight:bold;}</style></head><body><h1>403 Forbidden</h1><p>Database is already provisioned and locked. Modifying database settings requires an active Super Administrator session.</p><p><a href='../admin/'>Go to Super Admin Portal</a></p></body></html>");
}

$statusMsg = '';
$statusType = '';
$stepLogs = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host'] ?? '');
    $dbName = trim($_POST['db_name'] ?? '');
    $dbUser = trim($_POST['db_user'] ?? '');
    $dbPass = trim($_POST['db_pass'] ?? '');

    if (!$dbHost || !$dbName || !$dbUser) {
        $statusMsg = 'Please provide the DB Host, DB Name, and DB User.';
        $statusType = 'danger';
    } else {
        try {
            $dsn = "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4";
            $pdo = new PDO($dsn, $dbUser, $dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);

            $stepLogs[] = "✅ Successfully connected to MySQL server ($dbHost).";

            // Save db_config.php
            $configContent = "<?php\nreturn [\n    'host' => " . var_export($dbHost, true) . ",\n    'db'   => " . var_export($dbName, true) . ",\n    'user' => " . var_export($dbUser, true) . ",\n    'pass' => " . var_export($dbPass, true) . "\n];\n";
            file_put_contents($dbConfigFile, $configContent);
            $stepLogs[] = "✅ Updated database configuration in shared/backend/config/db_config.php.";

            // Execute schema.sql
            if (file_exists($schemaFile)) {
                $sql = file_get_contents($schemaFile);
                
                // Remove comment lines
                $lines = explode("\n", $sql);
                $cleanSql = '';
                foreach ($lines as $line) {
                    $trimLine = trim($line);
                    if (str_starts_with($trimLine, '--') || str_starts_with($trimLine, '/*')) {
                        continue;
                    }
                    $cleanSql .= $line . "\n";
                }

                // Split by statement delimiters
                $queries = array_filter(array_map('trim', explode(';', $cleanSql)));
                $executedCount = 0;

                foreach ($queries as $query) {
                    $trimQ = trim($query);
                    if (!empty($trimQ)) {
                        // Skip CREATE DATABASE and USE statements on shared hosting
                        if (preg_match('/^(CREATE\s+DATABASE|USE\s+)/i', $trimQ)) {
                            continue;
                        }
                        try {
                            $pdo->exec($trimQ);
                            $executedCount++;
                        } catch (Exception $qe) {
                            // Non-fatal if table already exists or duplicate key
                            $stepLogs[] = "ℹ️ Note on query (" . substr($trimQ, 0, 40) . "...): " . htmlspecialchars($qe->getMessage());
                        }
                    }
                }

                $stepLogs[] = "✅ Successfully executed $executedCount schema and seeding queries.";

                // Verify critical tables
                $tablesStmt = $pdo->query("SHOW TABLES");
                $tables = $tablesStmt->fetchAll(PDO::FETCH_COLUMN);
                $stepLogs[] = "📋 Active Tables in Database (" . count($tables) . "): " . implode(', ', $tables);

                $statusMsg = 'Database setup & configuration completed successfully!';
                $statusType = 'success';
            } else {
                $statusMsg = 'schema.sql file not found in database/ directory.';
                $statusType = 'warning';
            }

        } catch (PDOException $e) {
            $statusMsg = 'Database connection failed: ' . $e->getMessage();
            $statusType = 'danger';
        }
    }
}

// Check if db_config already exists
$currentConfig = file_exists($dbConfigFile) ? include $dbConfigFile : [];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNCP Portal — Remote Database Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { background: #0b1512; color: #e2e8f0; font-family: 'Segoe UI', system-ui, sans-serif; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .setup-card { background: #13241f; border: 1px solid #1f3d34; border-radius: 16px; box-shadow: 0 20px 40px rgba(0,0,0,0.5); max-width: 650px; width: 100%; padding: 32px; }
        .brand-badge { background: linear-gradient(135deg, #004D38 0%, #006A4E 100%); color: #D4AF37; padding: 6px 14px; border-radius: 50px; font-weight: 700; font-size: 0.8rem; letter-spacing: 1px; display: inline-block; margin-bottom: 12px; }
        .form-control { background: #0a1714; border: 1px solid #23473c; color: #fff; border-radius: 8px; padding: 12px 14px; }
        .form-control:focus { background: #0c1c18; border-color: #D4AF37; color: #fff; box-shadow: 0 0 0 3px rgba(212,175,55,0.2); }
        .btn-submit { background: linear-gradient(135deg, #006A4E 0%, #004D38 100%); color: #fff; font-weight: 700; border: 1px solid #D4AF37; border-radius: 8px; padding: 12px; transition: all 0.2s ease; width: 100%; }
        .btn-submit:hover { background: #D4AF37; color: #004D38; }
        .log-box { background: #07100e; border: 1px solid #18332b; border-radius: 8px; padding: 14px; font-family: monospace; font-size: 0.82rem; max-height: 220px; overflow-y: auto; margin-top: 16px; }
    </style>
</head>
<body>

<div class="setup-card">
    <div class="text-center mb-4">
        <span class="brand-badge"><i class="fa-solid fa-graduation-cap me-1"></i> GO-ON NATIONAL COLLEGE</span>
        <h3 class="fw-bold text-white mb-1">Remote Database Provisioner</h3>
        <p class="text-secondary small">One-Click MariaDB/MySQL Initialization for InfinityFree Hosting</p>
    </div>

    <?php if ($statusMsg): ?>
        <div class="alert alert-<?= $statusType ?> d-flex align-items-center mb-3" role="alert">
            <div><strong><?= $statusMsg ?></strong></div>
        </div>
    <?php endif; ?>

    <?php if (!empty($stepLogs)): ?>
        <div class="log-box mb-4">
            <?php foreach ($stepLogs as $log): ?>
                <div class="text-light mb-1"><?= $log ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($statusType === 'success'): ?>
        <div class="text-center p-3 bg-dark bg-opacity-50 border border-success rounded-3 mb-4">
            <h5 class="text-success fw-bold"><i class="fa-solid fa-circle-check me-2"></i>Deployment Ready!</h5>
            <p class="text-secondary small mb-3">All tables initialized and system administrators provisioned.</p>
            <div class="d-flex gap-2 justify-content-center flex-wrap">
                <a href="../" class="btn btn-outline-warning btn-sm"><i class="fa-solid fa-house me-1"></i> Gateway Home</a>
                <a href="../school-website/" class="btn btn-outline-light btn-sm"><i class="fa-solid fa-globe me-1"></i> School Website</a>
                <a href="../enrollment-system/" class="btn btn-outline-success btn-sm"><i class="fa-solid fa-user-plus me-1"></i> Online Registration</a>
                <a href="../registrar/" class="btn btn-outline-info btn-sm"><i class="fa-solid fa-id-card me-1"></i> Registrar Portal</a>
                <a href="../admin/" class="btn btn-outline-danger btn-sm"><i class="fa-solid fa-shield-halved me-1"></i> Super Admin</a>
            </div>
        </div>
    <?php endif; ?>

    <form method="POST" action="">
        <div class="mb-3">
            <label class="form-label text-light small fw-bold">MySQL Hostname</label>
            <input type="text" name="db_host" class="form-control" placeholder="e.g. sql300.infinityfree.com" value="<?= htmlspecialchars($currentConfig['host'] ?? '127.0.0.1') ?>" required>
            <div class="form-text text-secondary" style="font-size:0.75rem;">Found in your InfinityFree vPanel under 'MySQL Databases'</div>
        </div>

        <div class="mb-3">
            <label class="form-label text-light small fw-bold">MySQL Database Name</label>
            <input type="text" name="db_name" class="form-control" placeholder="e.g. if0_42745296_gncp_portal" value="<?= htmlspecialchars($currentConfig['db'] ?? 'gncp_portal') ?>" required>
        </div>

        <div class="mb-3">
            <label class="form-label text-light small fw-bold">MySQL Username</label>
            <input type="text" name="db_user" class="form-control" placeholder="e.g. if0_42745296" value="<?= htmlspecialchars($currentConfig['user'] ?? 'root') ?>" required>
        </div>

        <div class="mb-4">
            <label class="form-label text-light small fw-bold">MySQL Password</label>
            <input type="password" name="db_pass" class="form-control" placeholder="vPanel Password" value="<?= htmlspecialchars($currentConfig['pass'] ?? '') ?>">
        </div>

        <button type="submit" class="btn btn-submit">
            <i class="fa-solid fa-bolt me-2"></i> Connect & Initialize Database
        </button>
    </form>
</div>

</body>
</html>
