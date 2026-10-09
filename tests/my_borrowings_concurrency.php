<?php
require __DIR__.'/../config/database.php';
require __DIR__.'/../includes/auth_middleware.php';
require __DIR__.'/../includes/notifications.php';
require __DIR__.'/../includes/borrowing_history.php';
class PickupRaceStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params=null):bool {
        $result=parent::execute($params);
        if (($GLOBALS['holdPickupRow'] ?? false) && str_contains($this->queryString,'FROM borrowing_requests WHERE request_id =') && str_contains($this->queryString,'FOR UPDATE')) {
            $GLOBALS['holdPickupRow']=false;echo "LOCKED\n";flush();usleep(750000);
        }
        return $result;
    }
}
class RaceApiResult extends Exception { public function __construct(public int $status,public array $payload) {} }
function sendJson(int $status,array $payload):void { throw new RaceApiResult($status,$payload); }
function getJsonBody():array { return $GLOBALS['raceBody']; }
function cleanText(?string $value):string { return trim(strip_tags($value ?? '')); }
$db=(new Database())->getConnection();$mode=$argv[1] ?? '';
if ($mode==='setup') {
    if (!borrowingHistorySchema($db)['management_available']) { echo json_encode(['skip'=>true,'reason'=>'Part 3 audit storage is required for MySQL administrator concurrency checks.']); exit; }
    $users=[];
    $firstRole=$argv[2] ?? 'admin';
    if (!in_array($firstRole, BORROWING_MANAGEMENT_ROLES, true)) throw new RuntimeException('Invalid management role.');
    foreach(['customer',$firstRole,'admin'] as $role) {
        $stmt=$db->prepare('SELECT role_id FROM roles WHERE role_name=?');$stmt->execute([$role]);
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$stmt->fetchColumn(),'My Borrowings race fixture',uniqid('pickup-race-').'@example.invalid',password_hash('FixturePassword123!',PASSWORD_DEFAULT)]);
        $users[]=(int)$db->lastInsertId();
    }
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,2,2,7)')->execute(['My Borrowings race fixture']);$equipment=(int)$db->lastInsertId();
    $today=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());
    $db->beginTransaction();$requests=createBorrowingRequests($db,$users[0],[['equipment_id'=>$equipment,'requested_quantity'=>2]],$today,$today->modify('+1 day'));
    $request=$requests[0]['request_id'];$db->commit();
    echo json_encode(['users'=>$users,'equipment'=>$equipment,'request'=>$request,'first_role'=>$firstRole]);
} elseif ($mode==='worker') {
    $fixture=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);$kind=$argv[4];
    if ($argv[3]==='first') {$GLOBALS['holdPickupRow']=true;$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PickupRaceStatement::class]);}
    $_SESSION=['user_id'=>$fixture['users'][$argv[3]==='first'?1:2],'name'=>'Fixture','email'=>'fixture@example.invalid','role'=>$argv[3]==='first'?($fixture['first_role'] ?? 'admin'):'admin'];$_GET=[];
    try {
        if ($kind==='approve' || $kind==='pickup') {$result=changeBorrowingStatus($db,$fixture['request'],$kind==='approve'?'approved':'borrowed');echo json_encode(['status'=>200,'data'=>$result['data']])."\n";}
        else {
            $_SERVER['REQUEST_METHOD']='POST';$GLOBALS['raceBody']=['request_id'=>$fixture['request'],'condition_status'=>'good'];
            $source=preg_replace('/^<\?php/','',file_get_contents(__DIR__.'/../api/returns/index.php'));$source=preg_replace('/require_once[^;]+;/','',$source);eval($source);
        }
    } catch(BorrowingFailure $error) {echo json_encode(['status'=>$error->httpStatus,'code'=>$error->errorCode])."\n";}
      catch(RaceApiResult $result) {echo json_encode(['status'=>$result->status,'data'=>$result->payload['data'] ?? null])."\n";}
} elseif ($mode==='verify') {
    $fixture=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);$kind=$argv[3];
    $stmt=$db->prepare('SELECT status,picked_up_at,picked_up_by_staff_id FROM borrowing_requests WHERE request_id=?');$stmt->execute([$fixture['request']]);$row=$stmt->fetch();
    if ($row['status']!==['approve'=>'approved','pickup'=>'borrowed','return'=>'returned'][$kind])throw new RuntimeException('Concurrent status incorrect.');
    if ($kind==='approve' ? $row['picked_up_at']!==null : ($row['picked_up_at']===null || (int)$row['picked_up_by_staff_id']!==$fixture['users'][1]))throw new RuntimeException('Concurrent pickup attribution incorrect.');
    $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$fixture['equipment']]);if((int)$stmt->fetchColumn()!==($kind==='return'?2:0))throw new RuntimeException('Concurrent inventory update incorrect.');
    $stmt=$db->prepare('SELECT COUNT(*) FROM returns WHERE request_id=?');$stmt->execute([$fixture['request']]);if((int)$stmt->fetchColumn()!==($kind==='return'?1:0))throw new RuntimeException('Duplicate concurrent return.');
    $count=['approve'=>1,'pickup'=>2,'return'=>3][$kind];
    $stmt=$db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=?');$stmt->execute([$fixture['users'][0]]);if((int)$stmt->fetchColumn()!==$count)throw new RuntimeException('Duplicate concurrent notification.');
    $stmt=$db->prepare('SELECT COUNT(*) FROM borrowing_status_history WHERE request_id=?');$stmt->execute([$fixture['request']]);if((int)$stmt->fetchColumn()!==$count)throw new RuntimeException('Concurrent audit event count incorrect.');
    echo 'PASS';
} elseif ($mode==='cleanup') {
    $fixture=json_decode($argv[2],true,512,JSON_THROW_ON_ERROR);
    $db->prepare('DELETE FROM borrowing_status_history WHERE request_id=?')->execute([$fixture['request']]);
    $db->prepare('DELETE FROM returns WHERE request_id=?')->execute([$fixture['request']]);
    $db->prepare('DELETE FROM equipment_condition_reports WHERE equipment_id=?')->execute([$fixture['equipment']]);
    $db->prepare('DELETE FROM borrowing_requests WHERE request_id=?')->execute([$fixture['request']]);
    $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name=?')->execute([$fixture['equipment'],'My Borrowings race fixture']);
    foreach($fixture['users'] as $id)$db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$id,'My Borrowings race fixture']);
} else throw new RuntimeException('Invalid mode.');
