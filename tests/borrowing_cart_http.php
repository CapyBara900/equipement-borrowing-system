<?php
// Requires local Apache. Real session cookies and all normal endpoint bootstrap code.
require __DIR__.'/../config/database.php';
$db=(new Database())->getConnection();
$base=rtrim(getenv('EBS_TEST_BASE_URL') ?: 'http://localhost/equipement-borrowing-system','/');
function verify(bool $ok,string $message):void { if (!$ok) throw new RuntimeException($message); }
function client() { $curl=curl_init();curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);return $curl; }
function callApi($curl,string $path,string $method='GET',?array $body=null,int $expected=200):array {
    global $base;
    curl_setopt_array($curl,[CURLOPT_URL=>$base.'/api/'.$path,CURLOPT_CUSTOMREQUEST=>$method,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
    curl_setopt($curl,CURLOPT_POSTFIELDS,$body===null?null:json_encode($body));
    // POSTFIELDS can change the method; set it last explicitly.
    curl_setopt($curl,CURLOPT_CUSTOMREQUEST,$method);
    $text=curl_exec($curl);verify($text!==false,'HTTP connection failed: '.curl_error($curl));
    $data=json_decode($text,true);verify(is_array($data),'Non-JSON HTTP response from '.$path);
    verify(curl_getinfo($curl,CURLINFO_RESPONSE_CODE)===$expected,'Unexpected HTTP response from '.$path.': '.json_encode($data));
    return $data;
}
$users=[];$equipment=[];$clients=[];
try {
    foreach (['customer','customer','staff'] as $role) {
        $stmt=$db->prepare('SELECT role_id FROM roles WHERE role_name=?');$stmt->execute([$role]);
        $email=uniqid('cart-http-').'@example.invalid';
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$stmt->fetchColumn(),'EBS HTTP cart fixture',$email,password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);
        $users[]=['user_id'=>(int)$db->lastInsertId(),'email'=>$email];$clients[]=client();
    }
    foreach (['A','B','C'] as $label) {
        $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,2,2,7)')->execute(['EBS HTTP cart fixture '.$label]);$equipment[]=(int)$db->lastInsertId();
    }
    [$a,$b,$c]=$equipment;[$owner,$other,$staff]=$clients;
    callApi($owner,'cart/index.php','GET',null,401);
    foreach ($clients as $index=>$curl) callApi($curl,'auth/login.php','POST',['email'=>$users[$index]['email'],'password'=>'TemporaryTest123!']);
    verify(callApi($owner,'cart/index.php?capabilities=1')['data']['checkout_available'],'Checkout not enabled over HTTP');
    $window=callApi($owner,'borrowing-settings/index.php')['data'];
    $date=$window['min_date'];$return=(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
    $details=['borrow_date'=>$date,'expected_return_date'=>$return];
    callApi($owner,'cart/index.php','POST',['equipment_id'=>$a,'quantity'=>1],400);
    callApi($owner,'cart/index.php','POST',['equipment_id'=>$a,'quantity'=>1]+$details);
    callApi($owner,'cart/index.php','POST',['equipment_id'=>$a,'quantity'=>1]+$details);
    foreach ([$b,$c] as $id) callApi($owner,'cart/index.php','POST',['equipment_id'=>$id,'quantity'=>1]+$details);
    $cart=callApi($owner,'cart/index.php')['data'];verify(count($cart)===3 && $cart[0]['quantity']===2,'Duplicate HTTP additions did not merge');
    verify(callApi($owner,'cart/import.php','POST',['items'=>[['equipment_id'=>$a,'quantity'=>1]+$details]])['data']===$cart,'HTTP import repeated cart quantities');
    callApi($owner,'cart/index.php','PUT',['cart_item_id'=>$cart[0]['cart_item_id'],'quantity'=>3]+$details,409);
    verify(callApi($owner,'cart/index.php')['data']===$cart,'Failed quantity update changed cart');
    $originalCart=$cart;
    $modified=['quantity'=>1,'borrow_date'=>(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'),'expected_return_date'=>(new DateTimeImmutable($date))->modify('+2 days')->format('Y-m-d')];
    callApi($owner,'cart/index.php','PUT',['cart_item_id'=>$cart[0]['cart_item_id']]+$modified);
    $cart=callApi($owner,'cart/index.php')['data'];
    verify($cart[0]['quantity']===1 && $cart[0]['borrow_date']===$modified['borrow_date'] && $cart[0]['expected_return_date']===$modified['expected_return_date'],'Modified details did not persist on reload');
    verify(array_slice($cart,1)===array_slice($originalCart,1),'Modifying one entry changed other entries');
    foreach ([['quantity'=>0],['quantity'=>1.5],['borrow_date'=>(new DateTimeImmutable($date))->modify('-1 day')->format('Y-m-d')],['borrow_date'=>(new DateTimeImmutable($date))->modify('+8 days')->format('Y-m-d')],['expected_return_date'=>$date],['expected_return_date'=>(new DateTimeImmutable($date))->modify('+9 days')->format('Y-m-d')]] as $invalid) {
        callApi($owner,'cart/index.php','PUT',['cart_item_id'=>$cart[0]['cart_item_id']]+$invalid+$modified,400);
    }
    verify(callApi($owner,'cart/index.php')['data']===$cart,'Invalid modifications changed saved details');
    callApi($other,'cart/index.php','PUT',['cart_item_id'=>$cart[0]['cart_item_id']]+$modified,404);
    callApi($staff,'cart/index.php','PUT',['cart_item_id'=>$cart[0]['cart_item_id']]+$modified,403);

    verify(callApi($other,'cart/index.php')['data']===[],'Customer ownership failed');
    callApi($staff,'cart/index.php','GET',null,403);
    callApi($owner,'auth/logout.php','POST',[]);
    callApi($owner,'cart/index.php','GET',null,401);
    callApi($owner,'auth/login.php','POST',['email'=>$users[0]['email'],'password'=>'TemporaryTest123!']);
    verify(callApi($owner,'cart/index.php')['data']===$cart,'Cart disappeared on actual logout/login');
    $window=callApi($owner,'borrowing-settings/index.php')['data'];
    $return=(new DateTimeImmutable($window['min_date']))->modify('+1 day')->format('Y-m-d');
    $body=['idempotency_key'=>bin2hex(random_bytes(16)),'items'=>[['cart_item_id'=>$cart[0]['cart_item_id'],'equipment_id'=>$a,'requested_quantity'=>$cart[0]['quantity'],'borrow_date'=>$cart[0]['borrow_date'],'expected_return_date'=>$cart[0]['expected_return_date']],['cart_item_id'=>$cart[1]['cart_item_id'],'equipment_id'=>$b,'requested_quantity'=>1]+$details]];
    $stale=$body;$stale['idempotency_key']=bin2hex(random_bytes(16));$stale['items'][0]['borrow_date']=$date;
    callApi($owner,'cart/checkout.php','POST',$stale,409);
    verify(callApi($owner,'cart/index.php')['data']===$cart,'Rejected stale checkout changed cart');
    $result=callApi($owner,'cart/checkout.php','POST',$body);verify(count($result['data']['requests'])===2,'HTTP group not created');
    verify(callApi($owner,'cart/checkout.php','POST',$body)===$result,'HTTP replay differed');
    $rows=callApi($owner,'requests/index.php?checkout_id='.$result['data']['checkout_id'])['data'];verify(count($rows)===2 && $rows[0]['status']==='pending','My Borrowings group failed');
    $changedRequest=array_values(array_filter($rows,fn($row)=>(int)$row['equipment_id']===$a))[0];
    verify((int)$changedRequest['requested_quantity']===$modified['quantity'] && $changedRequest['borrow_date']===$modified['borrow_date'] && $changedRequest['expected_return_date']===$modified['expected_return_date'],'Checkout did not use modified borrowing details');
    $remaining=callApi($owner,'cart/index.php')['data'];verify(count($remaining)===1 && $remaining[0]['equipment_id']===$c,'Unselected HTTP cart removed');
    $requests=$result['data']['requests'];
    callApi($owner,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'approved'],403);
    callApi($staff,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'approved']);
    callApi($staff,'requests/index.php','PUT',['request_id'=>$requests[1]['request_id'],'status'=>'rejected']);
    callApi($staff,'returns/index.php','POST',['request_id'=>$requests[0]['request_id'],'condition_status'=>'good'],201);
    $cancel=$body;$cancel['items']=[['cart_item_id'=>$remaining[0]['cart_item_id'],'equipment_id'=>$c,'requested_quantity'=>1]+$details];$cancel['idempotency_key']=bin2hex(random_bytes(16));
    $submitted=callApi($owner,'cart/checkout.php','POST',$cancel);$requestId=$submitted['data']['requests'][0]['request_id'];
    callApi($owner,'requests/index.php?id='.$requestId,'DELETE');
    callApi($owner,'requests/index.php?id='.$requestId,'DELETE',null,409);
    verify(callApi($owner,'requests/index.php?id='.$requestId)['data']['status']==='cancelled','Cancellation did not preserve HTTP history');
    verify(callApi($owner,'cart/checkout.php','POST',$cancel)===$submitted,'Cancelled key resubmitted over HTTP');
    foreach ($equipment as $id) { $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$id]);verify((int)$stmt->fetchColumn()===2,'HTTP workflow stock not restored'); }
    echo "PASS: Apache HTTP authentication/cookies, ownership, independent Modify updates/validation, logout/login persistence, modified-date partial checkout/replay, My Borrowings, actual staff approval/rejection/return, cancellation history, and stock restoration.\n";
} finally {
    foreach ($clients as $curl) { try { callApi($curl,'auth/logout.php','POST',[]); } catch (Throwable $e) {} curl_close($curl); }
    foreach ($equipment as $id) $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name LIKE ?')->execute([$id,'EBS HTTP cart fixture %']);
    foreach ($users as $user) $db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$user['user_id'],'EBS HTTP cart fixture']);
}
