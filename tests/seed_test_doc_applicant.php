<?php
require_once __DIR__ . '/../shared/backend/config/database.php';

$pdo = Database::getInstance();

$testRef = "GNCP-TEST-DOC01";
$pdo->prepare("DELETE FROM pre_enrollments WHERE temp_student_id = :ref")->execute([':ref' => $testRef]);

$reqData = [
    'status' => 'PENDING',
    'requirements' => [
        'Form 138 (Original Senior High School Report Card)',
        'Original Certificate of Good Moral Character (with dry seal)',
        'PSA Birth Certificate (Photocopy)',
        '2 pieces recent 2x2 color pictures (white background with name tag)'
    ],
    'docs' => [
        'reportCard' => ['status' => 'ORIGINAL', 'deadline' => null, 'remarks' => ''],
        'psa' => ['status' => 'PHOTOCOPY', 'deadline' => null, 'remarks' => ''],
        'goodMoral' => ['status' => 'NOT_SUBMITTED', 'deadline' => null, 'remarks' => ''],
        '2_pieces_recent_2x2_color_pictures_white_background_with_name_tag' => ['status' => 'NOT_SUBMITTED', 'deadline' => null, 'remarks' => '']
    ],
    'files' => [
        'reportCard' => [
            'fileName' => 'test_report_card.png',
            'filePath' => 'uploads/documents/test_report_card.png',
            'fileType' => 'image/png',
            'uploadedAt' => date('Y-m-d H:i:s')
        ],
        'psa' => [
            'fileName' => 'sample_psa_birth_cert.pdf',
            'filePath' => 'uploads/documents/sample_psa_birth_cert.pdf',
            'fileType' => 'application/pdf',
            'uploadedAt' => date('Y-m-d H:i:s')
        ]
    ],
    'transmittal' => [
        'form137Status' => 'NOT_SENT',
        'dateDispatched' => '',
        'originSchool' => 'UST Senior High School',
        'remarks' => ''
    ]
];

$roadmap = [
    ['stepId' => 'online_registration', 'status' => 'COMPLETED', 'updatedAt' => date('c')],
    ['stepId' => 'registrar_verification', 'status' => 'PENDING', 'updatedAt' => null],
    ['stepId' => 'advising_assessment', 'status' => 'PENDING', 'updatedAt' => null],
    ['stepId' => 'clinic_checkup', 'status' => 'PENDING', 'updatedAt' => null],
    ['stepId' => 'cashier_payment', 'status' => 'PENDING', 'updatedAt' => null],
    ['stepId' => 'id_email_final', 'status' => 'PENDING', 'updatedAt' => null]
];

$stmt = $pdo->prepare("INSERT INTO pre_enrollments (
    temp_student_id, temp_pin, student_type, course_code, nstp,
    first_name, middle_name, last_name, email, phone, birth_date, gender, address,
    senior_high_school, shs_track, status, requirements_data, roadmap, created_at
) VALUES (
    :ref, '123456', 'FRESHMAN', 'BSIT', 'ROTC',
    'Elena', 'Rostova', 'Vasilyev', 'elena.rostova@gncp.edu.ph', '09171112233', '2005-06-15', 'Female', '123 Test St, QC',
    'UST Senior High School', 'STEM', 'PRE_REGISTERED', :reqData, :roadmap, NOW()
)");

$stmt->execute([
    ':ref' => $testRef,
    ':reqData' => json_encode($reqData),
    ':roadmap' => json_encode($roadmap)
]);

echo "SUCCESS: Seeded test applicant $testRef with softcopy documents.\n";
