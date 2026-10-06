<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = getJsonBody();
$email    = trim(strtolower(cleanText($body['email'] ?? '')));
$password = $body['password'] ?? '';

if (strlen($email) > 254 || strlen($password) > 200) {
    sendJson(400, ['success' => false, 'message' => 'Email or password is invalid.']);
}

if ($email === '' || $password === '') {
    sendJson(400, ['success' => false, 'message' => 'Email and password are required.']);
}

try {
    $stmt = $db->prepare(
        'SELECT u.user_id, u.name, u.email, u.password_hash, u.failed_login_attempts, u.locked_until, r.role_name
         FROM users u JOIN roles r ON r.role_id = u.role_id
         WHERE u.email = :email'
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user) {
        sendJson(401, ['success' => false, 'message' => 'Invalid email or password.']);
    }

    // A locked account can only be restored by an administrator, or after the
    // one-hour lock period has elapsed.
    if (!empty($user['locked_until'])) {
        $lockedUntil = strtotime($user['locked_until']);
        if ($lockedUntil > time()) {
            sendJson(423, [
                'success' => false,
                'message' => 'This account is temporarily locked because of multiple failed login attempts. Please try again in 1 hour or contact the administrator.'
            ]);
        }

        // The lock period has expired. Start a fresh set of attempts.
        $stmt = $db->prepare(
            'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id'
        );
        $stmt->execute(['id' => $user['user_id']]);
        $user['failed_login_attempts'] = 0;
        $user['locked_until'] = null;
    }

    if (!password_verify($password, $user['password_hash'])) {
        $attempts = (int)$user['failed_login_attempts'] + 1;

        if ($attempts >= 3) {
            $stmt = $db->prepare(
                'UPDATE users SET failed_login_attempts = 0, locked_until = DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE user_id = :id'
            );
            $stmt->execute(['id' => $user['user_id']]);

            sendJson(423, [
                'success' => false,
                'message' => 'This account has been temporarily locked after 3 failed login attempts. Please try again in 1 hour or contact the administrator.'
            ]);
        }

        $stmt = $db->prepare(
            'UPDATE users SET failed_login_attempts = :attempts WHERE user_id = :id'
        );
        $stmt->execute(['attempts' => $attempts, 'id' => $user['user_id']]);

        sendJson(401, ['success' => false, 'message' => 'Invalid email or password.']);
    }

    // Successful login clears the failed-attempt counter and any expired lock.
    $stmt = $db->prepare(
        'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id'
    );
    $stmt->execute(['id' => $user['user_id']]);

    // Regenerate the session id on login to prevent session fixation.
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['user_id'];
    $_SESSION['name']    = $user['name'];
    $_SESSION['email']   = $user['email'];
    $_SESSION['role']    = $user['role_name'];

    sendJson(200, [
        'success' => true,
        'message' => 'Logged in.',
        'data'    => [
            'user_id' => $user['user_id'],
            'name'    => $user['name'],
            'email'   => $user['email'],
            'role'    => $user['role_name'],
        ],
    ]);
} catch (PDOException $e) {
    sendJson(500, ['success' => false, 'message' => 'Login failed. Please try again.']);
}
