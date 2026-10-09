<?php
// Real database constraints, rollback-only fixtures, and migration resume validation.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../database/borrowing_management_migration.php';
function databaseVerify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }
function databaseReject(PDO $db, string $sql, array $params, array $codes): void
{
    try { $db->prepare($sql)->execute($params); }
    catch (PDOException $error) {
        databaseVerify(in_array((int)($error->errorInfo[1] ?? 0), $codes, true), 'Unexpected database error: ' . $error->getCode() . '/' . ($error->errorInfo[1] ?? '') . ' for ' . $sql);
        return;
    }
    throw new RuntimeException('Database accepted invalid operation: ' . $sql);
}
function databaseRejectMetadata(callable $operation): void
{
    try { $operation(); } catch (RuntimeException $error) { return; }
    throw new RuntimeException('Migration accepted incompatible metadata.');
}
$db = (new Database())->getConnection();
$baseline = historyMigrationSnapshot($db);
$metadata = managementMigrationMetadata($db);
managementMigrationPreflight($db, $metadata);
databaseVerify(!array_filter(managementMigrationPlan($metadata), fn($step) => !$step['skip']), 'Migration is not fully applied.');
$rerun = runBorrowingManagementMigration($db);
databaseVerify($rerun['fingerprints_verified'] && $rerun['backup_path'] === null && $rerun['management_available'], 'Rerun changed data or failed readiness.');
$bad = $metadata; $bad['borrowing_checkouts']['indexes']['uq_checkouts_owner']['columns'] = ['user_id'];
databaseRejectMetadata(fn() => managementMigrationPlan($bad));
$bad = $metadata; $bad['borrowing_requests']['checks']['chk_request_dates'] = '1=1';
databaseRejectMetadata(fn() => managementMigrationPlan($bad));
$bad = $metadata; $bad['borrowing_status_history']['columns']['changed_at']['COLUMN_TYPE'] = 'date';
databaseRejectMetadata(fn() => managementMigrationVerifyAudit($bad));
$bad = $metadata; $bad['borrowing_status_history']['foreign']['fk_borrowing_history_actor']['delete'] = 'CASCADE';
databaseRejectMetadata(fn() => managementMigrationVerifyAudit($bad));
$bad = $metadata; $bad['borrowing_status_history']['indexes']['uq_borrowing_history_request_status']['non_unique'] = 1;
databaseRejectMetadata(fn() => managementMigrationVerifyAudit($bad));
$db->beginTransaction();
try {
    $actors = [];
    foreach (['customer','customer','admin'] as $role) {
        $query = $db->prepare('SELECT role_id FROM roles WHERE role_name=?'); $query->execute([$role]);
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$query->fetchColumn(), 'Rollback database fixture', bin2hex(random_bytes(16)) . '@example.invalid', 'unused-fixture-hash']);
        $actors[] = (int)$db->lastInsertId();
    }
    [$owner,$other,$admin] = $actors;
    $db->exec("INSERT INTO equipment(equipment_name,total_quantity,available_quantity) VALUES('Rollback database fixture',5,1)"); $equipment = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO borrowing_checkouts(user_id,idempotency_key,payload_hash,borrow_date,expected_return_date) VALUES(?,?,?,?,?)')->execute([$owner,bin2hex(random_bytes(16)),str_repeat('a',64),'2026-10-09','2026-10-11']); $checkout = (int)$db->lastInsertId();
    $insert = 'INSERT INTO borrowing_requests(user_id,equipment_id,checkout_id,requested_quantity,borrow_date,expected_return_date) VALUES(?,?,?,?,?,?)';
    $db->prepare($insert)->execute([$owner,$equipment,$checkout,2,'2026-10-09','2026-10-11']); $request = (int)$db->lastInsertId();
    $db->prepare($insert)->execute([$owner,$equipment,$checkout,1,'2026-10-10','2026-10-11']); $second = (int)$db->lastInsertId();
    $db->prepare($insert)->execute([$owner,$equipment,null,1,'2026-10-09','2026-10-09']); $standalone = (int)$db->lastInsertId();
    databaseReject($db, $insert, [$other,$equipment,$checkout,1,'2026-10-11','2026-10-11'], [1452]);
    databaseReject($db, $insert, [$owner,$equipment,$checkout,1,'2026-10-09','2026-10-11'], [1062]);
    databaseReject($db, $insert, [$owner,$equipment,null,0,'2026-10-09','2026-10-11'], [4025,3819]);
    databaseReject($db, $insert, [$owner,$equipment,null,1,'2026-10-11','2026-10-09'], [4025,3819]);
    databaseReject($db, 'UPDATE equipment SET available_quantity=-1 WHERE equipment_id=?', [$equipment], [1264,4025,3819]);
    databaseReject($db, 'UPDATE equipment SET available_quantity=6 WHERE equipment_id=?', [$equipment], [4025,3819]);
    databaseReject($db, 'UPDATE equipment SET total_quantity=0 WHERE equipment_id=?', [$equipment], [4025,3819]);
    databaseReject($db, "UPDATE borrowing_requests SET status='unknown' WHERE request_id=?", [$request], [1265,1366]);
    databaseReject($db, 'DELETE FROM users WHERE user_id=?', [$owner], [1451]);
    databaseReject($db, 'DELETE FROM equipment WHERE equipment_id=?', [$equipment], [1451]);
    databaseReject($db, 'DELETE FROM borrowing_checkouts WHERE checkout_id=?', [$checkout], [1451]);
    $auditInsert = 'INSERT INTO borrowing_status_history(request_id,from_status,to_status,changed_by_user_id,changed_at) VALUES(?,?,?,?,?)';
    databaseReject($db, $auditInsert, [$request,'pending','returned',$admin,'2026-10-09 09:00:00'], [4025,3819]);
    databaseReject($db, $auditInsert, [$request,'approved','cancelled',$admin,'2026-10-09 09:00:00'], [4025,3819]);
    databaseReject($db, $auditInsert, [2147483647,'pending','approved',$admin,'2026-10-09 09:00:00'], [1452]);
    databaseReject($db, $auditInsert, [$request,'pending','approved',2147483647,'2026-10-09 09:00:00'], [1452]);
    foreach ([['pending','approved'],['approved','borrowed'],['borrowed','returned']] as [$from,$to]) {
        if ($to === 'borrowed') $db->prepare("UPDATE borrowing_requests SET status='borrowed',picked_up_at='2026-10-09 09:00:00',picked_up_by_staff_id=? WHERE request_id=?")->execute([$admin,$request]);
        else $db->prepare('UPDATE borrowing_requests SET status=? WHERE request_id=?')->execute([$to,$request]);
        $db->prepare($auditInsert)->execute([$request,$from,$to,$admin,'2026-10-09 09:00:00']);
    }
    databaseReject($db, $auditInsert, [$request,'pending','approved',$admin,'2026-10-09 10:00:00'], [1062]);
    databaseReject($db, 'DELETE FROM borrowing_requests WHERE request_id=?', [$request], [1451]);
    databaseReject($db, 'DELETE FROM users WHERE user_id=?', [$admin], [1451]);
    $db->prepare('INSERT INTO returns(request_id,processed_by_staff_id,returned_quantity) VALUES(?,?,2)')->execute([$request,$admin]);
    databaseReject($db, 'INSERT INTO returns(request_id,processed_by_staff_id,returned_quantity) VALUES(?,?,2)', [$request,$admin], [1062]);
    databaseReject($db, 'INSERT INTO returns(request_id,processed_by_staff_id,returned_quantity) VALUES(?,?,0)', [$second,$admin], [4025,3819]);
    $db->prepare($auditInsert)->execute([$second,'pending','rejected',$admin,'2026-10-09 09:00:00']);
    $db->prepare("UPDATE borrowing_requests SET status='rejected' WHERE request_id=?")->execute([$second]);
    $db->prepare($auditInsert)->execute([$standalone,'pending','cancelled',$owner,'2026-10-09 09:00:00']);
    $db->prepare("UPDATE borrowing_requests SET status='cancelled' WHERE request_id=?")->execute([$standalone]);
    $rows = $db->query('SELECT request_id,checkout_id,requested_quantity,borrow_date,expected_return_date,status FROM borrowing_requests WHERE equipment_id=' . $equipment . ' ORDER BY request_id')->fetchAll();
    databaseVerify(count($rows) === 3 && (int)$rows[0]['requested_quantity'] === 2 && $rows[0]['borrow_date'] !== $rows[1]['borrow_date'], 'Grouping lost item quantities/dates.');
    $references = array_unique(array_map(fn($row) => $row['checkout_id'] === null ? 'Request #' . $row['request_id'] : 'Checkout #' . $row['checkout_id'], $rows));
    databaseVerify(count($references) === 2, 'Transaction references collide or split a checkout.');
    $query = $db->prepare('SELECT COUNT(*),MIN(changed_at),MAX(changed_by_user_id) FROM borrowing_status_history WHERE request_id=?'); $query->execute([$request]); $history = $query->fetch(PDO::FETCH_NUM);
    databaseVerify((int)$history[0] === 3 && $history[1] === '2026-10-09 09:00:00' && (int)$history[2] === $admin, 'Audit lost administrator/time evidence.');
} finally { $db->rollBack(); }
databaseVerify(historyMigrationSnapshot($db, $baseline) === $baseline, 'Rollback-only tests altered existing data.');
echo "PASS: migration rerun, incompatible-object rejection, checkout ownership, stable references, independent dates/quantities, stock/date/status bounds, restrictive history relationships, precise audit actors/times, legal audit transitions and duplicate protection. All fixtures rolled back.\n";
