<?php
// Exercise the real endpoint with an in-memory database fixture. No live database is accessed.
// Run: php tests/admin_password_reset.php
require __DIR__ . '/../includes/password_validation.php';
class FixtureResponse extends Exception { public function __construct(public int $status, public array $payload) { parent::__construct('response'); } }
function sendJson(int $status, array $payload): void { throw new FixtureResponse($status, $payload); }
function getJsonBody(): array { global $fixtureBody; return $fixtureBody; }
function requireLogin(): array { global $fixtureActor; if (!$fixtureActor) sendJson(401, ['success'=>false]); return $fixtureActor; }
function requireRole(array $roles): array { $actor = requireLogin(); if (!in_array($actor['role'],$roles,true)) sendJson(403,['success'=>false]); return $actor; }
const USER_SELECT = 'SELECT fixture users';
class FixtureDb {
 public bool $active = false;
 public bool $failUpdate = false;
 public array $user;
 public array $snapshot = [];
 function __construct() { $this->user = ['user_id'=>2,'name'=>'Alice Smith','email'=>'alice@example.invalid','password_hash'=>password_hash('ExistingSecure456!', PASSWORD_BCRYPT),'failed_login_attempts'=>2,'locked_until'=>'2099-01-01 00:00:00']; }
 function beginTransaction() { $this->active=true; $this->snapshot=$this->user; }
 function commit() { $this->active=false; }
 function rollBack() { $this->user=$this->snapshot; $this->active=false; }
 function inTransaction() { return $this->active; }
 function prepare($sql) { return new FixtureStatement($this,$sql); }
 function query($sql) { return new FixtureStatement($this,$sql); }
}
class FixtureStatement {
 public array $params=[];
 function __construct(public FixtureDb $db, public string $sql) {}
 function execute($params=[]) {
  $this->params=$params;
  if (str_starts_with($this->sql,'UPDATE users SET password_hash')) {
   if($this->db->failUpdate) throw new PDOException('fixture failure');
   $this->db->user['password_hash']=$params['password_hash'];
   $this->db->user['failed_login_attempts']=0;
   $this->db->user['locked_until']=null;
  }
 }
 function fetch() { return ($this->params['id'] ?? 0) === 2 ? $this->db->user : false; }
 function fetchAll() { return [array_diff_key($this->db->user, ['password_hash'=>true])]; }
}
function ensure($ok,$message) { if(!$ok) throw new RuntimeException($message); }
$source = file_get_contents(__DIR__ . '/../api/users/index.php');
$endpoint = substr($source, strpos($source, '$method ='));
$checks = 0;
function runCase($label,$actor,$body,$status,$fail=false) {
 global $db,$fixtureActor,$fixtureBody,$endpoint,$checks;
 $db=new FixtureDb(); $before=$db->user; $db->failUpdate=$fail;
 $fixtureActor=$actor; $fixtureBody=$body;
 $_SESSION=['admin_users_csrf_token'=>'fixture-token']; $_SERVER['REQUEST_METHOD']='PUT';
 try { eval($endpoint); throw new RuntimeException($label.' did not respond'); }
 catch(FixtureResponse $response) {
  ensure($response->status===$status,$label.' status '.$response->status.' '.json_encode($response->payload));
  ensure(!$db->active,$label.' left transaction active');
  if($status!==200) ensure($db->user===$before,$label.' modified denied account');
  else {
   ensure(password_verify($body['password'],$db->user['password_hash']), 'Reset hash did not match');
   ensure($db->user['password_hash']!==$body['password'],'Plaintext password stored');
   ensure($db->user['failed_login_attempts']===0 && $db->user['locked_until']===null,'Reset did not unlock account');
   ensure(!isset($response->payload['password_hash']) && !isset($response->payload['password']), 'Password leaked');
  }
  $checks++;
 }
}
$admin=['user_id'=>1,'role'=>'admin'];
$valid=['user_id'=>2,'action'=>'reset_password','password'=>'StrongSecret123!','csrf_token'=>'fixture-token'];
runCase('guest',null,$valid,401);
runCase('staff',['user_id'=>1,'role'=>'staff'],$valid,403);
runCase('self',$admin,array_replace($valid,['user_id'=>1]),400);
runCase('missing csrf',$admin,array_diff_key($valid,['csrf_token'=>true]),403);
runCase('wrong csrf',$admin,array_replace($valid,['csrf_token'=>'wrong']),403);
foreach(['abc',0,[],true,'2junk'] as $id) runCase('invalid id',$admin,array_replace($valid,['user_id'=>$id]),400);
foreach([[],true,'','weak',str_repeat('A',73),"StrongSecret123!\0",'AliceSecret123!','ExistingSecure456!'] as $password) runCase('invalid password',$admin,array_replace($valid,['password'=>$password]),400);
runCase('not found',$admin,array_replace($valid,['user_id'=>999]),404);
runCase('db failure',$admin,$valid,500,true);
runCase('success',$admin,$valid,200);
$db=new FixtureDb(); $fixtureActor=$admin; $_SESSION=[]; $_SERVER['REQUEST_METHOD']='GET';
try { eval($endpoint); } catch(FixtureResponse $response) { ensure($response->status===200,'GET failed'); ensure(strlen($response->payload['csrf_token'])===64,'CSRF token invalid'); ensure($response->payload['csrf_token']===$_SESSION['admin_users_csrf_token'],'CSRF token mismatched'); ensure(!isset($response->payload['data'][0]['password_hash']),'GET leaked password hash'); $checks++; }
echo 'PASS: '.$checks.' in-memory account reset cases; no database accessed.'.PHP_EOL;
