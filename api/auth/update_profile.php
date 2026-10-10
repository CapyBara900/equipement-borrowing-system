<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/account_profile.php';
require_once __DIR__ . '/../../includes/email_validation.php';
require_once __DIR__ . '/../../includes/password_validation.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
$sessionUser = requireLogin();
$body = getJsonBody();
$token = $body['csrf_token'] ?? null;
if (!is_string($token) || empty($_SESSION['profile_csrf_token'])
    || !hash_equals($_SESSION['profile_csrf_token'], $token)) {
    sendJson(403, ['success' => false, 'message' => 'Your form has expired. Reload the profile page and try again.', 'code' => 'CSRF_INVALID']);
}
$action = $body['action'] ?? null;
if (!in_array($action, ['profile', 'password'], true)) {
    sendJson(400, ['success' => false, 'message' => 'Choose a valid profile action.']);
}
$fields = $action === 'profile' ? ['name', 'email'] : ['current_password', 'new_password', 'confirm_new_password'];
foreach ($fields as $field) {
    if (!isset($body[$field]) || !is_string($body[$field])) {
        sendJson(400, ['success' => false, 'message' => 'All form fields are required and must be text.']);
    }
}
if ($action === 'profile') {
    $name = trim($body['name']);
    $error = validateProfileName($name);
    if ($error !== null) sendJson(400, ['success' => false, 'message' => $error, 'code' => 'NAME_INVALID']);
    // Reject markup rather than silently changing the submitted email.
    if ($body['email'] !== strip_tags($body['email'])) {
        sendJson(400, ['success' => false, 'message' => 'Enter a valid email address.', 'code' => 'EMAIL_INVALID']);
    }
    [$email, $error] = normalizeAndValidateEmail($body['email']);
    if ($error !== null) sendJson(400, ['success' => false, 'message' => $error, 'code' => 'EMAIL_INVALID']);
} else {
    $currentPassword = $body['current_password'];
    $newPassword = $body['new_password'];
    if ($currentPassword === '' || strlen($currentPassword) > 200) {
        sendJson(400, ['success' => false, 'message' => 'Enter your current password.', 'code' => 'CURRENT_PASSWORD_INVALID']);
    }
    if ($newPassword !== $body['confirm_new_password']) {
        sendJson(400, ['success' => false, 'message' => 'New passwords do not match.', 'code' => 'PASSWORD_MISMATCH']);
    }
    // PASSWORD_DEFAULT currently uses bcrypt, which truncates after 72 bytes.
    if (strlen($newPassword) > 72 || str_contains($newPassword, "\0")) {
        sendJson(400, ['success' => false, 'message' => 'New password must be 72 bytes or fewer and contain no null characters.', 'code' => 'PASSWORD_INVALID']);
    }
}

$userId = (int)$sessionUser['user_id']; // Never accept the target ID or role from the request.
try {
    $db->beginTransaction();
    // Serialize changes to this account, including verification of its current hash.
    $stmt = $db->prepare('SELECT u.user_id, u.name, u.email, u.password_hash, r.role_name AS role
        FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :user_id FOR UPDATE');
    $stmt->execute(['user_id' => $userId]);
    $user = $stmt->fetch();
    if (!$user) {
        $db->rollBack();
        $_SESSION = [];
        sendJson(401, ['success' => false, 'message' => 'Your account is no longer available. Please sign in again.']);
    }
    if ($action === 'profile') {
        $conflict = profileConflict($db, $name, $email, $userId);
        if ($conflict) {
            $db->rollBack();
            sendJson(409, $conflict);
        }
        $stmt = $db->prepare('UPDATE users SET name = :name, email = :email WHERE user_id = :user_id');
        $stmt->execute(['name' => $name, 'email' => $email, 'user_id' => $userId]);
        $user['name'] = $name;
        $user['email'] = $email;
        $message = 'Profile updated successfully.';
    } else {
        if (!password_verify($currentPassword, $user['password_hash'])) {
            $db->rollBack();
            sendJson(400, ['success' => false, 'message' => 'Current password is incorrect.', 'code' => 'CURRENT_PASSWORD_INVALID']);
        }
        $error = validateNewPassword($newPassword, $user['name'], $user['email']);
        if ($error !== null) {
            $db->rollBack();
            sendJson(400, ['success' => false, 'message' => $error, 'code' => 'PASSWORD_INVALID']);
        }
        if (password_verify($newPassword, $user['password_hash'])) {
            $db->rollBack();
            sendJson(400, ['success' => false, 'message' => 'Choose a password different from your current password.', 'code' => 'PASSWORD_INVALID']);
        }
        $stmt = $db->prepare('UPDATE users SET password_hash = :password_hash WHERE user_id = :user_id');
        $stmt->execute(['password_hash' => password_hash($newPassword, PASSWORD_DEFAULT), 'user_id' => $userId]);
        $message = 'Password changed successfully.';
    }
    $db->commit();
    $_SESSION['name'] = $user['name'];
    $_SESSION['email'] = $user['email'];
    $_SESSION['role'] = $user['role'];
    unset($user['password_hash']);
    sendJson(200, ['success' => true, 'message' => $message, 'data' => $user]);
} catch (PDOException $error) {
    if ($db->inTransaction()) $db->rollBack();
    if ((int)($error->errorInfo[1] ?? 0) === 1062 && $action === 'profile') {
        sendJson(409, profileConflict($db, $name, $email, $userId)
            ?? ['success' => false, 'message' => 'This full name / username or email is already used by another account.']);
    }
    sendJson(500, ['success' => false, 'message' => 'Could not update your account. Please try again.']);
}
