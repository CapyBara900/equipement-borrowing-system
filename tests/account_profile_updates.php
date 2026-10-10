<?php
// Isolated database + PHP HTTP server: never change real accounts or schema.
if (PHP_SAPI !== 'cli') exit('CLI only.');
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$testName = 'ebs_profile_test_' . bin2hex(random_bytes(6));
$server = null;
$sessionDir = sys_get_temp_dir() . '/ebs-profile-sessions-' . bin2hex(random_bytes(6));
mkdir($sessionDir);
$clients = [];
$log = tempnam(sys_get_temp_dir(), 'ebs-profile-');
function checkProfileUpdate(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function profileTestCommand(array $command, array $env): string {
    $pipes = [];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__), $env);
    checkProfileUpdate(is_resource($process), 'Could not start PHP process.');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $error = stream_get_contents($pipes[2]); fclose($pipes[2]);
    checkProfileUpdate(proc_close($process) === 0, 'PHP command failed: ' . $error . $output);
    return $output;
}
function updateHttp($client, string $route, int $expected, ?array $body = null, string $method = 'POST'): array {
    global $base;
    curl_setopt_array($client, [CURLOPT_URL => $base . '/' . $route, CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body)]);
    $text = curl_exec($client);
    checkProfileUpdate($text !== false, curl_error($client));
    $status = curl_getinfo($client, CURLINFO_RESPONSE_CODE);
    checkProfileUpdate($status === $expected, "$method $route expected $expected, got $status: $text");
    if ($route === 'profile.php') {
        preg_match('/id="profileCsrf" value="([a-f0-9]+)"/', $text, $matches);
        checkProfileUpdate(isset($matches[1]), 'Missing CSRF token.');
        return ['token' => $matches[1]];
    }
    $result = json_decode($text, true);
    checkProfileUpdate(is_array($result) && isset($result['success']), 'Missing structured JSON response: ' . $text);
    checkProfileUpdate(!str_contains($text, 'password_hash'), 'Password hash leaked.');
    return $result;
}
try {
    $db->exec("CREATE DATABASE `$testName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $db->exec("CREATE TABLE `$testName`.roles LIKE roles");
    $db->exec("CREATE TABLE `$testName`.users LIKE users");
    $db->exec("USE `$testName`");
    $db->exec("INSERT INTO roles(role_id, role_name) VALUES(1, 'customer'), (2, 'admin'), (3, 'staff')");
    $env = array_merge(getenv(), ['DB_NAME' => $testName]);
    profileTestCommand([PHP_BINARY, 'database/migrate_account_profile.php', '--dry-run'], $env);
    profileTestCommand([PHP_BINARY, 'database/migrate_account_profile.php'], $env);
    profileTestCommand([PHP_BINARY, 'database/migrate_account_profile.php'], $env); // Safe rerun.
    $port = random_int(20000, 45000);
    $base = 'http://127.0.0.1:' . $port;
    $pipes = [];
    $server = proc_open([PHP_BINARY, '-d', 'session.save_path=' . $sessionDir, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__)],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $env);
    checkProfileUpdate(is_resource($server), 'Could not start test server.');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $probe = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(100000);
    }
    $guest = curl_init();
    curl_setopt_array($guest, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
    $clients[] = $guest;
    updateHttp($guest, 'update_profile.php', 401, ['action' => 'profile']);
    updateHttp($guest, 'api/auth/update_profile.php', 405, null, 'GET');
    $password = 'OriginalSecret123!';
    $newPassword = 'DifferentSecret456!';
    $accounts = [];
    foreach (['customer', 'admin', 'staff'] as $i => $role) {
        $name = ucfirst($role) . ' Person';
        $email = $role . '@example.invalid';
        $db->prepare('INSERT INTO users(role_id, name, email, password_hash) VALUES(?, ?, ?, ?)')
            ->execute([$i + 1, $name, $email, password_hash($password, PASSWORD_DEFAULT)]);
        $id = (int)$db->lastInsertId();
        $client = curl_init();
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 10]);
        $clients[] = $client;
        updateHttp($client, 'api/auth/login.php', 200, ['email' => $email, 'password' => $password]);
        $token = updateHttp($client, 'profile.php', 200, null, 'GET')['token'];
        $accounts[$role] = compact('client', 'id', 'token', 'name', 'email');
    }
    // Existing creation routes must report the new uniqueness policy correctly.
    $duplicateSignup = updateHttp($guest, 'api/auth/register.php', 409,
        ['name' => $accounts['customer']['name'], 'email' => 'new-signup@example.invalid', 'password' => $password]);
    checkProfileUpdate($duplicateSignup['code'] === 'NAME_TAKEN', 'Registration did not report duplicate name.');
    $duplicateAdminCreate = updateHttp($accounts['admin']['client'], 'api/users/index.php', 409,
        ['name' => $accounts['staff']['name'], 'email' => 'new-staff@example.invalid', 'password' => $password, 'role' => 'staff']);
    checkProfileUpdate($duplicateAdminCreate['code'] === 'NAME_TAKEN', 'Admin creation did not report duplicate name.');
    foreach ($accounts as $role => $account) {
        extract($account);
        $other = $accounts[$role === 'customer' ? 'admin' : 'customer'];
        $profile = ['action' => 'profile', 'csrf_token' => $token, 'name' => $name, 'email' => $email];
        updateHttp($client, 'update_profile.php', 403, array_merge($profile, ['csrf_token' => 'wrong']));
        $missingToken = $profile; unset($missingToken['csrf_token']);
        updateHttp($client, 'update_profile.php', 403, $missingToken);
        updateHttp($client, 'update_profile.php', 400, array_merge($profile, ['action' => 'promote']));
        foreach (['', [], "<script>alert(1)</script>", str_repeat('x', 101)] as $invalidName) {
            updateHttp($client, 'update_profile.php', 400, array_merge($profile, ['name' => $invalidName]));
        }
        updateHttp($client, 'update_profile.php', 400, array_merge($profile, ['email' => 'bad-email']));
        updateHttp($client, 'update_profile.php', 400, array_merge($profile, ['email' => []]));
        $collision = updateHttp($client, 'update_profile.php', 409, array_merge($profile, ['name' => strtoupper($other['name'])]));
        checkProfileUpdate($collision['code'] === 'NAME_TAKEN', 'Incorrect name conflict.');
        $collision = updateHttp($client, 'update_profile.php', 409, array_merge($profile, ['email' => strtoupper($other['email'])]));
        checkProfileUpdate($collision['code'] === 'EMAIL_TAKEN', 'Incorrect email conflict.');
        updateHttp($client, 'update_profile.php', 200, $profile); // Unchanged own values are allowed.
        $updatedName = $name . '_Updated É';
        $updatedEmail = $role . '-updated@example.invalid';
        $saved = updateHttp($client, 'update_profile.php', 200, array_merge($profile,
            ['name' => '  ' . $updatedName . '  ', 'email' => '  ' . strtoupper($updatedEmail) . '  ', 'user_id' => $other['id'], 'role' => 'admin']));
        checkProfileUpdate((int)$saved['data']['user_id'] === $id && $saved['data']['role'] === $role, 'Target ID or role injection changed authorization.');
        $fresh = updateHttp($client, 'api/auth/me.php', 200, null, 'GET')['data'];
        checkProfileUpdate($fresh['name'] === $updatedName && $fresh['email'] === $updatedEmail, 'Name/email did not refresh.');
        $otherRow = $db->query('SELECT name, email FROM users WHERE user_id = ' . (int)$other['id'])->fetch();
        checkProfileUpdate($otherRow['name'] === $other['name'] && $otherRow['email'] === $other['email'], 'Other account was changed.');
        $passwordBody = ['action' => 'password', 'csrf_token' => $token, 'current_password' => $password,
            'new_password' => $newPassword, 'confirm_new_password' => $newPassword];
        $wrong = updateHttp($client, 'update_profile.php', 400, array_merge($passwordBody, ['current_password' => 'wrong']));
        checkProfileUpdate($wrong['code'] === 'CURRENT_PASSWORD_INVALID', 'Incorrect current-password result.');
        updateHttp($client, 'update_profile.php', 400, array_merge($passwordBody, ['confirm_new_password' => 'different']));
        foreach (['weak', str_repeat('A', 73), $password, ucfirst($role) . 'Secret123!', "Null\0Secret123!"] as $invalidPassword) {
            updateHttp($client, 'update_profile.php', 400, array_merge($passwordBody, ['new_password' => $invalidPassword, 'confirm_new_password' => $invalidPassword]));
        }
        checkProfileUpdate(password_verify($password, $db->query('SELECT password_hash FROM users WHERE user_id = ' . $id)->fetchColumn()), 'Rejected password changed stored hash.');
        updateHttp($client, 'update_profile.php', 200, $passwordBody);
        $hash = $db->query('SELECT password_hash FROM users WHERE user_id = ' . $id)->fetchColumn();
        checkProfileUpdate(password_verify($newPassword, $hash) && !password_verify($password, $hash), 'Wrong password stored.');
        updateHttp($client, 'api/auth/logout.php', 200, []);
        updateHttp($client, 'api/auth/login.php', 401, ['email' => $updatedEmail, 'password' => $password]);
        updateHttp($client, 'api/auth/login.php', 200, ['email' => $updatedEmail, 'password' => $newPassword]);
        $accounts[$role]['name'] = $updatedName;
        $accounts[$role]['email'] = $updatedEmail;
    }
    // Database constraints reject writes that bypass the API precheck.
    foreach (['name', 'email'] as $field) {
        try {
            $db->prepare("UPDATE users SET $field = ? WHERE user_id = ?")
                ->execute([strtoupper($accounts['admin'][$field]), $accounts['customer']['id']]);
            throw new RuntimeException("Missing $field unique constraint.");
        } catch (PDOException $error) {
            checkProfileUpdate((int)($error->errorInfo[1] ?? 0) === 1062, 'Wrong uniqueness error.');
        }
    }
    // Removed accounts must lose access even if their PHP session still exists.
    $deleted = $accounts['staff'];
    $token = updateHttp($deleted['client'], 'profile.php', 200, null, 'GET')['token'];
    $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$deleted['id']]);
    updateHttp($deleted['client'], 'update_profile.php', 401,
        ['action' => 'profile', 'csrf_token' => $token, 'name' => 'Deleted Person', 'email' => 'deleted@example.invalid']);
    echo "PASS: isolated HTTP updates for all roles, migration dry-run/rerun, unique name/email constraints, registration/admin duplicate handling, CSRF, malformed input, own-account isolation, password failures, password hashing, old/new login, fresh profile, and deleted-account denial.\n";
} finally {
    foreach ($clients as $client) curl_close($client);
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $db->exec('USE information_schema');
    $db->exec("DROP DATABASE IF EXISTS `$testName`");
    if (is_file($log)) unlink($log);
    foreach (glob($sessionDir . '/sess_*') as $sessionFile) unlink($sessionFile);
    rmdir($sessionDir);
}
