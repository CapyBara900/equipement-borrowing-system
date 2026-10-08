<?php
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/borrowing_submission.php';
$db=(new Database())->getConnection();
$mode=$argv[1] ?? '';
if ($mode==='setup') {
    $role=$db->query("SELECT role_id FROM roles WHERE role_name='customer'")->fetchColumn();
    $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$role,'Cart concurrency fixture',uniqid('cart-concurrency-').'@example.invalid',password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);
    $user=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,1,1,7)')->execute(['Cart concurrency fixture']);
    echo json_encode(['user_id'=>$user,'equipment_id'=>(int)$db->lastInsertId()]);
} elseif ($mode==='worker') {
    $user=(int)$argv[2]; $equipment=(int)$argv[3];
    $pickup=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());
    try {
        $db->beginTransaction();
        createBorrowingRequests($db,$user,[['equipment_id'=>$equipment,'requested_quantity'=>1]],$pickup,$pickup->modify('+1 day'));
        if (($argv[4] ?? '')==='hold') { echo "LOCKED\n"; flush(); usleep(750000); }
        $db->commit(); echo "SUBMITTED\n";
    } catch (BorrowingFailure $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($e->httpStatus!==409) throw $e;
        echo "INSUFFICIENT_STOCK\n";
    }
} elseif ($mode==='verify') {
    $equipment=(int)$argv[3];
    $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?'); $stmt->execute([$equipment]);
    $stock=(int)$stmt->fetchColumn();
    $stmt=$db->prepare('SELECT COUNT(*) FROM borrowing_requests WHERE equipment_id=?'); $stmt->execute([$equipment]);
    if ($stock!==0 || (int)$stmt->fetchColumn()!==1) throw new RuntimeException('Concurrent submission exceeded stock.');
    echo "PASS: two concurrent MySQL connections submitted exactly one request for the last unit.\n";
} elseif ($mode==='cleanup') {
    $user=(int)$argv[2]; $equipment=(int)$argv[3];
    $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name=?')->execute([$equipment,'Cart concurrency fixture']);
    $db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$user,'Cart concurrency fixture']);
} else throw new RuntimeException('Unknown test mode.');
