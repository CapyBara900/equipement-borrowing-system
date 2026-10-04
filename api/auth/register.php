<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$body = getJsonBody();
$name     = cleanText($body['name'] ?? '');
$email    = trim(strtolower(cleanText($body['email'] ?? '')));
$password = $body['password'] ?? '';

if ($name === '' || $email === '' || $password === '') {
    sendJson(400, ['success' => false, 'message' => 'Name, email, and password are required.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    sendJson(400, ['success' => false, 'message' => 'Invalid email address.']);
}
if (strlen($name) > 100 || strlen($email) > 150) {
    sendJson(400, ['success' => false, 'message' => 'Name or email is too long.']);
}
if (strlen($password) < 8) {
    sendJson(400, ['success' => false, 'message' => 'Password must be at least 8 characters.']);
}

try {
    $check = $db->prepare('SELECT user_id FROM users WHERE email = :email');
    $check->execute(['email' => $email]);
    if ($check->fetch()) {
        sendJson(409, ['success' => false, 'message' => 'An account with that email already exists.']);
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
    sendJson(500, ['success' => false, 'message' => 'Registration failed. Please try again.']);
}
