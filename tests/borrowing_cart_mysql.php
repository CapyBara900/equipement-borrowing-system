<?php
require __DIR__.'/../config/database.php';require __DIR__.'/../includes/auth_middleware.php';require __DIR__.'/../includes/borrowing_cart.php';require __DIR__.'/../includes/notifications.php';
class CartResult extends Exception{public function __construct(public int $status,public array $payload){}}
function sendJson(int $status,array $payload):void{throw new CartResult($status,$payload);}function getJsonBody():array{return $GLOBALS['cartTestBody'];}function cleanText(?string $value):string{return trim(strip_tags($value??''));}
function check(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);}
function api(string $file,string $method,array $body=[],array $query=[],?string $role='customer',?int $owner=null):CartResult{
 global $db,$userId;$_SESSION=$role===null?[]:['user_id'=>$owner??$userId,'name'=>'Fixture','email'=>'test@example.invalid','role'=>$role];$_SERVER['REQUEST_METHOD']=$method;$_GET=$query;$GLOBALS['cartTestBody']=$body;
 $source=preg_replace('/^<\?php/','',file_get_contents(__DIR__.'/../api/'.$file));$source=preg_replace('/require_once[^;]+;/','',$source);$source=str_replace(['const REQUEST_SELECT','REQUEST_SELECT','const RETURN_SELECT','RETURN_SELECT','const EQUIPMENT_SELECT','EQUIPMENT_SELECT'],['$requestSelect','$requestSelect','$returnSelect','$returnSelect','$equipmentSelect','$equipmentSelect'],$source);
 try{eval($source);}catch(CartResult $result){return $result;}throw new RuntimeException('Missing API response.');
}
class FailDatedInsert extends PDOStatement{protected function __construct(){}public function execute(?array $params=null):bool{if(str_starts_with($this->queryString,'INSERT INTO borrowing_requests')&&++$GLOBALS['insertNumber']===2)throw new PDOException('Injected second-item failure');return parent::execute($params);}}
$db=(new Database())->getConnection();$users=[];$equipment=[];$userId=null;
try{
 check(cartCapabilities($db)['checkout_available'],'Dated schema unavailable');$role=$db->query("SELECT role_id FROM roles WHERE role_name='customer'")->fetchColumn();
 foreach([1,2]as$i){$db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$role,'Dated cart fixture',uniqid('dated-cart-').'@example.invalid',password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);$users[]=(int)$db->lastInsertId();}[$userId,$other]=$users;
 foreach(['A','B','C']as$name){$db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,5,5,3)')->execute(['Dated cart fixture '.$name]);$equipment[]=(int)$db->lastInsertId();}[$a,$b,$c]=$equipment;
 $today=new DateTimeImmutable(pickupDateWindow()['min_date'],borrowingTimezone());$day=fn(int $n)=>$today->modify("+$n days")->format('Y-m-d');
 $add=['equipment_id'=>$a,'quantity'=>1,'borrow_date'=>$day(0),'expected_return_date'=>$day(0)];
 foreach(['quantity','borrow_date','expected_return_date']as$field){$bad=$add;unset($bad[$field]);check(api('cart/index.php','POST',$bad)->status===400,'Missing '.$field.' accepted');}
 foreach([0,-1,true,1.5,'1.5',[]]as$value){$bad=$add;$bad['quantity']=$value;check(api('cart/index.php','POST',$bad)->status===400,'Invalid quantity accepted');}
 foreach([['borrow_date'=>$today->modify('-1 day')->format('Y-m-d')],['borrow_date'=>$day(8),'expected_return_date'=>$day(8)],['borrow_date'=>'2026-02-30'],['expected_return_date'=>$today->modify('-1 day')->format('Y-m-d')],['expected_return_date'=>$day(4)]]as$change)check(api('cart/index.php','POST',array_replace($add,$change))->status===400,'Invalid date accepted');
 check(api('cart/index.php','POST',$add)->status===200,'Same-day dates rejected');check(api('cart/index.php','POST',$add)->status===200,'Same-date addition failed');
 $later=$add;$later['quantity']=2;$later['borrow_date']=$day(1);$later['expected_return_date']=$day(2);check(api('cart/index.php','POST',$later)->status===200,'Different dates failed');
 $rows=readBorrowingCart($db,$userId);check(count($rows)===2&&$rows[0]['quantity']===2,'Same/different-date identity incorrect');[$first,$second]=$rows;
 $edit=['cart_item_id'=>$second['cart_item_id'],'quantity'=>2,'borrow_date'=>$day(2),'expected_return_date'=>$day(3)];check(api('cart/index.php','PUT',$edit)->status===200,'Independent date edit failed');
 check(readBorrowingCart($db,$userId)[0]['borrow_date']===$day(0),'Edited another entry');
 check(api('cart/index.php','PUT',array_replace($edit,['borrow_date'=>$day(0),'expected_return_date'=>$day(0)]))->status===409,'Date collision overwrote entry');
 check(api('cart/index.php','PUT',$edit,[],'customer',$other)->status===404,'Another customer edited entry');check(api('cart/index.php','DELETE',['cart_item_id'=>$first['cart_item_id']],[],'customer',$other)->status===404,'Another customer removed entry');
 check(api('cart/index.php','GET',[],[],null)->status===401,'Unauthenticated cart accessible');check(api('cart/index.php','GET',[],[],'staff')->status===403,'Staff accessed customer cart');
 $fresh=(new Database())->getConnection();check(readBorrowingCart($fresh,$userId)===readBorrowingCart($db,$userId),'Dated entries not persistent');
 $stock=function($id)use($db){$stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$id]);return(int)$stmt->fetchColumn();};check($stock($a)===5,'Adding reserved inventory');
 $payload=function(array $rows){return ['idempotency_key'=>bin2hex(random_bytes(16)),'items'=>array_map(fn($row)=>['cart_item_id'=>$row['cart_item_id'],'equipment_id'=>$row['equipment_id'],'requested_quantity'=>$row['quantity'],'borrow_date'=>$row['borrow_date'],'expected_return_date'=>$row['expected_return_date']],$rows)];};
 $editFirst=['cart_item_id'=>$first['cart_item_id'],'quantity'=>4,'borrow_date'=>$day(0),'expected_return_date'=>$day(0)];check(api('cart/index.php','PUT',$editFirst)->status===200,'Quantity edit failed');
 $before=readBorrowingCart($db,$userId);check(api('cart/checkout.php','POST',$payload($before))->status===409,'Aggregate overselling allowed');check(readBorrowingCart($db,$userId)===$before&&$stock($a)===5,'Failed checkout changed entries');
 $editFirst['quantity']=2;api('cart/index.php','PUT',$editFirst);
 // Legacy undated rows survive migration and are editable, but not silently submitted.
 $db->prepare('INSERT INTO borrowing_cart_items(user_id,equipment_id,quantity) VALUES(?,?,1)')->execute([$userId,$b]);$draftId=(int)$db->lastInsertId();
 check(api('cart/index.php','PUT',['cart_item_id'=>$draftId,'quantity'=>1])->status===400,'Missing draft dates accepted');
 check(api('cart/index.php','PUT',['cart_item_id'=>$draftId,'quantity'=>1,'borrow_date'=>$day(1),'expected_return_date'=>$day(1)])->status===200,'Old draft could not be completed');
 $extra=$add;$extra['quantity']=1;$extra['borrow_date']=$day(3);$extra['expected_return_date']=$day(3);api('cart/index.php','POST',$extra);api('cart/index.php','POST',array_replace($add,['equipment_id'=>$c]));
 $all=readBorrowingCart($db,$userId);$selected=array_slice($all,0,3);$body=$payload($selected);
 $stale=$body;$stale['items'][0]['expected_return_date']=$day(1);check(api('cart/checkout.php','POST',$stale)->status===409,'Stale dates accepted');
 $before=readBorrowingCart($db,$userId);$oldClass=$db->getAttribute(PDO::ATTR_STATEMENT_CLASS);$GLOBALS['insertNumber']=0;$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[FailDatedInsert::class]);
 try{check(api('cart/checkout.php','POST',$body)->status===500,'Injected DB failure not reported');}finally{$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,$oldClass);}
 check(readBorrowingCart($db,$userId)===$before&&$stock($a)===5&&$stock($b)===5,'MySQL failure was not atomic');
 $result=api('cart/checkout.php','POST',$body);check($result->status===200,'Dated checkout failed: '.json_encode($result->payload));$result=$result->payload;$requests=$result['data']['requests'];check(count($requests)===3,'Incorrect item count');
 check($requests[0]['equipment_id']===$a&&$requests[1]['equipment_id']===$a&&$requests[0]['borrow_date']===$day(0)&&$requests[1]['borrow_date']===$day(2),'Independent request dates lost');
 check(count($result['confirmed_cart_item_ids'])===3&&$stock($a)===1&&$stock($b)===4,'Wrong acknowledgments or stock');
 $remaining=readBorrowingCart($db,$userId);check(count($remaining)===2&&$remaining[0]['equipment_id']===$a&&$remaining[0]['borrow_date']===$day(3),'Unselected same-equipment entry removed');
 check(checkoutBorrowingCart($fresh,$userId,$body)===$result,'Replay changed result');$reordered=$body;$reordered['items']=array_reverse($body['items']);check(checkoutBorrowingCart($db,$userId,$reordered)===$result,'Ordering changed idempotency');
 $changed=$body;$changed['items'][0]['requested_quantity']=1;check(api('cart/checkout.php','POST',$changed)->status===409,'Changed payload reused key');
 $group=$result['data']['checkout_id'];check(count(api('requests/index.php','GET',[],['checkout_id'=>$group])->payload['data'])===3,'My Borrowings lost group members');
 check(api('requests/index.php','GET',[],['checkout_id'=>$group],'customer',$other)->payload['data']===[],'Other customer listed group');
 check(api('requests/index.php','DELETE',[],['id'=>$requests[0]['request_id']])->status===200&&$stock($a)===3,'Cancel restored wrong quantity');check(api('requests/index.php','DELETE',[],['id'=>$requests[0]['request_id']])->status===409,'Repeat cancel allowed');
 api('requests/index.php','PUT',['request_id'=>$requests[1]['request_id'],'status'=>'approved'],[],'staff');check($stock($a)===3,'Approval deducted again');check(api('returns/index.php','POST',['request_id'=>$requests[1]['request_id'],'condition_status'=>'good'],[],'staff')->status===201&&$stock($a)===5,'Good return failed');
 api('requests/index.php','PUT',['request_id'=>$requests[2]['request_id'],'status'=>'rejected'],[],'staff');check($stock($b)===5,'Rejection failed');
 // Pre-upgrade success keys remain replayable with their original payload hashes.
 $legacy=['items'=>[['equipment_id'=>$b,'requested_quantity'=>1]],'borrow_date'=>$day(0),'expected_return_date'=>$day(1)];$oldKey=bin2hex(random_bytes(16));$oldResult=['success'=>true,'confirmed_equipment_ids'=>[$b],'data'=>['request_id'=>$requests[2]['request_id']]];
 $db->prepare('INSERT INTO borrowing_checkouts(user_id,idempotency_key,payload_hash,response_json,borrow_date,expected_return_date) VALUES(?,?,?,?,?,?)')->execute([$userId,$oldKey,hash('sha256',json_encode($legacy)),json_encode($oldResult),$day(0),$day(1)]);
 check(checkoutBorrowingCart($db,$userId,$legacy+['idempotency_key'=>$oldKey])===$oldResult&&$stock($b)===5,'Old success key could not replay safely');
 $legacy['idempotency_key']=bin2hex(random_bytes(16));check(api('cart/checkout.php','POST',$legacy)->status===400,'New undated legacy checkout allowed');
 // Import is date-aware, atomic, and stable on repeat.
 $import=['items'=>[array_replace($add,['borrow_date'=>$day(3),'expected_return_date'=>$day(3)])]];check(api('cart/import.php','POST',$import)->status===200,'Dated import failed');$before=readBorrowingCart($db,$userId);check(api('cart/import.php','POST',$import)->payload['data']===$before,'Import added twice');
 $import['items'][]=['equipment_id'=>$c,'quantity'=>1];check(api('cart/import.php','POST',$import)->status===400&&readBorrowingCart($db,$userId)===$before,'Undated import changed cart');
 try{$db->prepare('INSERT INTO borrowing_cart_items(user_id,equipment_id,quantity,borrow_date,expected_return_date) VALUES(?,?,?,?,?)')->execute([$userId,$a,1,$day(3),$day(3)]);throw new RuntimeException('Database accepted duplicate dated identity');}catch(PDOException $e){}
 echo "PASS: required dates/quantities, same-day returns, independent dated entries/edits, ownership, aggregate stock, atomic rollback, entry-specific removal, persistent dates, old/new idempotency, imports, and unchanged approval/restoration workflow.\n";
}finally{if($db->inTransaction())$db->rollBack();foreach($equipment as$id)$db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name LIKE ?')->execute([$id,'Dated cart fixture %']);foreach($users as$id)$db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$id,'Dated cart fixture']);}
