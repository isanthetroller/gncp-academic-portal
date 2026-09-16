<?php
require_once __DIR__ . '/../shared/backend/config/database.php';
$pdo = Database::getInstance();
$hash = password_hash('Password123!', PASSWORD_DEFAULT);
$stmt = $pdo->prepare("UPDATE `students` SET `password` = :pw, `must_change_password` = 0 WHERE `id` = '2026-1006'");
$stmt->execute(['pw' => $hash]);
echo "Updated student 2026-1006 with password 'Password123!'\n";
