<?php
// Real HTTP tests use temporary accounts only, removed in finally.
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$base = rtrim(getenv('EBS_TEST_BASE_URL') ?: 'http://localhost/equipement-borrowing-system', '/');
function profileCheck(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
function profileClient() {
    $client = curl_init();
    curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    return $client;
}
function profileHttp($client, string $path, int $expected, string $method = 'GET', ?array $body = null): string {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . '/' . $path,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body), CURLOPT_CUSTOMREQUEST => $method]);
    $text = curl_exec($client);
    profileCheck($text !== false, 'HTTP failed: ' . curl_error($client));
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    profileCheck($status === $expected, "$method $path: expected $expected, got $status $text");
    return $text;
}
$prefix = 'Profile test ' . bin2hex(random_bytes(6));
$accounts = [];
$guest = profileClient();
try {
    profileHttp($guest, 'profile.php', 302);
    profileHttp($guest, 'api/auth/me.php', 401);
    foreach (['customer', 'admin', 'staff'] as $role) {
        $stmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = ?');
        $stmt->execute([$role]);
        $roleId = $stmt->fetchColumn();
        $email = uniqid('profile-') . '@example.invalid';
        $name = $prefix . ' ' . $role;
        $db->prepare('INSERT INTO users(role_id, name, email, password_hash) VALUES(?, ?, ?, ?)')
            ->execute([$roleId, $name, $email, password_hash('TemporaryProfile123!', PASSWORD_DEFAULT)]);
        $id = (int)$db->lastInsertId();
        $client = profileClient();
        $accounts[$role] = ['id' => $id, 'role_id' => $roleId, 'client' => $client, 'name' => $name, 'email' => $email];
        profileHttp($client, 'api/auth/login.php', 200, 'POST', ['email' => $email, 'password' => 'TemporaryProfile123!']);
        if ($role === 'staff') {
            foreach (['dashboard.html', 'requests.html'] as $entryPage) {
                $entry = profileHttp($client, $entryPage, 200);
                profileCheck(str_contains($entry, 'assets/js/app.js?v=account-profile-v2'), "$entryPage did not refresh Staff navigation");
                profileCheck(str_contains($entry, 'assets/css/styles.css?v=account-profile-v2'), "$entryPage did not refresh account styles");
            }
        }
        $page = profileHttp($client, 'profile.php', 200);
        profileCheck(str_contains($page, 'assets/js/app.js?v=account-profile-v2'), "$role profile loaded stale navigation");
        foreach (['Account Profile', 'Full Name', 'Email Address', 'User Role', 'profileBack', 'assets/js/profile.js'] as $text) {
            profileCheck(str_contains($page, $text), "$role profile missing $text");
        }
    }
    foreach ($accounts as $role => $account) {
        $client = $account['client'];
        $other = $accounts[$role === 'customer' ? 'admin' : 'customer'];
        foreach (['', '?user_id=' . $other['id'] . '&id=' . $other['id']] as $query) {
            $data = json_decode(profileHttp($client, 'api/auth/me.php' . $query, 200), true)['data'];
            profileCheck((int)$data['user_id'] === $account['id'], 'Another account was exposed');
            profileCheck($data['name'] === $account['name'] && $data['email'] === $account['email'] && $data['role'] === $role, 'Wrong profile fields');
            $keys = array_keys($data); sort($keys);
            profileCheck($keys === ['email', 'name', 'role', 'user_id'], 'Sensitive or unnecessary account fields exposed');
        }
        // Confirm the endpoint reads the database rather than the login snapshot.
        $newEmail = uniqid('profile-updated-') . '@example.invalid';
        $db->prepare('UPDATE users SET name = ?, email = ? WHERE user_id = ?')
            ->execute([$account['name'] . ' updated', $newEmail, $account['id']]);
        $data = json_decode(profileHttp($client, 'api/auth/me.php', 200), true)['data'];
        profileCheck($data['name'] === $account['name'] . ' updated' && $data['email'] === $newEmail, 'Profile used stale session details');
        profileHttp($client, 'api/auth/me.php', 405, 'POST', ['user_id' => $other['id']]);
    }
    $db->prepare('UPDATE users SET role_id = ? WHERE user_id = ?')
        ->execute([$accounts['staff']['role_id'], $accounts['customer']['id']]);
    $data = json_decode(profileHttp($accounts['customer']['client'], 'api/auth/me.php', 200), true)['data'];
    profileCheck($data['role'] === 'staff', 'Profile used a stale role');
    profileHttp($accounts['customer']['client'], 'api/equipment/index.php', 403);

    $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$accounts['staff']['id']]);
    profileHttp($accounts['staff']['client'], 'api/auth/me.php', 401);
    profileHttp($accounts['staff']['client'], 'profile.php', 302);
    profileHttp($accounts['admin']['client'], 'api/auth/logout.php', 200, 'POST');
    profileHttp($accounts['admin']['client'], 'api/auth/me.php', 401);
    profileHttp($accounts['admin']['client'], 'profile.php', 302);
    echo "PASS: live profiles for all roles, own-account isolation, fresh database name/email/role, minimal response fields, method restriction, deleted-account denial, and logout protection.\n";
} finally {
    foreach ($accounts as $account) {
        curl_close($account['client']);
        $db->prepare('DELETE FROM users WHERE user_id = ? AND name LIKE ?')->execute([$account['id'], $prefix . '%']);
    }
    curl_close($guest);
}
