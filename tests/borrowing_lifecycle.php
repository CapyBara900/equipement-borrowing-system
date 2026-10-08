<?php
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/auth_middleware.php';
require __DIR__.'/../includes/borrowing_submission.php';
require __DIR__.'/../includes/notifications.php';
class LifecycleResult extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status, array $payload): void { throw new LifecycleResult($status,$payload); }
function getJsonBody(): array { return $GLOBALS['testBody']; }
function cleanText(?string $value): string { return trim(strip_tags($value ?? '')); }
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function endpoint(string $path,string $method,array $body=[],array $query=[],string $role='customer'): LifecycleResult {
    global $db,$userId;
    $_SERVER['REQUEST_METHOD']=$method; $_GET=$query; $GLOBALS['testBody']=$body;
    $_SESSION=['user_id'=>$userId,'name'=>'Fixture','email'=>'fixture@example.invalid','role'=>$role];
    $source=preg_replace('/^<\?php/','',file_get_contents(__DIR__.'/../api/'.$path.'/index.php'));
    $source=preg_replace('/require_once[^;]+;/','',$source);
    $source=str_replace(['const REQUEST_SELECT','REQUEST_SELECT','const RETURN_SELECT','RETURN_SELECT'],['$requestSelect','$requestSelect','$returnSelect','$returnSelect'],$source);
    try { eval($source); } catch (LifecycleResult $result) { return $result; }
    throw new RuntimeException('Missing API response.');
}
$db=(new Database())->getConnection(); $userId=null; $equipmentId=null;
try {
    $roleId=$db->query("SELECT role_id FROM roles WHERE role_name='customer'")->fetchColumn();
    $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$roleId,'Cart lifecycle fixture',uniqid('cart-lifecycle-').'@example.invalid',password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);
    $userId=(int)$db->lastInsertId();
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,5,5,7)')->execute(['Cart lifecycle fixture']);
    $equipmentId=(int)$db->lastInsertId();
    $pickup=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());
    $stock=function() use($db,$equipmentId) { $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$equipmentId]);return (int)$stmt->fetchColumn(); };
    foreach (['reject','cancel','return','damaged'] as $action) {
        $db->beginTransaction();
        $requests=createBorrowingRequests($db,$userId,[['equipment_id'=>$equipmentId,'requested_quantity'=>2]],$pickup,$pickup->modify('+1 day'));
        $db->commit(); $id=$requests[0]['request_id'];
        verify($stock()===3,'Pending requests did not reserve units');
        $list=endpoint('requests','GET',[],['id'=>$id]);
        verify($list->status===200 && (int)$list->payload['data']['requested_quantity']===2 && $list->payload['data']['borrow_date']===$pickup->format('Y-m-d') && $list->payload['data']['status']==='pending' && $list->payload['data']['equipment_name']==='Cart lifecycle fixture','My Borrowings details incorrect');
        if ($action==='reject') {
            verify(endpoint('requests','PUT',['request_id'=>$id,'status'=>'rejected'],[],'staff')->status===200,'Reject failed');
            verify(endpoint('requests','PUT',['request_id'=>$id,'status'=>'rejected'],[],'staff')->status===409,'Repeated rejection allowed');
        } elseif ($action==='cancel') {
            verify(endpoint('requests','DELETE',[],['id'=>$id])->status===200,'Cancel failed');
            verify(endpoint('requests','DELETE',[],['id'=>$id])->status===409,'Repeated cancellation allowed');
        } else {
            verify(endpoint('requests','PUT',['request_id'=>$id,'status'=>'approved'],[],'staff')->status===200,'Approval failed');
            verify($stock()===3,'Approval deducted twice');
            verify(endpoint('returns','POST',['request_id'=>$id,'condition_status'=>$action==='damaged'?'damaged':'good'],[],'staff')->status===201,'Return failed');
            verify(endpoint('returns','POST',['request_id'=>$id],[],'staff')->status===409,'Repeated return allowed');
        }
        verify($stock()===($action==='damaged'?3:5),'Restoration rule changed for '.$action);
    }
    echo "PASS: My Borrowings equipment/quantity/dates/status, pending reservation, no double deduction on approval, rejection/cancellation/good return restoration, damaged return withholding, and repeat protection.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($equipmentId) $db->prepare('DELETE FROM equipment WHERE equipment_id=?')->execute([$equipmentId]);
    if ($userId) $db->prepare('DELETE FROM users WHERE user_id=?')->execute([$userId]);
}
