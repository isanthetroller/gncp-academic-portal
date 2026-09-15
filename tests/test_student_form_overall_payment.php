<?php
/**
 * Automated Verification: Student Form Overall Semester Payment Alignment
 */
require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../shared/backend/services/AssessmentService.php';

$pdo = Database::getInstance();

$passed = 0;
$failed = 0;

function assertCheck($desc, $cond, $info = '') {
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "✅ [PASS] $desc\n";
        if ($info) echo "   └─ $info\n";
    } else {
        $failed++;
        echo "❌ [FAIL] $desc\n";
        if ($info) echo "   └─ $info\n";
    }
}

echo "=== VERIFYING STUDENT REGISTRATION & FORM OVERALL SEMESTER PAYMENT ===\n\n";

// 1. Test get_active_programs API payload
$url = 'http://127.0.0.1/systemtest/enrollment-system/backend/register.php?action=get_active_programs';
$response = file_get_contents($url);
$json = json_decode($response, true);

assertCheck("get_active_programs returns success", isset($json['success']) && $json['success'] === true);
$payload = $json['data'] ?? [];
assertCheck("curriculumFeeMap is included in data payload", isset($payload['curriculumFeeMap']));

$feeMap = $payload['curriculumFeeMap'] ?? [];
assertCheck("curriculumFeeMap contains BSIT", isset($feeMap['BSIT']));

$bsit1st = $feeMap['BSIT']['1st Year'] ?? null;
assertCheck("BSIT 1st Year has curriculum fee calculation", $bsit1st !== null);
assertCheck("BSIT 1st Year cashTotal is exactly 18300", ($bsit1st['cashTotal'] ?? 0) == 18300, "Value: " . ($bsit1st['cashTotal'] ?? 'null'));
assertCheck("BSIT 1st Year totalUnits is 20", ($bsit1st['totalUnits'] ?? 0) == 20, "Units: " . ($bsit1st['totalUnits'] ?? 'null'));
assertCheck("BSIT 1st Year subjectsCount is 7", ($bsit1st['subjectsCount'] ?? 0) == 7, "Subjects: " . ($bsit1st['subjectsCount'] ?? 'null'));
assertCheck("BSIT 1st Year totalLabFee is 3000", ($bsit1st['totalLabFee'] ?? 0) == 3000, "Lab Fee: " . ($bsit1st['totalLabFee'] ?? 'null'));

// 2. Test track.php API response for existing student
$stmt = $pdo->query("SELECT temp_student_id, temp_pin, course_code FROM pre_enrollments ORDER BY id DESC LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    $tempId = $row['temp_student_id'];
    $pin = $row['temp_pin'];
    $trackUrl = 'http://127.0.0.1/systemtest/enrollment-system/backend/track.php?id=' . urlencode($tempId) . '&pin=' . urlencode($pin);
    $trackResp = file_get_contents($trackUrl);
    $trackJson = json_decode($trackResp, true);
    $trackData = $trackJson['data'] ?? [];

    assertCheck("track.php returns success for applicant $tempId", isset($trackJson['success']) && $trackJson['success'] === true);
    assertCheck("track.php includes payment block with totalFee", isset($trackData['payment']['totalFee']));
    assertCheck("track.php payment totalFee is numeric and > 0", is_numeric($trackData['payment']['totalFee']) && $trackData['payment']['totalFee'] > 0, "totalFee: " . ($trackData['payment']['totalFee'] ?? 'null'));
    assertCheck("track.php exposes courseCode", isset($trackData['courseCode']), "courseCode: " . ($trackData['courseCode'] ?? 'null'));
    assertCheck("track.php exposes yearLevel", isset($trackData['yearLevel']), "yearLevel: " . ($trackData['yearLevel'] ?? 'null'));
}

echo "\n=== SUMMARY: Passed: $passed | Failed: $failed ===\n";
exit($failed > 0 ? 1 : 0);
