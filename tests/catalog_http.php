<?php
// Isolated database + local HTTP server; application accounts and inventory are untouched.
if (PHP_SAPI !== 'cli') exit('CLI only.');
require __DIR__ . '/../config/database.php';
$db=(new Database())->getConnection(); $testName='ebs_catalog_test_' . bin2hex(random_bytes(6));
$server=null; $clients=[]; $sessionDir=sys_get_temp_dir() . '/ebs-catalog-' . bin2hex(random_bytes(6));
mkdir($sessionDir); $log=tempnam(sys_get_temp_dir(),'ebs-catalog-log-');
function catalogCheck(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function catalogHttp($client,string $route,int $expected=200,?array $body=null): array {
 global $base;
 curl_setopt_array($client,[CURLOPT_URL=>$base . '/' . $route,CURLOPT_HTTPHEADER=>['Content-Type: application/json'],CURLOPT_CUSTOMREQUEST=>$body===null?'GET':'POST',CURLOPT_POSTFIELDS=>$body===null?null:json_encode($body)]);
 $text=curl_exec($client);$status=curl_getinfo($client,CURLINFO_RESPONSE_CODE);catalogCheck($text!==false && $status===$expected,"$route expected $expected, got $status: $text");
 $result=json_decode($text,true);catalogCheck(is_array($result) && isset($result['success']),'Missing structured JSON.');return $result;
}
try {
 $db->exec("CREATE DATABASE $testName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
 foreach(['roles','users','categories','equipment','borrowing_requests','returns','equipment_condition_reports'] as $table)$db->exec("CREATE TABLE $testName.$table LIKE $table");
 $db->exec("USE $testName");$db->exec("INSERT INTO roles(role_id,role_name) VALUES(1,'customer'),(2,'admin'),(3,'staff')");
 $password='CatalogFixture123!';
 foreach(['Customer Alpha','Customer Beta','Admin Person','Staff Person'] as $i=>$name)$db->prepare('INSERT INTO users(user_id,role_id,name,email,password_hash) VALUES(?,?,?,?,?)')->execute([$i+1,$i<2?1:$i,$name,'catalog' . $i . '@example.invalid',password_hash($password,PASSWORD_DEFAULT)]);
 $db->exec("INSERT INTO categories(category_id,category_name) VALUES(1,'Audio Visual'),(2,'Computing')");
 $insert=$db->prepare('INSERT INTO equipment(equipment_id,equipment_name,description,serial_number,category_id,total_quantity,available_quantity,borrowing_time_limit_days,status) VALUES(?,?,?,?,?,20,?,7,?)');
 for($id=1;$id<=29;$id++)$insert->execute([$id,$id===1?'Fixture Camera':'Catalog Item ' . str_pad((string)$id,2,'0',STR_PAD_LEFT),$id===1?'Portable optics':'Fixture equipment','SERIAL-'.$id,$id%2?1:2,$id===2?0:($id===3?1:18),$id===2?'maintenance':'available']);
 $today=date('Y-m-d');$borrow=date('Y-m-d',strtotime('-3 days'));
 $request=$db->prepare("INSERT INTO borrowing_requests(user_id,equipment_id,requested_quantity,status,borrow_date,expected_return_date) VALUES(?,1,1,?,?,?)");
 $request->execute([1,'pending',$borrow,$today]);$ownId=(int)$db->lastInsertId();
 $request->execute([2,'pending',$borrow,$today]);$otherId=(int)$db->lastInsertId();
 $db->prepare("INSERT INTO equipment_condition_reports(equipment_id,reported_by_user_id,condition_status,notes) VALUES(1,3,'good','Internal condition note')")->execute();
 $port=random_int(20000,45000);$base='http://127.0.0.1:' . $port;$pipes=[];
 $server=proc_open([PHP_BINARY,'-d','session.save_path=' . $sessionDir,'-S','127.0.0.1:' . $port,'-t',dirname(__DIR__)],[0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),array_merge(getenv(),['DB_NAME'=>$testName]));
 catalogCheck(is_resource($server),'Could not start isolated server.');fclose($pipes[0]);
 for($attempt=0;$attempt<50;$attempt++){if($probe=@fsockopen('127.0.0.1',$port,$errno,$error,0.1)){fclose($probe);break;}usleep(100000);}
 $guest=curl_init();curl_setopt_array($guest,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);$clients[]=$guest;
 catalogHttp($guest,'api/equipment/history.php?equipment_id=1',401);catalogHttp($guest,'api/equipment/history.php',405,[]);
 foreach(['customer','other','admin','staff'] as $i=>$role){
  $client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]);$clients[]=$client;
  catalogHttp($client,'api/auth/login.php',200,['email'=>'catalog' . $i . '@example.invalid','password'=>$password]);
  if($role==='staff'){catalogHttp($client,'api/equipment/history.php?equipment_id=1',403);catalogHttp($client,'api/equipment/index.php',403);continue;}
  $catalog=catalogHttp($client,'api/equipment/index.php?limit=12');catalogCheck($catalog['total']===29 && $catalog['pages']===3 && count($catalog['data'])===12 && $catalog['has_more']===true,'Catalog totals or first page incorrect.');
  $ids=[];for($page=1;$page<=3;$page++){ $data=catalogHttp($client,'api/equipment/index.php?limit=12&page=' . $page);foreach($data['data'] as $row)$ids[]=$row['equipment_id']; }
  catalogCheck(count(array_unique($ids))===29,'Pagination omitted or duplicated equipment.');
  $last=catalogHttp($client,'api/equipment/index.php?limit=12&page=999');catalogCheck($last['page']===3 && $last['has_more']===false && count($last['data'])===5,'Last-page clamp or next-page flag incorrect.');
  foreach(['Fixture Camera','Portable optics','SERIAL-1'] as $term){$found=catalogHttp($client,'api/equipment/index.php?search=' . urlencode($term));catalogCheck($found['total']>=1 && count($found['data'])>=1,'Search failed for name, description, or serial.');}
  $unavailable=catalogHttp($client,'api/equipment/index.php?status=unavailable');catalogCheck($unavailable['total']===1 && (int)$unavailable['data'][0]['equipment_id']===2,'Stock-based status filter failed.');
  $category=catalogHttp($client,'api/equipment/index.php?category_id=2');catalogCheck($category['total']===14,'Category filter total incorrect.');
  $stock=catalogHttp($client,'api/equipment/index.php?sort_by=available_quantity&sort_dir=ASC');catalogCheck((int)$stock['data'][0]['available_quantity']===0,'Stock sorting failed.');
  $history=catalogHttp($client,'api/equipment/history.php?equipment_id=1&user_id=2&role=admin')['data'];
  if($role==='admin'){catalogCheck($history['scope']==='all' && count($history['borrowings'])===2 && count($history['conditions'])===1,'Admin history incomplete.');}
  else {catalogCheck($history['scope']==='own' && count($history['borrowings'])===1 && (int)$history['borrowings'][0]['request_id']===($role==='customer'?$ownId:$otherId),'Another customer borrowing history leaked.');catalogCheck(!$history['conditions'] && !isset($history['borrowings'][0]['user_name']),'Private condition notes or borrower identity leaked.');}
  $empty=catalogHttp($client,'api/equipment/history.php?equipment_id=29')['data'];catalogCheck(!$empty['borrowings'] && !$empty['conditions'],'Empty history incorrect.');
  foreach(['equipment_id=0','equipment_id[]=1','equipment_id=1%27%20OR%201%3D1'] as $bad)catalogHttp($client,'api/equipment/history.php?' . $bad,400);
  catalogHttp($client,'api/equipment/history.php?equipment_id=999',404);
 }
 $db->exec('UPDATE users SET role_id=1 WHERE user_id=3');$changed=catalogHttp($clients[3],'api/equipment/history.php?equipment_id=1')['data'];catalogCheck($changed['scope']==='own' && !$changed['borrowings'] && !$changed['conditions'],'Stale admin role exposed history.');
 echo "PASS: isolated customer/admin catalog, totals and stable pagination, AJAX filter queries, stock sort, customer history isolation, admin condition records, malformed IDs, fresh role checks, and staff/guest denial.\n";
} finally {
 foreach($clients as $client)curl_close($client);if(is_resource($server)){proc_terminate($server);proc_close($server);}
 $db->exec('USE information_schema');$db->exec("DROP DATABASE IF EXISTS $testName");
 if(is_file($log))unlink($log);foreach(glob($sessionDir . '/sess_*') as $file)unlink($file);rmdir($sessionDir);
}
