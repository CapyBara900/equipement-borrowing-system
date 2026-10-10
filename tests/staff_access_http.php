<?php
// Real HTTP role checks with temporary accounts and one equipment fixture.
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$base = rtrim(getenv('EBS_TEST_BASE_URL') ?: 'http://localhost/equipement-borrowing-system', '/');
function checkAccess(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function accessHttp($client, string $path, int $expected, string $method = 'GET', ?array $body = null): string {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . '/' . $path,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body),
        CURLOPT_CUSTOMREQUEST => $method]);
    $text = curl_exec($client);
    checkAccess($text !== false, 'HTTP failed: ' . curl_error($client));
    checkAccess(curl_getinfo($client, CURLINFO_RESPONSE_CODE) === $expected, $method . ' ' . $path . ': expected ' . $expected . ', got ' . curl_getinfo($client, CURLINFO_RESPONSE_CODE) . ' ' . $text);
    return $text;
}
$users = []; $clients = []; $equipmentId = null;
$prefix = 'Staff access test ' . bin2hex(random_bytes(6));
$guest = curl_init();
curl_setopt_array($guest, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
try {
    accessHttp($guest, 'equipment.php', 302);
    foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) accessHttp($guest, 'api/equipment/index.php', 401, $method);
    foreach (['admin', 'staff', 'customer'] as $role) {
        $stmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ?'); $stmt->execute([$role]);
        $email = uniqid('staff-access-') . '@example.invalid';
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$stmt->fetchColumn(), $prefix . ' ' . $role, $email, password_hash('TemporaryTest123!', PASSWORD_DEFAULT)]);
        $users[] = (int)$db->lastInsertId();
        $client = curl_init();
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
        $clients[$role] = $client;
        accessHttp($client, 'api/auth/login.php', 200, 'POST', ['email' => $email, 'password' => 'TemporaryTest123!']);
    }
    $created = json_decode(accessHttp($clients['admin'], 'api/equipment/index.php', 201, 'POST', ['equipment_name' => $prefix, 'total_quantity' => 3, 'borrowing_time_limit_days' => 7]), true);
    $equipmentId = (int)$created['data']['equipment_id'];
    $stmt = $db->prepare('SELECT * FROM equipment WHERE equipment_id = ?'); $stmt->execute([$equipmentId]); $before = $stmt->fetch();
    $staff = $clients['staff'];
    foreach (['equipment.php', 'equipment.php?id=' . $equipmentId] as $page) {
        $text = accessHttp($staff, $page, 403);
        checkAccess(!str_contains($text, 'editForm') && !str_contains($text, 'equipmentList'), 'Denied page exposed equipment interface');
    }
    $legacy = accessHttp($staff, 'equipment.html', 200);
    checkAccess(str_contains($legacy, 'equipment.php') && !str_contains($legacy, 'equipmentList') && !str_contains($legacy, 'editForm'), 'Legacy URL bypassed protected page');
    foreach (['GET', 'POST', 'PUT', 'DELETE'] as $method) {
        foreach (['', '?id=' . $equipmentId, '?search=test&category_id=1'] as $query) accessHttp($staff, 'api/equipment/index.php' . $query, 403, $method, ['equipment_id' => $equipmentId]);
        accessHttp($staff, 'api/categories/index.php?id=1', 403, $method, ['category_id' => 1]);
    }
    accessHttp($staff, 'api/categories/events.php', 403);
    foreach (['GET', 'POST'] as $method) accessHttp($staff, 'api/condition-reports/index.php', 403, $method, ['equipment_id' => $equipmentId, 'condition_status' => 'damaged']);
    foreach (['admin', 'customer'] as $role) {
        $page = accessHttp($clients[$role], 'equipment.php', 200);
        checkAccess(str_contains($page, 'equipmentList') && str_contains($page, 'assets/js/equipment.js'), $role . ' lost equipment interface');
        accessHttp($clients[$role], 'api/equipment/index.php?id=' . $equipmentId, 200);
        accessHttp($clients[$role], 'api/categories/index.php?all=1', 200);
    }
    foreach (['POST', 'PUT', 'DELETE'] as $method) accessHttp($clients['customer'], 'api/equipment/index.php?id=' . $equipmentId, 403, $method, ['equipment_id' => $equipmentId]);
    foreach (['dashboard.html', 'requests.html', 'profile.php'] as $page) {
        $html = accessHttp($staff, $page, 200);
        checkAccess(str_contains($html, 'assets/js/app.js?v=account-profile-v2'), $page . ' loaded stale navigation');
        checkAccess(str_contains($html, 'assets/css/styles.css?v=' . ($page === 'profile.php' ? 'account-profile-ui-v1' : 'account-profile-v2')), $page . ' loaded stale account styles');
        checkAccess(!preg_match('/href="equipment\.(html|php)/', $html), $page . ' contains a staff equipment link');
    }
    $script = accessHttp($staff, 'assets/js/app.js?v=account-profile-v2', 200);
    checkAccess(hash('sha256', $script) === hash_file('sha256', __DIR__ . '/../assets/js/app.js'), 'Served navigation differs from current role-filtered script');
    accessHttp($staff, 'api/dashboard/index.php', 200);
    accessHttp($staff, 'api/requests/index.php?capabilities=1', 200);
    accessHttp($staff, 'api/requests/index.php?view=transactions&limit=1', 200);
    accessHttp($staff, 'api/returns/index.php', 200);
    $stmt->execute([$equipmentId]); checkAccess($stmt->fetch() === $before, 'Denied requests changed equipment');
    accessHttp($clients['admin'], 'api/equipment/index.php?id=' . $equipmentId, 200, 'DELETE'); $equipmentId = null;
    echo "PASS: real HTTP staff page denial, legacy URL, all equipment/category methods, category stream and condition-report denial; guest authentication, admin/customer access, staff overview/borrowing APIs, and unchanged stock.\n";
} finally {
    if ($equipmentId) $db->prepare('DELETE FROM equipment WHERE equipment_id = ? AND equipment_name = ?')->execute([$equipmentId, $prefix]);
    foreach ($clients as $client) { try { accessHttp($client, 'api/auth/logout.php', 200, 'POST', []); } catch (Throwable $e) {} curl_close($client); }
    curl_close($guest);
    foreach ($users as $id) $db->prepare('DELETE FROM users WHERE user_id = ? AND name LIKE ?')->execute([$id, $prefix . ' %']);
}
