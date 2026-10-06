<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/email_validation.php';
require_once __DIR__ . '/../../includes/password_validation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body     = getJsonBody();
$name     = cleanText($body['name']     ?? '');
$email = '';
$emailInput = $body['email'] ?? '';
$password = $body['password'] ?? '';

// -----------------------------------------------------------------------
// Email-only duplicate probe (called from the frontend blur handler).
// We respond with 409 if the email is taken, or 200 if it is free —
// never actually creating an account.
// -----------------------------------------------------------------------
if (!empty($body['__check_email_only'])) {
    [$email, $emailError] = normalizeAndValidateEmail($emailInput);
    if ($emailError !== null) {
        sendJson(400, ['success' => false, 'message' => $emailError]);
    }
    try {
        $ck = $db->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
        $ck->execute(['email' => $email]);
        if ($ck->fetch()) {
            sendJson(409, ['success' => false, 'message' => 'This email address is already associated with an account.']);
        }
        sendJson(200, ['success' => true, 'message' => 'Email is available.']);
    } catch (PDOException $e) {
        sendJson(500, ['success' => false, 'message' => 'Could not check email availability.']);
    }
}

// -----------------------------------------------------------------------
// Full registration validation
// -----------------------------------------------------------------------

// --- Required fields ---
[$email, $emailError] = normalizeAndValidateEmail($emailInput);
if ($name === '' || $password === '') {
    sendJson(400, ['success' => false, 'message' => 'Name, email, and password are required.']);
}

// --- Full name rules ---
if (strlen($name) < 5 || strlen($name) > 100) {
    sendJson(400, ['success' => false, 'message' => 'Full name must be between 5 and 100 characters.']);
}
// Only letters (including accented), spaces, hyphens, apostrophes, periods.
if (!preg_match("/^[\p{L} '\-.]+$/u", $name)) {
    sendJson(400, ['success' => false, 'message' => "Full name may only contain letters, spaces, hyphens (-), apostrophes ('), and periods (.).  Numbers and other special characters are not allowed."]);
}
// No consecutive spaces.
if (preg_match('/  /', $name)) {
    sendJson(400, ['success' => false, 'message' => 'Full name must not contain consecutive spaces.']);
}
// No repeated special characters (-- or '' or ..).
if (preg_match("/--|''|\.\./", $name)) {
    sendJson(400, ['success' => false, 'message' => 'Full name must not contain repeated special characters (e.g. --, \'\', ..).']);
}

// --- Email rules ---
if ($emailError !== null) {
    sendJson(400, ['success' => false, 'message' => $emailError]);
}

// --- Password rules ---
$passwordError = validateNewPassword($password, $name, $email);
if ($passwordError !== null) {
    sendJson(400, ['success' => false, 'message' => $passwordError]);
}

// -----------------------------------------------------------------------
// Database operations
// -----------------------------------------------------------------------
try {
    // Case-insensitive duplicate check (email is already lowercased above).
    $check = $db->prepare('SELECT user_id FROM users WHERE email = :email LIMIT 1');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        sendJson(409, ['success' => false, 'message' => 'This email address is already associated with an account.']);
    }

    // Public registration can only ever create a "customer" account.
    // Admin/staff accounts are created by an admin via the users endpoint.
    $roleStmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = :role_name');
    $roleStmt->execute(['role_name' => 'customer']);
    $role = $roleStmt->fetch();
    if (!$role) {
        sendJson(500, ['success' => false, 'message' => 'The "customer" role is missing from the roles table.']);
    }

    $hashedPassword = password_hash($password, PASSWORD_BCRYPT);

    $stmt = $db->prepare(
        'INSERT INTO users (role_id, name, email, password_hash) VALUES (:role_id, :name, :email, :password_hash)'
    );
    $stmt->execute([
        'role_id'       => $role['role_id'],
        'name'          => $name,
        'email'         => $email,
        'password_hash' => $hashedPassword,
    ]);
    $newUserId = (int)$db->lastInsertId();

    sendJson(201, [
        'success' => true,
        'message' => 'Account created.',
        'data'    => ['user_id' => $newUserId, 'name' => $name, 'email' => $email, 'role' => 'customer'],
    ]);
} catch (PDOException $e) {
    if ($e->getCode() === '23000') {
        sendJson(409, ['success' => false, 'message' => 'This email address is already associated with an account.']);
    }
    sendJson(500, ['success' => false, 'message' => 'Registration failed. Please try again.']);
}
