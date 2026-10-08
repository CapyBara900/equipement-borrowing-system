<?php
// Uses the configured local database + Apache. Only temporary fixtures are removed.
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$base = rtrim(getenv('EBS_TEST_BASE_URL') ?: 'http://localhost/equipement-borrowing-system', '/');
function verify(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function callApi($client, string $path, string $method = 'GET', ?array $body = null, int $expected = 200): array {
    global $base, $categories;
    curl_setopt_array($client, [CURLOPT_URL => $base . '/api/' . $path, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body), CURLOPT_CUSTOMREQUEST => $method]);
    $text = curl_exec($client);
    verify($text !== false, 'HTTP connection failed: ' . curl_error($client));
    $payload = json_decode($text, true);
    verify(is_array($payload), 'Non-JSON response: ' . $path . ' ' . $text);
    if ($method === 'POST' && $path === 'categories/index.php' && isset($payload['data']['category_id'])) $categories[] = (int)$payload['data']['category_id'];
    verify(curl_getinfo($client, CURLINFO_RESPONSE_CODE) === $expected, 'Unexpected HTTP response: ' . $path . ' ' . json_encode($payload));
    return $payload;
}
$users = []; $clients = []; $categories = []; $equipmentId = null;
$prefix = 'Category test ' . bin2hex(random_bytes(6));
$before = $db->query('SELECT * FROM equipment ORDER BY equipment_id')->fetchAll();
try {
    foreach (['admin', 'staff', 'customer'] as $role) {
        $stmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ?'); $stmt->execute([$role]);
        $email = uniqid('category-test-') . '@example.invalid';
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES (?,?,?,?)')->execute([$stmt->fetchColumn(), $prefix, $email, password_hash('TemporaryTest123!', PASSWORD_DEFAULT)]);
        $users[] = (int)$db->lastInsertId();
        $client = curl_init();
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
        $clients[$role] = $client;
        callApi($client, 'categories/index.php', 'GET', null, 401);
        callApi($client, 'categories/index.php', 'POST', ['category_name' => $prefix], 401);
        callApi($client, 'auth/login.php', 'POST', ['email' => $email, 'password' => 'TemporaryTest123!']);
    }
    foreach (['POST', 'PUT', 'DELETE'] as $method) callApi($clients['customer'], 'categories/index.php?id=1', $method, ['category_id' => 1, 'category_name' => $prefix], 403);
    foreach (['admin', 'staff'] as $role) {
        foreach (['', '  ', '---', '<b>Tools</b>', "Tools\0", [], true, 42, str_repeat('x', 101)] as $invalid) {
            callApi($clients[$role], 'categories/index.php', 'POST', ['category_name' => $invalid], 400);
        }
        callApi($clients[$role], 'categories/index.php', 'POST', ['category_name' => $prefix, 'description' => []], 400);
        callApi($clients[$role], 'categories/index.php', 'POST', ['category_name' => $prefix, 'description' => str_repeat('d', 501)], 400);
        callApi($clients[$role], 'categories/index.php?id=0', 'GET', null, 400);
    }
    $created = callApi($clients['staff'], 'categories/index.php', 'POST', ['category_name' => '  ' . $prefix . "  Audio\t  Visual  ", 'description' => 'Test'], 201)['data'];
    $a = (int)$created['category_id']; $categories[] = $a;
    verify($created['category_name'] === $prefix . ' Audio Visual', 'Whitespace not normalized');
    $b = (int)callApi($clients['admin'], 'categories/index.php', 'POST', ['category_name' => $prefix . ' Computing'], 201)['data']['category_id']; $categories[] = $b;
    foreach (['admin', 'staff'] as $role) {
        foreach ([strtolower($prefix) . ' audio visual', ' ' . $prefix . '   Audio Visual ', $prefix . "\u{00A0}Audio\u{2003}Visual"] as $duplicate) {
            $error = callApi($clients[$role], 'categories/index.php', 'POST', ['category_name' => $duplicate], 409);
            verify($error['code'] === 'CATEGORY_DUPLICATE', 'Missing duplicate error code');
            callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => $b, 'category_name' => $duplicate], 409);
        }
        callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => $a, 'category_name' => strtoupper($prefix) . ' AUDIO VISUAL']);
        callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => $a, 'category_name' => strtoupper($prefix) . ' AUDIO VISUAL']);
        callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => $a, 'category_name' => []], 400);
        callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => 'abc', 'category_name' => $prefix], 400);
        callApi($clients[$role], 'categories/index.php', 'PUT', ['category_id' => 2147483647, 'category_name' => $prefix], 404);
    }
    $equipment = ['equipment_name' => $prefix, 'category_id' => $a, 'total_quantity' => 3, 'borrowing_time_limit_days' => 7];
    $equipmentId = (int)callApi($clients['staff'], 'equipment/index.php', 'POST', $equipment, 201)['data']['equipment_id'];
    foreach (['admin', 'staff'] as $role) {
        $error = callApi($clients[$role], 'categories/index.php?id=' . $a, 'DELETE', null, 409);
        verify($error['code'] === 'CATEGORY_IN_USE' && str_contains($error['message'], 'Reassign'), 'Unclear assigned-category error');
        $item = callApi($clients['customer'], 'equipment/index.php?id=' . $equipmentId)['data'];
        verify((int)$item['category_id'] === $a && (int)$item['available_quantity'] === 3, 'Delete altered category assignment or stock');
    }
    // Database restriction protects direct/concurrent deletions too.
    try {
        $db->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$a]);
        throw new RuntimeException('Database accepted deletion of assigned category');
    } catch (PDOException $e) { verify((int)$e->errorInfo[1] === 1451, 'Unexpected FK failure'); }
    callApi($clients['staff'], 'categories/index.php', 'PUT', ['category_id' => $a, 'category_name' => $prefix . ' Renamed']);
    foreach ($clients as $client) {
        $item = callApi($client, 'equipment/index.php?id=' . $equipmentId)['data'];
        verify($item['category_name'] === $prefix . ' Renamed' && (int)$item['category_id'] === $a, 'Rename not reflected across roles');
        verify(count(callApi($client, 'equipment/index.php?category_id=' . $a)['data']) === 1, 'Category filter failed');
    }
    // Keep a real stream open during a save, then observe the changed snapshot.
    $stream = curl_init($base . '/api/categories/events.php'); $event = '';
    $cookies = curl_getinfo($clients['customer'], CURLINFO_COOKIELIST);
    curl_setopt_array($stream, [CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 8,
        CURLOPT_WRITEFUNCTION => function ($curl, $chunk) use (&$event) { $event .= $chunk; return strlen($chunk); }]);
    foreach ($cookies as $cookie) curl_setopt($stream, CURLOPT_COOKIELIST, $cookie);
    $multi = curl_multi_init(); curl_multi_add_handle($multi, $stream);
    try {
        $deadline = microtime(true) + 3;
        do { curl_multi_exec($multi, $running); if (!str_contains($event, 'event: categories')) usleep(10000); }
        while (!str_contains($event, 'event: categories') && microtime(true) < $deadline);
        verify(str_contains($event, $prefix . ' Renamed'), 'Initial live snapshot missing');
        callApi($clients['admin'], 'categories/index.php', 'PUT', ['category_id' => $a, 'category_name' => $prefix . ' Live Renamed']);
        // This is the stream owner's session: it must not wait for the stream to finish.
        $sessionStart = microtime(true);
        callApi($clients['customer'], 'categories/index.php?all=1');
        verify(microtime(true) - $sessionStart < 2, 'Stream blocked the customer session');
        $deadline = microtime(true) + 3;
        do { curl_multi_exec($multi, $running); if (!str_contains($event, $prefix . ' Live Renamed')) usleep(10000); }
        while (!str_contains($event, $prefix . ' Live Renamed') && microtime(true) < $deadline);
        verify(str_contains($event, $prefix . ' Live Renamed'), 'Open stream did not reflect the saved rename');
    } finally {
        curl_multi_remove_handle($multi, $stream); curl_close($stream); curl_multi_close($multi);
    }
    foreach (['abc', 0, [], true, 2147483647] as $invalid) callApi($clients['staff'], 'equipment/index.php', 'PUT', ['equipment_id' => $equipmentId, 'category_id' => $invalid] + $equipment, 400);
    callApi($clients['staff'], 'equipment/index.php', 'PUT', ['equipment_id' => $equipmentId, 'category_id' => $b] + $equipment);
    callApi($clients['staff'], 'categories/index.php?id=' . $a, 'DELETE');
    callApi($clients['customer'], 'categories/index.php?id=' . $a, 'GET', null, 404);
    callApi($clients['admin'], 'categories/index.php?id=' . $a, 'DELETE', null, 404);
    verify((int)callApi($clients['customer'], 'equipment/index.php?id=' . $equipmentId)['data']['category_id'] === $b, 'Reassignment lost');
    $db->prepare('DELETE FROM equipment WHERE equipment_id = ?')->execute([$equipmentId]); $equipmentId = null;
    callApi($clients['admin'], 'categories/index.php?id=' . $b, 'DELETE');
    // The complete snapshot is deliberately not capped at the old 50-category dropdown limit.
    $insert = $db->prepare('INSERT INTO categories(category_name) VALUES (?)');
    for ($i = 0; $i < 51; $i++) { $insert->execute([$prefix . ' Extra ' . $i]); $categories[] = (int)$db->lastInsertId(); }
    $all = callApi($clients['customer'], 'categories/index.php?all=1')['data'];
    verify(count(array_filter($all, fn($row) => str_starts_with($row['category_name'], $prefix . ' Extra '))) === 51, 'Complete snapshot truncated');
    echo "PASS: real HTTP permissions, normalization, Unicode spacing, duplicates on create/update, validation, unchanged saves, protected deletion, FK restriction, reassignment, cross-role names/filtering, event stream, full dropdowns, and database persistence.\n";
} finally {
    if ($equipmentId) $db->prepare('DELETE FROM equipment WHERE equipment_id = ? AND equipment_name = ?')->execute([$equipmentId, $prefix]);
    foreach ($categories as $id) $db->prepare('DELETE FROM categories WHERE category_id = ?')->execute([$id]);
    foreach ($clients as $client) { try { callApi($client, 'auth/logout.php', 'POST', []); } catch (Throwable $e) {} curl_close($client); }
    foreach ($users as $id) $db->prepare('DELETE FROM users WHERE user_id = ? AND name = ?')->execute([$id, $prefix]);
    verify($before === $db->query('SELECT * FROM equipment ORDER BY equipment_id')->fetchAll(), 'Existing equipment records changed');
}
