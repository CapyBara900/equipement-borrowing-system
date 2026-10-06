<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/email_validation.php';
require_once __DIR__ . '/../../includes/password_validation.php';

const USER_SELECT = '
    SELECT u.user_id, u.name, u.email, r.role_name AS role, u.created_at,
           u.failed_login_attempts, u.locked_until
    FROM users u JOIN roles r ON r.role_id = u.role_id
';

function findRoleId(PDO $db, string $roleName): ?int
{
    $stmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = :role_name');
    $stmt->execute(['role_name' => $roleName]);
    $row = $stmt->fetch();
    return $row ? (int)$row['role_id'] : null;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        requireRole(['admin']);
        $stmt = $db->query(USER_SELECT . ' ORDER BY u.name');
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'POST':
        // Admin creates a staff or admin account directly (customers self-register instead).
        requireRole(['admin']);
        $body = getJsonBody();
        $name     = cleanText($body['name'] ?? '');
        [$email, $emailError] = normalizeAndValidateEmail($body['email'] ?? '');
        $password = $body['password'] ?? '';
        $roleName = $body['role'] ?? 'staff';

        if ($name === '' || $email === '' || $password === '') {
            sendJson(400, ['success' => false, 'message' => 'name, email, and password are required.']);
        }
        if ($emailError !== null) {
            sendJson(400, ['success' => false, 'message' => $emailError]);
        }
        if (strlen($name) > 100) {
            sendJson(400, ['success' => false, 'message' => 'Name, email, or password is too long.']);
        }
        $passwordError = validateNewPassword($password, $name, $email);
        if ($passwordError !== null) {
            sendJson(400, ['success' => false, 'message' => $passwordError]);
        }
        $roleId = findRoleId($db, $roleName);
        if (!$roleId) {
            sendJson(400, ['success' => false, 'message' => 'role must be admin, staff, or customer.']);
        }

        try {
            $stmt = $db->prepare(
                'INSERT INTO users (role_id, name, email, password_hash) VALUES (:role_id, :name, :email, :password_hash)'
            );
            $stmt->execute([
                'role_id'       => $roleId,
                'name'          => $name,
                'email'         => $email,
                'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            ]);
            $newId = (int)$db->lastInsertId();
            sendJson(201, ['success' => true, 'data' => ['user_id' => $newId, 'name' => $name, 'email' => $email, 'role' => $roleName]]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                sendJson(409, ['success' => false, 'message' => 'This email address is already associated with an account.']);
            }
            sendJson(409, ['success' => false, 'message' => 'Could not create user (email may already exist).']);
        }
        break;

    case 'PUT':
        // Admin changes someone's role or restores a temporarily locked account. (e.g. promote a customer to staff).
        requireRole(['admin']);
        $body = getJsonBody();
        $id = $body['user_id'] ?? null;
        $roleName = $body['role'] ?? null;
        $action = $body['action'] ?? null;

        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'user_id is required.']);
        }
        $current = requireLogin();
        if ((int)$id === (int)$current['user_id'] && $action === 'unlock') {
            sendJson(400, ['success' => false, 'message' => 'You cannot unlock your own account from this screen.']);
        }

        if ($action === 'unlock') {
            $stmt = $db->prepare(
                'UPDATE users SET failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id'
            );
            $stmt->execute(['id' => $id]);
            if ($stmt->rowCount() === 0) {
                $check = $db->prepare('SELECT user_id FROM users WHERE user_id = :id');
                $check->execute(['id' => $id]);
                if (!$check->fetch()) {
                    sendJson(404, ['success' => false, 'message' => 'User not found.']);
                }
            }
            sendJson(200, ['success' => true, 'message' => 'Account access restored.']);
        }

        $roleId = $roleName ? findRoleId($db, $roleName) : null;
        if (!$roleId) {
            sendJson(400, ['success' => false, 'message' => 'A valid role is required.']);
        }
        if ((int)$id === (int)$current['user_id']) {
            sendJson(400, ['success' => false, 'message' => 'You cannot change your own role.']);
        }
        $stmt = $db->prepare('SELECT user_id FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            sendJson(404, ['success' => false, 'message' => 'User not found.']);
        }
        $stmt = $db->prepare('UPDATE users SET role_id = :role_id WHERE user_id = :id');
        $stmt->execute(['role_id' => $roleId, 'id' => $id]);
        sendJson(200, ['success' => true, 'message' => 'Role updated.']);
        break;

    case 'DELETE':
        requireRole(['admin']);
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'id query param is required.']);
        }
        $current = requireLogin();
        if ((int)$id === (int)$current['user_id']) {
            sendJson(400, ['success' => false, 'message' => 'You cannot delete your own account.']);
        }
        $stmt = $db->prepare('SELECT user_id FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $id]);
        if (!$stmt->fetch()) {
            sendJson(404, ['success' => false, 'message' => 'User not found.']);
        }
        $stmt = $db->prepare('DELETE FROM users WHERE user_id = :id');
        $stmt->execute(['id' => $id]);
        sendJson(200, ['success' => true, 'message' => 'User deleted.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
