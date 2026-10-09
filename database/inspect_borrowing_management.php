<?php
// Read-only schema inventory. Does not emit customer data or credentials.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/my_borrowings_migration.php';
$db = (new Database())->getConnection();
echo 'Server: ' . $db->query('SELECT VERSION()')->fetchColumn() . "\n";
foreach (['users','equipment','borrowing_requests','borrowing_checkouts','borrowing_cart_items','returns','equipment_condition_reports','borrowing_status_history'] as $table) {
    $exists = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $exists->execute([$table]);
    if (!(int)$exists->fetchColumn()) { echo "$table: absent\n"; continue; }
    echo $db->query('SHOW CREATE TABLE ' . historyMigrationIdentifier($table))->fetch(PDO::FETCH_NUM)[1] . ";\n";
}
$snapshot = historyMigrationSnapshot($db);
echo json_encode(['fingerprints' => $snapshot, 'capabilities' => borrowingHistorySchema($db)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
if (in_array('--recent-summary', $argv, true)) {
    // IDs and fixture flags distinguish live activity without printing names/emails.
    echo json_encode($db->query("SELECT r.request_id,r.equipment_id,r.request_date,r.requested_quantity,r.status,r.checkout_id,(u.name LIKE '%fixture%') AS fixture_customer,(e.equipment_name LIKE '%fixture%') AS fixture_equipment FROM borrowing_requests r JOIN users u ON u.user_id=r.user_id JOIN equipment e ON e.equipment_id=r.equipment_id ORDER BY r.request_id DESC LIMIT 3")->fetchAll(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
}
