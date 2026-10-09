<?php
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/borrowing_cart.php';
$db=(new Database())->getConnection();$mode=$argv[1] ?? '';
class HoldCartStockStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params=null):bool {
        $result=parent::execute($params);
        if (($GLOBALS['holdCartStock'] ?? false) && str_contains($this->queryString,'FROM equipment WHERE equipment_id = ? FOR UPDATE')) {
            $GLOBALS['holdCartStock']=false;echo "LOCKED\n";flush();usleep(750000);
        }
        return $result;
    }
}
if ($mode==='setup') {
    requireCartSchema($db);
    $role=$db->query("SELECT role_id FROM roles WHERE role_name='customer'")->fetchColumn();$users=[];$equipment=[];
    foreach ([1,2] as $index) {
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$role,'Cart batch concurrency fixture',uniqid('batch-race-').'@example.invalid',password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);
        $users[]=(int)$db->lastInsertId();
        $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,1,1,7)')->execute(['Cart batch concurrency fixture']);
        $equipment[]=(int)$db->lastInsertId();
    }
    $pickup=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());
    foreach ($users as $user) foreach ($equipment as $id) mutateBorrowingCart($db,$user,'POST',['equipment_id'=>$id,'quantity'=>1,'borrow_date'=>$pickup->format('Y-m-d'),'expected_return_date'=>$pickup->modify('+1 day')->format('Y-m-d')]);
    $carts=[];foreach($users as $user)$carts[$user]=readBorrowingCart($db,$user);
    echo json_encode(['users'=>$users,'equipment'=>$equipment,'key'=>bin2hex(random_bytes(16)),'carts'=>$carts]);
} elseif ($mode==='worker') {
    $fixture=json_decode($argv[2],true);$second=($argv[3] ?? '')==='second';$same=($argv[4] ?? '')==='same';
    $user=$second&&!$same?$fixture['users'][1]:$fixture['users'][0];
    $key=$second&&!$same?str_repeat('b',32):$fixture['key'];
    $pickup=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());
    $body=['idempotency_key'=>$key,'items'=>array_map(fn($row)=>['cart_item_id'=>$row['cart_item_id'],'equipment_id'=>$row['equipment_id'],'requested_quantity'=>$row['quantity'],'borrow_date'=>$row['borrow_date'],'expected_return_date'=>$row['expected_return_date']],$fixture['carts'][$user])];
    if (!$second) { $GLOBALS['holdCartStock']=true;$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[HoldCartStockStatement::class]); }
    try { echo json_encode(checkoutBorrowingCart($db,$user,$body))."\n"; }
    catch (BorrowingFailure $e) { if ($e->httpStatus!==409) throw $e;echo json_encode(['success'=>false,'code'=>$e->errorCode])."\n"; }
} elseif ($mode==='verify') {
    $fixture=json_decode($argv[2],true);
    $stmt=$db->prepare('SELECT COUNT(*) FROM borrowing_checkouts WHERE user_id IN (?,?)');$stmt->execute($fixture['users']);
    if ((int)$stmt->fetchColumn()!==1) throw new RuntimeException('Concurrency created duplicate groups.');
    $stmt=$db->prepare('SELECT COUNT(*) FROM borrowing_requests WHERE user_id IN (?,?)');$stmt->execute($fixture['users']);
    if ((int)$stmt->fetchColumn()!==2) throw new RuntimeException('Concurrency created duplicate items.');
    foreach ($fixture['equipment'] as $id) {
        $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$id]);
        if ((int)$stmt->fetchColumn()!==0) throw new RuntimeException('Concurrent stock deduction incorrect.');
    }
    if (count(readBorrowingCart($db,$fixture['users'][1]))!==2) throw new RuntimeException('Losing/unselected cart was changed.');
    echo 'PASS';
} elseif ($mode==='cleanup') {
    $fixture=json_decode($argv[2],true);
    foreach ($fixture['equipment'] as $id) {
        $db->prepare('DELETE FROM borrowing_requests WHERE equipment_id=?')->execute([$id]);
        $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name=?')->execute([$id,'Cart batch concurrency fixture']);
    }
    foreach ($fixture['users'] as $id) {
        $db->prepare('DELETE FROM borrowing_checkouts WHERE user_id=?')->execute([$id]);
        $db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$id,'Cart batch concurrency fixture']);
    }
} else throw new RuntimeException('Invalid mode.');
