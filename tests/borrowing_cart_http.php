<?php
// Requires local Apache. Real session cookies and all normal endpoint bootstrap code.
require __DIR__.'/../config/database.php';
require_once __DIR__.'/../includes/borrowing_history.php';
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
function verifyDeskStatus(int $requestId, string $currentStatus): void {
    global $admin, $staff, $owner, $users;
    foreach ([$admin, $staff, $owner] as $reader) {
        $allIds = []; $statusTotal = 0;
        foreach (BORROWING_HISTORY_STATUSES as $status) {
            $found = false; $tabIds = [];
            for ($page = 1; ; $page++) {
                $response = callApi($reader, 'requests/index.php?view=transactions&status=' . $status . '&limit=50&page=' . $page);
                foreach ($response['data'] as $transaction) {
                    verify($transaction['status'] === $status && $transaction['statuses'] === [$status] && count($transaction['items']) === 1 && $transaction['items'][0]['status'] === $status, 'HTTP status filter included another current status');
                    if ($reader === $owner) verify($transaction['items'][0]['user_id'] === $users[0]['user_id'], 'Customer HTTP transaction exposed another owner');
                    $tabIds[] = $transaction['transaction_id'];
                    if ($transaction['transaction_id'] === 'request:' . $requestId) $found = true;
                }
                if (!$response['has_more']) break;
            }
            verify(count($tabIds) === $response['total'] && count($tabIds) === count(array_unique($tabIds)), 'HTTP status total or pagination duplicated/lost transactions');
            verify($found === ($status === $currentStatus), 'HTTP transaction did not move exclusively to its current status tab');
            $statusTotal += $response['total'];
            $allIds = array_merge($allIds, $tabIds);
        }
        $all = callApi($reader, 'requests/index.php?view=transactions&limit=1');
        verify($statusTotal === $all['total'] && count($allIds) === count(array_unique($allIds)), 'HTTP status tabs do not partition All Requests');
        $detail = callApi($reader, 'requests/index.php?view=transactions&id=' . $requestId)['data'];
        verify($detail['status'] === $currentStatus, 'HTTP current status disagrees with transaction detail');
    }
}
$users=[];$equipment=[];$clients=[];
try {
    foreach (['customer','customer','staff','admin'] as $role) {
        $stmt=$db->prepare('SELECT role_id FROM roles WHERE role_name=?');$stmt->execute([$role]);
        $email=uniqid('cart-http-').'@example.invalid';
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$stmt->fetchColumn(),'EBS HTTP cart fixture',$email,password_hash('TemporaryTest123!',PASSWORD_DEFAULT)]);
        $users[]=['user_id'=>(int)$db->lastInsertId(),'email'=>$email];$clients[]=client();
    }
    foreach (['A','B','C'] as $label) {
        $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,2,2,7)')->execute(['EBS HTTP cart fixture '.$label]);$equipment[]=(int)$db->lastInsertId();
    }
    [$a,$b,$c]=$equipment;[$owner,$other,$staff,$admin]=$clients;
    callApi($owner,'cart/index.php','GET',null,401);
    callApi($owner,'requests/index.php','GET',null,401);
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
    $grouped=callApi($owner,'requests/index.php?view=transactions&checkout_id='.$result['data']['checkout_id'])['data'];
    verify(count($grouped)===2 && count($grouped[0]['items'])===1 && count($grouped[1]['items'])===1,'Customer transaction API combined independent requests');
    verify($rows[0]['transaction_id']==='checkout:'.$result['data']['checkout_id'] && is_int($rows[0]['requested_quantity']),'Request JSON contract incorrect');
    verify(callApi($owner,'requests/index.php?checkout_id='.$result['data']['checkout_id'].'&user_id='.$users[1]['user_id'])['data']===$rows,'Supplied customer ID affected ownership');
    verify(callApi($other,'requests/index.php?view=transactions&checkout_id='.$result['data']['checkout_id'])['data']===[],'Other customer read transaction members');
    callApi($other,'requests/index.php?id='.$rows[0]['request_id'],'GET',null,404);
    callApi($other,'requests/index.php?view=transactions&id='.$rows[0]['request_id'],'GET',null,404);
    callApi($admin,'requests/index.php?view=transactions&checkout_id='.$result['data']['checkout_id']);
    foreach (['status=unknown','status%5B%5D=pending','id=0','limit=51','date_from=2026-02-30'] as $invalid) callApi($owner,'requests/index.php?'.$invalid,'GET',null,400);
    verify(callApi($owner,'requests/index.php?status=borrowed')['data']===[],'Approved or pending requests appeared as Borrowed');
    $changedRequest=array_values(array_filter($rows,fn($row)=>(int)$row['equipment_id']===$a))[0];
    verify((int)$changedRequest['requested_quantity']===$modified['quantity'] && $changedRequest['borrow_date']===$modified['borrow_date'] && $changedRequest['expected_return_date']===$modified['expected_return_date'],'Checkout did not use modified borrowing details');
    $remaining=callApi($owner,'cart/index.php')['data'];verify(count($remaining)===1 && $remaining[0]['equipment_id']===$c,'Unselected HTTP cart removed');
    $requests=$result['data']['requests'];
    callApi($owner,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'approved'],403);
    callApi($staff,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'cancelled'],403);
    callApi($staff,'requests/index.php?id='.$requests[0]['request_id'],'DELETE',null,403);
    $historyCaps=callApi($owner,'requests/index.php?capabilities=1')['data'];
    verify($historyCaps['management_roles']===['admin','staff'],'HTTP capabilities omit staff management');
    if ($historyCaps['management_available']) {
        // The unmodified second item is due today; the modified first item is due tomorrow.
        callApi($admin,'requests/index.php','PUT',['request_id'=>$requests[1]['request_id'],'status'=>'approved']);
        verify(callApi($owner,'requests/index.php?id='.$requests[1]['request_id'])['data']['status']==='approved','Approval fabricated a pickup status');
        callApi($admin,'returns/index.php','POST',['request_id'=>$requests[1]['request_id'],'condition_status'=>'good'],409);
        callApi($admin,'requests/index.php','PUT',['request_id'=>$requests[1]['request_id'],'action'=>'pickup']);
        callApi($admin,'returns/index.php','POST',['request_id'=>$requests[1]['request_id'],'condition_status'=>'good'],201);
        callApi($staff,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'rejected']);
    } else {
        callApi($admin,'requests/index.php','PUT',['request_id'=>$requests[0]['request_id'],'status'=>'approved'],503);
        callApi($admin,'requests/index.php','PUT',['request_id'=>$requests[1]['request_id'],'status'=>'rejected'],503);
        verify(callApi($owner,'requests/index.php?id='.$requests[0]['request_id'])['data']['status']==='pending','Schema-gated administrator action changed status');
        foreach ($requests as $request) callApi($owner,'requests/index.php?id='.$request['request_id'],'DELETE');
    }
    $cancel=$body;$cancel['items']=[['cart_item_id'=>$remaining[0]['cart_item_id'],'equipment_id'=>$c,'requested_quantity'=>1]+$details];$cancel['idempotency_key']=bin2hex(random_bytes(16));
    $submitted=callApi($owner,'cart/checkout.php','POST',$cancel);$requestId=$submitted['data']['requests'][0]['request_id'];
    callApi($owner,'requests/index.php?id='.$requestId,'DELETE');
    callApi($owner,'requests/index.php?id='.$requestId,'DELETE',null,409);
    verify(callApi($owner,'requests/index.php?id='.$requestId)['data']['status']==='cancelled','Cancellation did not preserve HTTP history');
    verify(callApi($owner,'cart/checkout.php','POST',$cancel)===$submitted,'Cancelled key resubmitted over HTTP');
    if ($historyCaps['pickup_available']) {
        $loan=callApi($owner,'requests/index.php','POST',['equipment_id'=>$c,'requested_quantity'=>1,'borrow_date'=>$window['min_date'],'expected_return_date'=>$return],201)['data']['request_id'];
        verifyDeskStatus($loan, 'pending');
        callApi($staff,'requests/index.php','PUT',['request_id'=>$loan,'action'=>'pickup'],409);
        callApi($staff,'returns/index.php','POST',['request_id'=>$loan],409);
        callApi($staff,'requests/index.php','PUT',['request_id'=>$loan,'status'=>'approved']);
        verifyDeskStatus($loan, 'approved');
        callApi($staff,'returns/index.php','POST',['request_id'=>$loan],409);
        callApi($staff,'requests/index.php','PUT',['request_id'=>$loan,'status'=>'rejected'],409);
        callApi($owner,'requests/index.php','PUT',['request_id'=>$loan,'action'=>'pickup'],403);
        callApi($staff,'requests/index.php','PUT',['request_id'=>$loan,'action'=>'pickup']);
        verifyDeskStatus($loan, 'borrowed');
        $pickedUp=callApi($owner,'requests/index.php?id='.$loan)['data'];
        verify($pickedUp['status']==='borrowed' && $pickedUp['picked_up_at']!==null && $pickedUp['picked_up_by_staff_id']===$users[2]['user_id'],'HTTP pickup evidence was not retained');
        $borrowed=callApi($owner,'requests/index.php?status=borrowed')['data'];verify(count($borrowed)===1 && $borrowed[0]['request_id']===$loan,'Borrowed status filtering failed over HTTP');
        callApi($staff,'requests/index.php','PUT',['request_id'=>$loan,'action'=>'pickup'],409);
        callApi($staff,'returns/index.php','POST',['request_id'=>$loan,'condition_status'=>'good'],201);
        verifyDeskStatus($loan, 'returned');
        callApi($staff,'returns/index.php','POST',['request_id'=>$loan,'condition_status'=>'good'],409);
        $returnedLoan=callApi($owner,'requests/index.php?id='.$loan)['data'];
        verify($returnedLoan['status']==='returned' && $returnedLoan['picked_up_at']===$pickedUp['picked_up_at'] && $returnedLoan['picked_up_by_staff_id']===$pickedUp['picked_up_by_staff_id'],'Return lost pickup attribution over HTTP');
        foreach ([$owner,$admin,$staff] as $reader) {
            $detail=callApi($reader,'requests/index.php?id='.$loan)['data'];
            verify($detail['status']==='returned' && count($detail['status_history'])===3,'Staff updates absent from HTTP borrowing history');
            foreach ($detail['status_history'] as $event) verify($event['changed_by_user_id']===$users[2]['user_id'] && strlen($event['changed_at'])===19,'Staff audit attribution missing over HTTP');
        }
        verifyDeskStatus($requests[0]['request_id'], 'rejected');
        verifyDeskStatus($requestId, 'cancelled');
    }
    foreach ($equipment as $id) { $stmt=$db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?');$stmt->execute([$id]);verify((int)$stmt->fetchColumn()===2,'HTTP workflow stock not restored'); }
    echo "PASS: Apache HTTP authentication/cookies, ownership, independent dated edits, persistence, partial checkout/replay, grouped borrowing history, admin/staff lifecycle and audit attribution, cancellation history, and stock restoration.\n";
} finally {
    foreach ($clients as $curl) { try { callApi($curl,'auth/logout.php','POST',[]); } catch (Throwable $e) {} curl_close($curl); }
    foreach ($equipment as $id) {
        if (borrowingAuditSchema($db)['audit_available']) $db->prepare('DELETE FROM borrowing_status_history WHERE request_id IN (SELECT request_id FROM borrowing_requests WHERE equipment_id=?)')->execute([$id]);
        $db->prepare('DELETE FROM returns WHERE request_id IN (SELECT request_id FROM borrowing_requests WHERE equipment_id=?)')->execute([$id]);
        $db->prepare('DELETE FROM equipment_condition_reports WHERE equipment_id=?')->execute([$id]);
        $db->prepare('DELETE FROM borrowing_requests WHERE equipment_id=?')->execute([$id]);
        $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name LIKE ?')->execute([$id,'EBS HTTP cart fixture %']);
    }
    foreach ($users as $user) {
        $db->prepare('DELETE FROM borrowing_checkouts WHERE user_id=?')->execute([$user['user_id']]);
        $db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$user['user_id'],'EBS HTTP cart fixture']);
    }
}
