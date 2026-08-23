<?php
require_once __DIR__ . '/../shared/backend/config/database.php';
require_once __DIR__ . '/../stations/backend/services/QueueService.php';
$pdo = Database::getInstance();
$q = QueueService::fetchQueue($pdo);
echo "Queue count: " . count($q) . "\n";
foreach($q as $s) {
    echo $s['referenceNumber'] . " | " . $s['name'] . " | " . $s['status'] . "\n";
}
