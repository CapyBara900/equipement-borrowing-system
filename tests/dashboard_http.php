<?php
// Uses cloned tables in an isolated temporary database; never edits application data.
if (PHP_SAPI !== 'cli') exit('CLI only.');
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$testName = 'ebs_dashboard_test_' . bin2hex(random_bytes(6));
$server = null; $clients = [];
$sessionDir = sys_get_temp_dir() . '/ebs-dashboard-' . bin2hex(random_bytes(6));
mkdir($sessionDir); $log = tempnam(sys_get_temp_dir(), 'ebs-dashboard-log-');
function dashboardCheck(bool $condition, string $message): void {if (!$condition) throw new RuntimeException($message);}
function dashboardHttp($client, string $route, int $expected = 200, ?array $body = null): array {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . '/' . $route, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST => $body === null ? 'GET' : 'POST', CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $text = curl_exec($client); $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    dashboardCheck($text !== false && $status === $expected, "$route expected $expected, got $status: $text");
    $result = json_decode($text, true); dashboardCheck(is_array($result) && isset($result['success']), 'Missing structured JSON.');
    dashboardCheck(!str_contains($text, 'password_hash'), 'Password hash leaked.');
    return $result;
}
try {
    $db->exec("CREATE DATABASE $testName CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    foreach (['roles','users','equipment','borrowing_requests','returns'] as $table) $db->exec("CREATE TABLE $testName.$table LIKE $table");
    $db->exec("USE $testName");
    $db->exec("INSERT INTO roles(role_id,role_name) VALUES(1,'customer'),(2,'staff'),(3,'admin')");
    $password = 'DashboardFixture123!';
    foreach (['Customer Alpha','Customer Beta','Staff Person','Admin Person'] as $i => $name) {
        $db->prepare('INSERT INTO users(user_id,role_id,name,email,password_hash) VALUES(?,?,?,?,?)')->execute([$i+1, $i < 2 ? 1 : $i, $name, 'dashboard' . $i . '@example.invalid', password_hash($password, PASSWORD_DEFAULT)]);
    }
    $db->exec("INSERT INTO equipment(equipment_id,equipment_name,total_quantity,available_quantity,status) VALUES(1,'Fixture Camera',100,89,'available')");
    $zone = new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Asia/Manila');
    $now = new DateTimeImmutable('now', $zone); $today = $now->format('Y-m-d');
    $pickup = $now->modify('-10 days')->format('Y-m-d');
    $insert = $db->prepare('INSERT INTO borrowing_requests(user_id,equipment_id,status,requested_quantity,borrow_date,expected_return_date,picked_up_at,picked_up_by_staff_id) VALUES(?,1,?,?,?,?,?,?)');
    $create = function(int $user, string $status, string $due, int $qty = 2) use ($insert, $db, $pickup): int {
        $picked = in_array($status, ['borrowed','returned'], true);
        $insert->execute([$user,$status,$qty,$pickup,$due,$picked ? $pickup . ' 12:00:00' : null,$picked ? 3 : null]);
        return (int)$db->lastInsertId();
    };
    for ($i=0; $i<65; $i++) $create(1,'pending',$today);
    $create(1,'approved',$now->modify('-1 day')->format('Y-m-d')); $create(1,'approved',$today);
    $overdueId = $create(1,'borrowed',$now->modify('-2 days')->format('Y-m-d'));
    $create(1,'borrowed',$today); $create(1,'borrowed',$now->modify('+6 days')->format('Y-m-d'));
    $returnedId = $create(1,'returned',$today); $create(1,'rejected',$today); $create(1,'cancelled',$today);
    $db->prepare('INSERT INTO returns(request_id,actual_return_date,returned_quantity) VALUES(?,?,2)')->execute([$returnedId,$today]);
    $create(2,'pending',$today); $create(2,'borrowed',$today,5);
    $port = random_int(20000,45000); $base = 'http://127.0.0.1:' . $port;
    $env = array_merge(getenv(), ['DB_NAME'=>$testName]); $pipes=[];
    $server = proc_open([PHP_BINARY,'-d','session.save_path=' . $sessionDir,'-S','127.0.0.1:' . $port,'-t',dirname(__DIR__)],
        [0=>['pipe','r'],1=>['file',$log,'a'],2=>['file',$log,'a']],$pipes,dirname(__DIR__),$env);
    dashboardCheck(is_resource($server),'Could not start test server.'); fclose($pipes[0]);
    for ($attempt=0;$attempt<50;$attempt++) { $probe=@fsockopen('127.0.0.1',$port,$errno,$error,0.1); if ($probe) {fclose($probe);break;} usleep(100000); }
    $guest=curl_init(); curl_setopt_array($guest,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]); $clients[]=$guest;
    dashboardHttp($guest,'api/dashboard/index.php',401); dashboardHttp($guest,'api/dashboard/index.php',405,[]);
    foreach (['customer','other','staff','admin'] as $i=>$role) {
        $client=curl_init(); curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>10]); $clients[]=$client;
        dashboardHttp($client,'api/auth/login.php',200,['email'=>'dashboard' . $i . '@example.invalid','password'=>$password]);
        $data=dashboardHttp($client,'api/dashboard/index.php?user_id=2&role=admin')['data']; $s=$data['summary'];
        dashboardCheck($data['today']===$today && $data['timezone']===$zone->getName(),'Wrong app calendar date.');
        if ($role==='customer') {
            dashboardCheck($s['total_requests']===73 && $s['pending']===65 && $s['borrowed']===3 && $s['approved']===2 && $s['due_today']===1 && $s['overdue']===1 && $s['cancelled']===1 && $s['rejected']===1,'Wrong customer totals or lifecycle statuses.');
            dashboardCheck(!isset($s['total_units']) && !$data['monthly'] && !$data['most_requested'],'Customer received global equipment reports.');
            foreach ($data['borrowings'] as $row) dashboardCheck((int)$row['user_id']===1,'Another customer borrowing leaked.');
            foreach ($data['recent_activity'] as $row) dashboardCheck($row['user_name']==='Customer Alpha','Another customer activity leaked.');
            $seen=[];
            for($page=1;$page<=9;$page++) {
                $pending=dashboardHttp($client,'api/dashboard/index.php?filter=pending&page=' . $page)['data'];
                dashboardCheck($pending['pagination']['total']===65 && $pending['pagination']['pages']===9,'Pagination total was truncated.');
                foreach($pending['borrowings'] as $row) {dashboardCheck($row['status']==='pending','Wrong filtered status.');$seen[]=$row['request_id'];}
            }
            dashboardCheck(count(array_unique($seen))===65,'Pagination duplicated or omitted requests.');
            $overdue=dashboardHttp($client,'api/dashboard/index.php?filter=overdue')['data'];
            dashboardCheck(count($overdue['borrowings'])===1 && (int)$overdue['borrowings'][0]['request_id']===$overdueId,'Approved pickup became overdue loan.');
            $due=dashboardHttp($client,'api/dashboard/index.php?filter=due_today')['data']; dashboardCheck($due['pagination']['total']===1,'Due today not customer scoped.');
            $all=dashboardHttp($client,'api/dashboard/index.php?filter=all&page=100')['data']; dashboardCheck($all['pagination']['page']===$all['pagination']['pages'],'Page was not safely clamped.');
        } elseif ($role==='other') {
            dashboardCheck($s['total_requests']===2 && $s['pending']===1 && $s['borrowed']===1,'Second customer scope failed.');
            foreach ($data['recent_activity'] as $row) dashboardCheck($row['user_name']==='Customer Beta','First customer activity leaked to second.');
        } else {
            dashboardCheck($s['total_requests']===75 && $s['pending']===66 && $s['borrowed']===4 && $s['due_today']===2,'Desk totals incorrect.');
            dashboardCheck($s['total_units']===100 && $s['borrowed_units']===11 && (float)$s['utilization']===11.0,'Utilization counted reservations or request rows rather than units on loan.');
            dashboardCheck(!empty($data['monthly']) && !empty($data['most_requested']),'Desk reports missing.');
        }
        foreach(['filter=bad','filter[]=pending','page=0','limit=21','page[]=1','filter=pending%27%20OR%201%3D1'] as $query) dashboardHttp($client,'api/dashboard/index.php?' . $query,400);
    }
    $db->exec('UPDATE users SET role_id=1 WHERE user_id=3');
    $changed=dashboardHttp($clients[3],'api/dashboard/index.php')['data'];
    dashboardCheck($changed['role']==='customer' && $changed['summary']['total_requests']===0,'A stale staff session granted desk-wide data after a role change.');
    $db->exec('DELETE FROM users WHERE user_id=3'); dashboardHttp($clients[3],'api/dashboard/index.php',401);
    echo "PASS: isolated HTTP role access, customer list/feed/metric isolation, exact totals above 50, full pagination, status filters, overdue/pickup distinction, timezone, quantity utilization, malformed inputs, and guest/method denial.\n";
} finally {
    foreach($clients as $client) curl_close($client);
    if(is_resource($server)) {proc_terminate($server);proc_close($server);}
    $db->exec('USE information_schema'); $db->exec("DROP DATABASE IF EXISTS $testName");
    if(is_file($log)) unlink($log); foreach(glob($sessionDir . '/sess_*') as $sessionFile) unlink($sessionFile); rmdir($sessionDir);
}
