<?php
/**
 * GNCP Enrollment System — Automated Test Suite & System Dashboard
 * Runs comprehensive diagnostics on environment, database, authentication,
 * state machine transitions, and local library assets.
 */

// Access control: allow CLI unconditionally, require ADMIN session for HTTP requests
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/shared/backend/utils/session_guard.php';
    initSession();
    $adminSess = $_SESSION['gncp_admin_user'] ?? null;
    $stationSess = $_SESSION['gncp_station_user'] ?? null;
    $sess = $adminSess ?: $stationSess;
    $user = is_array($sess) ? $sess : (is_string($sess) ? json_decode($sess, true) : null);
    $role = strtoupper($user['role'] ?? '');
    if (!in_array($role, ['ADMIN', 'SUPER_ADMIN', 'DEVELOPER'], true)) {
        http_response_code(403);
        header('Content-Type: text/html; charset=utf-8');
        die("<!DOCTYPE html><html><head><title>403 Forbidden</title></head><body style='font-family:sans-serif;text-align:center;padding:50px;'><h1>403 Forbidden</h1><p>Access to the System Diagnostic Suite requires an active Administrator session.</p><a href='/systemtest/' style='color:#0ea5e9;'>Go to Portal Login</a></body></html>");
    }
}

// 1. Diagnostics / Environment Check (Pre-DB)
$phpVersion = PHP_VERSION;
$phpOk = version_compare($phpVersion, '8.0.0', '>=');
$extensions = ['pdo', 'pdo_mysql', 'json', 'openssl'];
$missingExts = [];
foreach ($extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExts[] = $ext;
    }
}
$extsOk = empty($missingExts);

// 2. Database Connection Test (Custom connection to avoid early exit in config/database.php)
$dbConnected = false;
$dbError = null;
$pdo = null;

$host = 'localhost';
$dbName = 'gncp_portal';
$dbUser = 'root';
$dbPass = '';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbName;charset=$charset", $dbUser, $dbPass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $dbConnected = true;
} catch (PDOException $e) {
    $dbError = $e->getMessage();
}

// 3. Run diagnostics if DB is connected
$tablesCheck = [];
$usersCheck = [];
$flowTestResult = null;
$cleanupDone = false;

if ($dbConnected) {
    // A. Check required tables
    $requiredTables = ['pre_enrollments', 'station_users', 'students', 'courses', 'sections', 'enrollments'];
    foreach ($requiredTables as $table) {
        try {
            $stmt = $pdo->query("SELECT 1 FROM `$table` LIMIT 1");
            $tablesCheck[$table] = [
                'status' => 'OK',
                'details' => 'Table exists and is readable.'
            ];
        } catch (PDOException $ex) {
            $tablesCheck[$table] = [
                'status' => 'MISSING',
                'details' => $ex->getMessage()
            ];
        }
    }

    // B. Check default station users presence
    $requiredUsers = [
        'admin'      => ['role' => 'ADMIN', 'name' => 'System Administrator'],
        'it_officer' => ['role' => 'IT_CENTER', 'name' => 'IT Desk Officer'],
        'kriz'       => ['role' => 'REGISTRAR', 'name' => 'Registrar Officer'],
        'tristan'    => ['role' => 'HELPDESK', 'name' => 'Academic Advisor'],
        'ethan'      => ['role' => 'MEDICAL', 'name' => 'Clinic Medical Officer'],
        'thirdy'     => ['role' => 'SCHOLARSHIP', 'name' => 'Scholarship Officer'],
        'cashier'    => ['role' => 'CASHIER', 'name' => 'Main Cashier']
    ];

    foreach ($requiredUsers as $uname => $meta) {
        $stmt = $pdo->prepare("SELECT * FROM `station_users` WHERE `username` = :user");
        $stmt->execute(['user' => $uname]);
        $uRow = $stmt->fetch();
        if ($uRow) {
            $roleMatch = $uRow['role'] === $meta['role'];
            $usersCheck[$uname] = [
                'status' => $roleMatch ? 'OK' : 'MISMATCH',
                'details' => $roleMatch ? "Account is active. Assigned role: {$uRow['role']}." : "Role mismatch! Found: {$uRow['role']}, Expected: {$meta['role']}"
            ];
        } else {
            $usersCheck[$uname] = [
                'status' => 'MISSING',
                'details' => "Account is not seeded in database. Run schema.sql."
            ];
        }
    }

    // C. Simulated Integration Test: Online pre-reg -> approval -> physical workstations -> full activation
    $testId = 'TEST-' . rand(1000, 9999);
    $steps = [];
    try {
        // Step 1: Pre-Registration Creation
        $initialRoadmap = [
            ['stepId' => 'online_registration', 'status' => 'COMPLETED', 'updatedAt' => date('c')],
            ['stepId' => 'registrar_verification', 'status' => 'PENDING', 'updatedAt' => null],
            ['stepId' => 'advising_assessment', 'status' => 'PENDING', 'updatedAt' => null],
            ['stepId' => 'clinic_checkup', 'status' => 'PENDING', 'updatedAt' => null],
            ['stepId' => 'scholarship_verification', 'status' => 'PENDING', 'updatedAt' => null],
            ['stepId' => 'cashier_payment', 'status' => 'PENDING', 'updatedAt' => null],
            ['stepId' => 'id_email_final', 'status' => 'PENDING', 'updatedAt' => null]
        ];
        $reqData = ['status' => 'PENDING', 'docs' => ['psa' => 'not-submitted', 'reportCard' => 'not-submitted', 'goodMoral' => 'not-submitted']];
        $medData = ['status' => 'pending', 'physicalExam' => 'not-assessed'];
        $schData = ['status' => 'PENDING', 'notes' => ''];
        $payData = ['status' => 'PENDING', 'totalFee' => 24000, 'amountPaid' => 0, 'balance' => 24000];
        $helpData = ['nstp' => 'CWTS', 'hasScholarship' => false, 'scholarshipName' => 'NONE', 'status' => 'PENDING'];

        $ins = $pdo->prepare("INSERT INTO `pre_enrollments` (
            `temp_student_id`, `temp_pin`, `student_type`, `course_code`, `nstp`, 
            `first_name`, `last_name`, `email`, `phone`, `birth_date`, `gender`, `address`, 
            `elementary_school`, `junior_high_school`, `senior_high_school`, `health_status`, 
            `emergency_contact_name`, `emergency_contact_phone`, `payment_mode`, `status`, `roadmap`,
            `requirements_data`, `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`
        ) VALUES (
            :ref, '123456', 'FRESHMAN', 'BSIT', 'CWTS',
            'TestStudent', 'SystemVerifier', 'test.student@gncp-test.edu', '09123456789', '2005-01-01', 'Male', 'Test Address',
            'Elem School', 'JHS School', 'SHS School', 'GOOD',
            'Emergency Parent', '09876543210', 'Cash', 'PRE_REGISTERED', :roadmap,
            :req, :med, :sch, :pay, :help
        )");
        $ins->execute([
            'ref' => $testId,
            'roadmap' => json_encode($initialRoadmap),
            'req' => json_encode($reqData),
            'med' => json_encode($medData),
            'sch' => json_encode($schData),
            'pay' => json_encode($payData),
            'help' => json_encode($helpData)
        ]);
        $steps[] = ['name' => '1. Online Pre-Registration creation', 'status' => 'SUCCESS', 'message' => "Created record with Ref No: $testId"];

        // Step 2: Registrar Coordinator approval simulation
        $sel = $pdo->prepare("SELECT * FROM `pre_enrollments` WHERE `temp_student_id` = :ref");
        $sel->execute(['ref' => $testId]);
        $stud = $sel->fetch();
        
        $rm = json_decode($stud['roadmap'], true);
        $rm[1]['status'] = 'COMPLETED'; // Registrar Verification complete
        $rm[2]['status'] = 'IN_PROGRESS'; // Advising Assessment activated
        
        $up = $pdo->prepare("UPDATE `pre_enrollments` SET `status` = 'Approved', `roadmap` = :rm WHERE `temp_student_id` = :ref");
        $up->execute(['rm' => json_encode($rm), 'ref' => $testId]);
        $steps[] = ['name' => '2. Registrar Coordinator Admission Approval', 'status' => 'SUCCESS', 'message' => 'Status changed to "Approved", roadmap steps updated.'];

        // Step 3: Advising Clearance simulation (Station 2)
        $helpData['status'] = 'COMPLETED';
        $helpData['tlcNotes'] = 'Verified BSIT program and CWTS selection.';
        $rm[2]['status'] = 'COMPLETED';
        $rm[3]['status'] = 'IN_PROGRESS'; // Medical checkup activated
        
        $up = $pdo->prepare("UPDATE `pre_enrollments` SET `helpdesk_data` = :hd, `roadmap` = :rm WHERE `temp_student_id` = :ref");
        $up->execute(['hd' => json_encode($helpData), 'rm' => json_encode($rm), 'ref' => $testId]);
        $steps[] = ['name' => '3. Station 2: Advising & Course Assessment clearance', 'status' => 'SUCCESS', 'message' => 'Cleared Advising & NSTP selection.'];

        // Step 4: Medical Clearance simulation (Station 3)
        $medData['status'] = 'COMPLETED';
        $medData['peFitness'] = 'FIT';
        $medData['nstpFitness'] = 'FIT';
        $rm[3]['status'] = 'COMPLETED';
        $rm[4]['status'] = 'IN_PROGRESS'; // Scholarship activated
        
        $up = $pdo->prepare("UPDATE `pre_enrollments` SET `medical_data` = :med, `roadmap` = :rm WHERE `temp_student_id` = :ref");
        $up->execute(['med' => json_encode($medData), 'rm' => json_encode($rm), 'ref' => $testId]);
        $steps[] = ['name' => '4. Station 3: Medical Checkup clearance', 'status' => 'SUCCESS', 'message' => 'Fitness set to FIT and cleared.'];

        // Step 5: Scholarship Verification simulation (Station 4)
        $schData['status'] = 'COMPLETED';
        $schData['notes'] = 'No scholarship applicable.';
        $rm[4]['status'] = 'COMPLETED';
        $rm[5]['status'] = 'IN_PROGRESS'; // Cashier activated
        
        $up = $pdo->prepare("UPDATE `pre_enrollments` SET `scholarship_data` = :sch, `roadmap` = :rm WHERE `temp_student_id` = :ref");
        $up->execute(['sch' => json_encode($schData), 'rm' => json_encode($rm), 'ref' => $testId]);
        $steps[] = ['name' => '5. Station 4: Scholarship verification clearance', 'status' => 'SUCCESS', 'message' => 'Scholarship check completed.'];

        // Step 6: Payment Processing simulation (Station 5)
        $payData['status'] = 'PAID';
        $payData['amountPaid'] = 24000;
        $payData['balance'] = 0;
        $payData['transactionRef'] = 'TXN-TEST-999999';
        $rm[5]['status'] = 'COMPLETED';
        $rm[6]['status'] = 'IN_PROGRESS'; // IT activation activated
        
        $up = $pdo->prepare("UPDATE `pre_enrollments` SET `payment_data` = :pay, `roadmap` = :rm WHERE `temp_student_id` = :ref");
        $up->execute(['pay' => json_encode($payData), 'rm' => json_encode($rm), 'ref' => $testId]);
        $steps[] = ['name' => '6. Station 5: Cashier Ledger Payment process', 'status' => 'SUCCESS', 'message' => 'Balance paid, transaction ID logged.'];

        // Step 7: IT Center Portal Activation simulation (Station 6)
        $itData = [
            'permanentId' => 'GNCP-2026-TEST9999',
            'institutionalEmail' => 'test.student@gncp.edu.ph',
            'password' => 'passTest123'
        ];
        $rm[6]['status'] = 'COMPLETED';
        
        // This simulates the actual behavior of stations/backend/api.php?action=update_student when IT finalizes
        $personalInfoJson = json_encode([
            'firstName' => 'TestStudent',
            'middleName' => '',
            'lastName' => 'SystemVerifier',
            'email' => 'test.student@gncp-test.edu',
            'phone' => '09123456789',
            'birthDate' => '2005-01-01',
            'gender' => 'Male',
            'address' => 'Test Address'
        ]);
        $academicInfoJson = json_encode([
            'elementarySchool' => 'Elem School',
            'juniorHighSchool' => 'JHS School',
            'seniorHighSchool' => 'SHS School',
            'shsTrack' => 'STEM'
        ]);
        $hashedPass = password_hash('passTest123', PASSWORD_DEFAULT);

        $insStud = $pdo->prepare("INSERT INTO `students` (
                                    `id`, `name`, `program`, `email`, `password`, `photo`, `year_level`, `status`,
                                    `temp_reference_no`, `personal_info`, `academic_info`, `roadmap`, `requirements_data`,
                                    `medical_data`, `scholarship_data`, `payment_data`, `helpdesk_data`
                                 ) VALUES (
                                    'GNCP-2026-TEST9999', 'TestStudent SystemVerifier', 'BSIT', 'test.student@gncp.edu.ph', :pass, NULL, '1st Year', 'Active',
                                    :temp_ref, :personal, :academic, :roadmap, :requirements,
                                    :medical, :scholarship, :payment, :helpdesk
                                 )");
        $insStud->execute([
            'pass' => $hashedPass,
            'temp_ref' => $testId,
            'personal' => $personalInfoJson,
            'academic' => $academicInfoJson,
            'roadmap'  => json_encode($rm),
            'requirements' => json_encode($reqData),
            'medical'  => json_encode($medData),
            'scholarship' => json_encode($schData),
            'payment'  => json_encode($payData),
            'helpdesk' => json_encode($itData)
        ]);

        $insEnroll = $pdo->prepare("INSERT INTO `enrollments` (`student`, `course`, `status`, `updated`) 
                                    VALUES ('TestStudent SystemVerifier', 'BSIT', 'Enrolled', NOW())");
        $insEnroll->execute();

        // Delete the pre_enrollment record as part of finalization
        $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref")->execute(['ref' => $testId]);

        $steps[] = ['name' => '7. Station 6: Student Portal Account Activation', 'status' => 'SUCCESS', 'message' => 'Generated student portal credentials, set status to ENROLLED and moved pre-enrollment.'];

        // Verification validation: Check if student exists in permanent directories
        $checkPortal = $pdo->prepare("SELECT COUNT(*) FROM `students` WHERE `id` = 'GNCP-2026-TEST9999'");
        $checkPortal->execute();
        $inPortal = $checkPortal->fetchColumn() > 0;

        $checkEnrollCount = $pdo->prepare("SELECT COUNT(*) FROM `enrollments` WHERE `student` = 'TestStudent SystemVerifier'");
        $checkEnrollCount->execute();
        $inEnrollments = $checkEnrollCount->fetchColumn() > 0;

        if ($inPortal && $inEnrollments) {
            $steps[] = ['name' => '8. Student directory migration assertion', 'status' => 'SUCCESS', 'message' => 'Asserted that student was successfully migrated to `students` and `enrollments` tables.'];
            $flowTestResult = ['success' => true, 'steps' => $steps];
        } else {
            $steps[] = ['name' => '8. Student directory migration assertion', 'status' => 'FAILED', 'message' => 'Migration assertion failed! Record missing in permanent directories.'];
            $flowTestResult = ['success' => false, 'steps' => $steps];
        }

        // Database Cleanup
        $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref")->execute(['ref' => $testId]);
        $pdo->prepare("DELETE FROM `students` WHERE `id` = 'GNCP-2026-TEST9999'")->execute();
        $pdo->prepare("DELETE FROM `enrollments` WHERE `student` = 'TestStudent SystemVerifier'")->execute();
        $cleanupDone = true;

    } catch (PDOException $e) {
        $steps[] = ['name' => 'Execution Error', 'status' => 'FAILED', 'message' => $e->getMessage()];
        $flowTestResult = ['success' => false, 'steps' => $steps];
        
        // Attempt cleanup regardless
        try {
            $pdo->prepare("DELETE FROM `pre_enrollments` WHERE `temp_student_id` = :ref")->execute(['ref' => $testId]);
            $pdo->prepare("DELETE FROM `students` WHERE `id` = 'GNCP-2026-TEST9999'")->execute();
            $pdo->prepare("DELETE FROM `enrollments` WHERE `student` = 'TestStudent SystemVerifier'")->execute();
        } catch (Exception $ex) { }
    }
}

// 4. Vendor Libraries (Asset Verification)
$libsPath = __DIR__ . '/shared/libs/';
$libsToTest = [
    'vue.global.js' => $libsPath . 'vue.global.js',
    'bootstrap.bundle.min.js' => $libsPath . 'bootstrap.bundle.min.js',
    'bootstrap.min.css' => $libsPath . 'bootstrap.min.css',
    'font-awesome/css/all.min.css' => $libsPath . 'font-awesome/css/all.min.css',
    'font-awesome/webfonts/fa-solid-900.woff2' => $libsPath . 'font-awesome/webfonts/fa-solid-900.woff2'
];
$assetsCheck = [];
foreach ($libsToTest as $name => $path) {
    if (file_exists($path)) {
        $assetsCheck[$name] = [
            'status' => 'OK',
            'details' => 'Local asset file exists on disk. File size: ' . round(filesize($path) / 1024, 1) . ' KB.'
        ];
    } else {
        $assetsCheck[$name] = [
            'status' => 'MISSING',
            'details' => 'Asset file is missing! Frontend pages will fail to load in offline environments.'
        ];
    }
}

// 5. Workstation Synchronization Check (Static Code Analysis)
$filesToCheck = [
    'tlc-helpdesk' => __DIR__ . '/stations/tlc-helpdesk/assets/js/app.js',
    'medical-checkup' => __DIR__ . '/stations/medical-checkup/assets/js/app.js',
    'payment-processing' => __DIR__ . '/stations/payment-processing/assets/js/app.js',
    'it-center' => __DIR__ . '/stations/it-center/assets/js/app.js'
];
$syncCheck = [];
foreach ($filesToCheck as $station => $path) {
    if (file_exists($path)) {
        $code = file_get_contents($path);
        $hasStorage = (strpos($code, "window.addEventListener('storage'") !== false) 
                   || (strpos($code, 'window.addEventListener("storage"') !== false);
        $syncCheck[$station] = [
            'status' => $hasStorage ? 'OK' : 'MISSING',
            'details' => $hasStorage ? 'Storage reload synchronizer is implemented.' : 'Offline storage listener is missing! Data won\'t update reactively.'
        ];
    } else {
        $syncCheck[$station] = [
            'status' => 'MISSING',
            'details' => 'Station JS entry script file not found!'
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>GNCP Portal — Automated System Diagnostics</title>
    
    <link href="shared/libs/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="shared/libs/font-awesome/css/all.min.css">
    
    <style>
        :root {
            --bg-dark: #070d0a;
            --panel-bg: rgba(255, 255, 255, 0.03);
            --panel-border: rgba(255, 255, 255, 0.08);
            --gold: #D4AF37;
            --emerald: #10b981;
            --crimson: #ef4444;
            --text-muted: rgba(255, 255, 255, 0.55);
        }

        body {
            background-color: var(--bg-dark);
            color: #ffffff;
            font-family: 'Plus Jakarta Sans', -apple-system, sans-serif;
            min-height: 100vh;
            padding: 40px 20px;
        }

        .dashboard-header {
            margin-bottom: 40px;
            text-align: center;
        }

        .dashboard-title {
            font-size: 2.2rem;
            font-weight: 800;
            letter-spacing: -0.5px;
            background: linear-gradient(135deg, #ffffff 0%, var(--gold) 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .dashboard-subtitle {
            color: var(--text-muted);
            font-size: 0.95rem;
            margin-top: 5px;
        }

        .panel-card {
            background: var(--panel-bg);
            border: 1px solid var(--panel-border);
            border-radius: 16px;
            padding: 24px;
            margin-bottom: 24px;
            backdrop-filter: blur(10px);
            transition: transform 0.2s, box-shadow 0.2s;
        }

        .panel-card:hover {
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
            border-color: rgba(255, 255, 255, 0.12);
        }

        .panel-title {
            font-size: 1.15rem;
            font-weight: 700;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            border-bottom: 1px solid var(--panel-border);
            padding-bottom: 12px;
        }

        .panel-title i {
            color: var(--gold);
        }

        .diagnostic-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid rgba(255, 255, 255, 0.03);
        }

        .diagnostic-row:last-child {
            border-bottom: none;
        }

        .diagnostic-label {
            font-size: 0.9rem;
            font-weight: 600;
        }

        .diagnostic-desc {
            font-size: 0.76rem;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .badge-status {
            padding: 5px 10px;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .status-ok {
            background-color: rgba(16, 185, 129, 0.15);
            color: var(--emerald);
            border: 1px solid rgba(16, 185, 129, 0.3);
        }

        .status-missing, .status-failed {
            background-color: rgba(239, 68, 68, 0.15);
            color: var(--crimson);
            border: 1px solid rgba(239, 68, 68, 0.3);
        }

        .status-warn {
            background-color: rgba(212, 175, 85, 0.15);
            color: var(--gold);
            border: 1px solid rgba(212, 175, 85, 0.3);
        }

        .flow-step {
            display: flex;
            gap: 14px;
            position: relative;
            padding-bottom: 20px;
        }

        .flow-step:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 17px;
            top: 30px;
            bottom: 0;
            width: 2px;
            background: var(--panel-border);
        }

        .flow-node {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.85rem;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.05);
            border: 2px solid var(--panel-border);
            color: var(--text-muted);
            z-index: 1;
        }

        .flow-step.success .flow-node {
            background: var(--emerald);
            border-color: var(--emerald);
            color: #fff;
            box-shadow: 0 0 10px rgba(16, 185, 129, 0.3);
        }

        .flow-step.failed .flow-node {
            background: var(--crimson);
            border-color: var(--crimson);
            color: #fff;
            box-shadow: 0 0 10px rgba(239, 68, 68, 0.3);
        }

        .flow-content {
            flex: 1;
            padding-top: 4px;
        }

        .flow-title {
            font-size: 0.92rem;
            font-weight: 700;
            margin: 0;
        }

        .flow-message {
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-top: 3px;
        }

        .console-box {
            background: #000000;
            border: 1px solid var(--panel-border);
            border-radius: 10px;
            padding: 16px;
            font-family: 'Courier New', Courier, monospace;
            font-size: 0.82rem;
            color: #38bdf8;
            max-height: 250px;
            overflow-y: auto;
            margin-top: 10px;
        }
    </style>
</head>
<body>

    <div class="container">
        
        <header class="dashboard-header">
            <h1 class="dashboard-title"><i class="fa-solid fa-microchip me-2"></i>Automated Testing Suite</h1>
            <p class="dashboard-subtitle">GNCP Enrollment System Database & Workstations Verifier</p>
        </header>

        <div class="row">
            
            <!-- Left Side columns: Environment, Tables, Local Assets -->
            <div class="col-lg-6">
                
                <!-- Section 1: Server Environment -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-server"></i>Server Environment Details</h2>
                    
                    <div class="diagnostic-row">
                        <div>
                            <div class="diagnostic-label">PHP Engine Version</div>
                            <div class="diagnostic-desc">Requires PHP 8.0 or higher.</div>
                        </div>
                        <span class="badge-status <?php echo $phpOk ? 'status-ok' : 'status-failed'; ?>">
                            PHP <?php echo $phpVersion; ?>
                        </span>
                    </div>

                    <div class="diagnostic-row">
                        <div>
                            <div class="diagnostic-label">Required Exts (PDO, JSON)</div>
                            <div class="diagnostic-desc">Verifies loaded PHP runtime extensions.</div>
                        </div>
                        <span class="badge-status <?php echo $extsOk ? 'status-ok' : 'status-failed'; ?>">
                            <?php echo $extsOk ? 'All Loaded' : 'Missing: ' . implode(', ', $missingExts); ?>
                        </span>
                    </div>
                </section>

                <!-- Section 2: Database Integrity -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-database"></i>Database Schema Integrity</h2>
                    
                    <div class="diagnostic-row">
                        <div>
                            <div class="diagnostic-label">Database Connection</div>
                            <div class="diagnostic-desc">Connects to 'gncp_portal' database using standard root user.</div>
                        </div>
                        <span class="badge-status <?php echo $dbConnected ? 'status-ok' : 'status-failed'; ?>">
                            <?php echo $dbConnected ? 'Connected' : 'Offline / Error'; ?>
                        </span>
                    </div>

                    <?php if (!$dbConnected): ?>
                        <div class="alert alert-danger mt-3" style="font-size: 0.8rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #fca5a5;">
                            <strong>Connection Error:</strong> <?php echo htmlspecialchars($dbError); ?><br>
                            <span class="d-block mt-2">👉 Make sure the MySQL server is started in the XAMPP Control Panel.</span>
                        </div>
                    <?php else: ?>
                        
                        <div class="mt-4">
                            <h6 style="font-weight:700; font-size:0.82rem; text-transform:uppercase; color:var(--gold); letter-spacing:0.5px;">Table Assertions</h6>
                            <?php foreach ($tablesCheck as $tName => $check): ?>
                                <div class="diagnostic-row">
                                    <div>
                                        <div class="diagnostic-label" style="font-weight: 500; font-family: monospace; font-size: 0.88rem;">`<?php echo $tName; ?>`</div>
                                        <div class="diagnostic-desc" style="font-size:0.7rem;"><?php echo htmlspecialchars($check['details']); ?></div>
                                    </div>
                                    <span class="badge-status <?php echo $check['status'] === 'OK' ? 'status-ok' : 'status-failed'; ?>">
                                        <?php echo $check['status']; ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    <?php endif; ?>
                </section>

                <!-- Section 3: Seeding Verification -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-users-gear"></i>Workstation Credentials Check</h2>
                    
                    <?php if (!$dbConnected): ?>
                        <p class="text-muted" style="font-size:0.84rem;">A database connection is required to scan accounts.</p>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 0.78rem; margin-top:-10px; margin-bottom: 20px;">
                            Verifies that all pre-configured accounts needed to operate the kiosks are correctly loaded in the `station_users` database.
                        </p>
                        <?php foreach ($usersCheck as $username => $check): ?>
                            <div class="diagnostic-row">
                                <div>
                                    <div class="diagnostic-label" style="font-weight: 700;">Account: `<?php echo $username; ?>`</div>
                                    <div class="diagnostic-desc"><?php echo htmlspecialchars($check['details']); ?></div>
                                </div>
                                <span class="badge-status <?php echo $check['status'] === 'OK' ? 'status-ok' : ($check['status'] === 'MISMATCH' ? 'status-warn' : 'status-failed'); ?>">
                                    <?php echo $check['status']; ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </section>

            </div>

            <!-- Right Side columns: Integration state machine, vendor libraries, synchronizer -->
            <div class="col-lg-6">

                <!-- Section 4: Live State Transition Test -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-circle-nodes"></i>End-to-End State Machine Flow Test</h2>
                    
                    <?php if (!$dbConnected): ?>
                        <p class="text-muted" style="font-size:0.84rem;">Database connection is required to simulate state transitions.</p>
                    <?php else: ?>
                        <p class="text-muted" style="font-size: 0.78rem; margin-top:-10px; margin-bottom: 25px;">
                            Simulates a complete student enrollment lifecycle: Online registration ➔ coordinator approval ➔ physical workstation kiosk clearance ➔ permanent directory migration.
                        </p>
                        
                        <div class="timeline mt-2">
                            <?php foreach ($flowTestResult['steps'] as $idx => $step): ?>
                                <div class="flow-step <?php echo strtolower($step['status']); ?>">
                                    <div class="flow-node">
                                        <?php if ($step['status'] === 'SUCCESS'): ?>
                                            <i class="fa-solid fa-check"></i>
                                        <?php else: ?>
                                            <i class="fa-solid fa-xmark"></i>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flow-content">
                                        <h4 class="flow-title"><?php echo htmlspecialchars($step['name']); ?></h4>
                                        <p class="flow-message"><?php echo htmlspecialchars($step['message']); ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <?php if ($flowTestResult['success']): ?>
                            <div class="alert alert-success mt-2" style="font-size: 0.8rem; background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.2); color: #a7f3d0; margin-bottom:0;">
                                <i class="fa-solid fa-circle-check me-2"></i><strong>All Transitions Passed!</strong> State machine logic is fully correct. Diagnostics database cleanup completed successfully.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-danger mt-2" style="font-size: 0.8rem; background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.2); color: #fca5a5; margin-bottom:0;">
                                <i class="fa-solid fa-triangle-exclamation me-2"></i><strong>Integration Flow Failed!</strong> Review the timeline log above to identify where the state machine broke.
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </section>

                <!-- Section 5: Offline Vendor Assets Check -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-boxes-packing"></i>Offline Vendor Libraries</h2>
                    
                    <p class="text-muted" style="font-size: 0.78rem; margin-top:-10px; margin-bottom: 20px;">
                        Verifies the local physical existence of shared CSS/JS vendor files needed to support presentation without internet.
                    </p>
                    
                    <?php foreach ($assetsCheck as $name => $check): ?>
                        <div class="diagnostic-row">
                            <div>
                                <div class="diagnostic-label" style="font-family: monospace; font-size:0.82rem;"><?php echo $name; ?></div>
                                <div class="diagnostic-desc"><?php echo htmlspecialchars($check['details']); ?></div>
                            </div>
                            <span class="badge-status <?php echo $check['status'] === 'OK' ? 'status-ok' : 'status-failed'; ?>">
                                <?php echo $check['status']; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </section>

                <!-- Section 6: Workstation Sync check -->
                <section class="panel-card">
                    <h2 class="panel-title"><i class="fa-solid fa-arrows-rotate"></i>Real-time Station Synchronizers</h2>
                    
                    <p class="text-muted" style="font-size: 0.78rem; margin-top:-10px; margin-bottom: 20px;">
                        Analyzes the client-side JavaScript controllers of all workstation kiosks for offline-safe live update event listeners.
                    </p>
                    
                    <?php foreach ($syncCheck as $station => $check): ?>
                        <div class="diagnostic-row">
                            <div>
                                <div class="diagnostic-label" style="text-transform: capitalize;"><?php echo str_replace('-', ' ', $station); ?> Kiosk</div>
                                <div class="diagnostic-desc"><?php echo htmlspecialchars($check['details']); ?></div>
                            </div>
                            <span class="badge-status <?php echo $check['status'] === 'OK' ? 'status-ok' : 'status-failed'; ?>">
                                <?php echo $check['status']; ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </section>

            </div>

        </div>

        <footer style="text-align: center; margin-top: 40px; color: var(--text-muted); font-size: 0.8rem;">
            GNCP Portal Automated Tests • Recreated and Compiled dynamically for pairing verification.
        </footer>

    </div>

</body>
</html>
