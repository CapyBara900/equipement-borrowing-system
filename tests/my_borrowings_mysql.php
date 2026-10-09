<?php
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/borrowing_history.php';
require __DIR__ . '/../includes/category_management.php';
require __DIR__ . '/../database/my_borrowings_migration.php';
class MigrationApiResult extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status,array $payload):void { throw new MigrationApiResult($status,$payload); }
function getJsonBody():array { return $GLOBALS['migrationTestBody']; }
function cleanText(?string $value):string { return trim(strip_tags($value ?? '')); }
function verifyMigration(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function dbRejects(callable $action,string $message):void { try { $action(); } catch (PDOException $error) { return; } throw new RuntimeException($message); }
function migrationApi(string $path,string $method,array $body=[],array $query=[],string $role='staff',?int $id=null):MigrationApiResult {
    global $db,$customer,$staffId;
    $_SESSION=['user_id'=>$id ?? ($role==='customer' ? $customer : $staffId),'name'=>'Migration fixture','email'=>'fixture@example.invalid','role'=>$role];
    $_SERVER['REQUEST_METHOD']=$method;$_GET=$query;$GLOBALS['migrationTestBody']=$body;
    $source=preg_replace('/^<\?php/','',file_get_contents(__DIR__.'/../api/'.$path.'/index.php'));
    $source=preg_replace('/require_once[^;]+;/','',$source);
    $source=str_replace(['const RETURN_SELECT','RETURN_SELECT','const EQUIPMENT_SELECT','EQUIPMENT_SELECT'],['$returnSelect','$returnSelect','$equipmentSelect','$equipmentSelect'],$source);
    try { eval($source); } catch (MigrationApiResult $result) { return $result; }
    throw new RuntimeException('Missing API response.');
}
$db=(new Database())->getConnection();$users=[];$equipment=[];$customer=null;$staffId=null;
try {
    verifyMigration(borrowingHistorySchema($db)['pickup_available'],'Pickup schema is not ready');
    $before=historyMigrationSnapshot($db);$rerun=runMyBorrowingsMigration($db);
    verifyMigration(!array_filter($rerun['steps'],fn($step)=>$step['action']!=='SKIP'),'Migration rerun changed schema');
    verifyMigration($before===historyMigrationSnapshot($db),'Migration rerun changed records');
    foreach (['customer','staff'] as $role) {
        $lookup=$db->prepare('SELECT role_id FROM roles WHERE role_name=?');$lookup->execute([$role]);
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$lookup->fetchColumn(),'My Borrowings schema fixture',uniqid('schema-borrowing-').'@example.invalid',password_hash('FixturePassword123!',PASSWORD_DEFAULT)]);
        $users[]=(int)$db->lastInsertId();
    }
    [$customer,$staffId]=$users;
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,5,5,7)')->execute(['My Borrowings schema fixture']);
    $equipment[]=(int)$db->lastInsertId();$item=$equipment[0];
    $start=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());$date=$start->format('Y-m-d');$returned=$start->modify('+1 day')->format('Y-m-d');
    $stock=function()use($db,$item):int{$stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$item]);return(int)$stmt->fetchColumn();};
    $newRequest=function(int $quantity=2)use($db,$customer,$item,$start):int{$db->beginTransaction();$rows=createBorrowingRequests($db,$customer,[['equipment_id'=>$item,'requested_quantity'=>$quantity]],$start,$start->modify('+1 day'));$db->commit();return$rows[0]['request_id'];};
    $id=$newRequest();verifyMigration($stock()===3,'Pending reservation failed');
    verifyMigration(migrationApi('requests','PUT',['request_id'=>$id,'status'=>'approved'])->status===200,'Approval failed');
    verifyMigration($stock()===3,'Approval deducted twice');
    dbRejects(fn()=>$db->prepare("UPDATE borrowing_requests SET status='borrowed' WHERE request_id=?")->execute([$id]),'Borrowed without pickup evidence accepted');
    dbRejects(fn()=>$db->prepare('UPDATE borrowing_requests SET picked_up_at=? WHERE request_id=?')->execute([$date.' 12:00:00',$id]),'Partial pickup evidence accepted');
    dbRejects(fn()=>$db->prepare("UPDATE borrowing_requests SET status='borrowed',picked_up_at=?,picked_up_by_staff_id=? WHERE request_id=?")->execute([$start->modify('-1 day')->format('Y-m-d').' 12:00:00',$staffId,$id]),'Pickup outside agreed dates accepted');
    dbRejects(fn()=>$db->prepare("UPDATE borrowing_requests SET status='borrowed',picked_up_at=?,picked_up_by_staff_id=? WHERE request_id=?")->execute([$date.' 12:00:00',2147483647,$id]),'Unknown pickup actor accepted');
    dbRejects(fn()=>$db->prepare('UPDATE borrowing_requests SET requested_quantity=0 WHERE request_id=?')->execute([$id]),'Zero request quantity accepted');
    dbRejects(fn()=>$db->prepare('UPDATE borrowing_requests SET user_id=? WHERE request_id=?')->execute([2147483647,$id]),'Broken customer relationship accepted');
    dbRejects(fn()=>$db->prepare('UPDATE borrowing_requests SET equipment_id=? WHERE request_id=?')->execute([2147483647,$id]),'Broken equipment relationship accepted');
    verifyMigration(migrationApi('requests','PUT',['request_id'=>$id,'action'=>'pickup'],[], 'customer')->status===403,'Customer could pick up');
    $pickup=migrationApi('requests','PUT',['request_id'=>$id,'action'=>'pickup']);
    verifyMigration($pickup->status===200 && $stock()===3,'Pickup failed or deducted twice');
    $detail=migrationApi('requests','GET',[],['id'=>$id],'customer')->payload['data'];
    verifyMigration($detail['status']==='borrowed' && $detail['picked_up_at']!==null && $detail['picked_up_by_staff_id']===$staffId,'Pickup evidence absent from history');
    verifyMigration(migrationApi('requests','GET',[],['status'=>'borrowed'],'customer')->payload['total']===1,'Borrowed tab cannot retrieve loan');
    verifyMigration(migrationApi('requests','PUT',['request_id'=>$id,'action'=>'pickup'])->status===409,'Repeated pickup accepted');
    dbRejects(fn()=>$db->prepare('DELETE FROM users WHERE user_id=?')->execute([$staffId]),'Pickup attribution was erased by staff deletion');
    $edit=['equipment_id'=>$item,'equipment_name'=>'My Borrowings schema fixture','total_quantity'=>5,'borrowing_time_limit_days'=>7,'status'=>'available','release_quantity'=>1];
    verifyMigration(migrationApi('equipment','PUT',$edit)->status===409,'Condition clearance released borrowed stock');
    // Old migrations must remain safe after an actual borrowed record exists.
    $beforeRerun=historyMigrationSnapshot($db);$php=PHP_BINARY;
    foreach (['migrate_borrowing_cart.php','migrate_cart_item_dates.php','migrate_my_borrowings.php'] as $script) {
        $command=escapeshellarg($php).' '.escapeshellarg(__DIR__.'/../database/'.$script);
        exec($command,$output,$exit);verifyMigration($exit===0,'Migration rerun failed: '.$script);
    }
    verifyMigration($beforeRerun===historyMigrationSnapshot($db),'Migration reruns changed borrowed records');
    verifyMigration(migrationApi('returns','POST',['request_id'=>$id,'condition_status'=>'good'])->status===201 && $stock()===5,'Good return failed');
    verifyMigration(migrationApi('returns','POST',['request_id'=>$id])->status===409 && $stock()===5,'Repeated return restored twice');
    $detail=migrationApi('requests','GET',[],['id'=>$id],'customer')->payload['data'];
    verifyMigration($detail['status']==='returned' && $detail['picked_up_by_staff_id']===$staffId,'Return removed pickup history');
    $bad=$newRequest();migrationApi('requests','PUT',['request_id'=>$bad,'status'=>'approved']);migrationApi('requests','PUT',['request_id'=>$bad,'action'=>'pickup']);
    verifyMigration(migrationApi('returns','POST',['request_id'=>$bad,'condition_status'=>'damaged'])->status===201 && $stock()===3,'Damaged return released held units');
    $cancel=$newRequest(1);verifyMigration(migrationApi('requests','DELETE',[],['id'=>$cancel],'customer')->status===200 && $stock()===3,'Cancellation stock restoration changed');
    $reject=$newRequest(1);verifyMigration(migrationApi('requests','PUT',['request_id'=>$reject,'status'=>'rejected'])->status===200 && $stock()===3,'Rejection stock restoration changed');
    verifyMigration(migrationApi('requests','GET',[],[],'customer')->payload['total']===4,'Repeated requests were merged');
    $plan=$db->prepare("EXPLAIN SELECT request_id,request_date FROM borrowing_requests WHERE user_id=? AND status='returned' ORDER BY request_date DESC,request_id DESC LIMIT 20");$plan->execute([$customer]);$plan=$plan->fetch();
    verifyMigration($plan['key']==='idx_requests_user_status_date' && !str_contains($plan['Extra'],'Using filesort'),'Customer status history index did not support filtering/sorting');
    echo "PASS: real MySQL pickup evidence/date/FK constraints, preserved request IDs, actor retention, approval/pickup/returns, inventory safety, status index, and old/new migration reruns.\n";
} finally {
    if ($db->inTransaction())$db->rollBack();
    foreach($equipment as $id)$db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name=?')->execute([$id,'My Borrowings schema fixture']);
    foreach($users as $id)$db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$id,'My Borrowings schema fixture']);
}
