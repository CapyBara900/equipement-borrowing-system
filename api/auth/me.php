<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

$sessionUser = requireLogin();

// The session identifies the account; request parameters never select a user.
$stmt = $db->prepare(
    'SELECT u.user_id, u.name, u.email, r.role_name AS role
     FROM users u JOIN roles r ON r.role_id = u.role_id
     WHERE u.user_id = :user_id'
);
$stmt->execute(['user_id' => $sessionUser['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    $_SESSION = [];
    sendJson(401, ['success' => false, 'message' => 'Your account is no longer available. Please sign in again.']);
}

// Keep shared navigation and subsequent authorization in sync with the database.
$_SESSION['name'] = $user['name'];
$_SESSION['email'] = $user['email'];
$_SESSION['role'] = $user['role'];

sendJson(200, ['success' => true, 'data' => $user]);
