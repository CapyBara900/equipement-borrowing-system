<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/email_validation.php';
require_once __DIR__ . '/../../includes/password_validation.php';
require_once __DIR__ . '/../../includes/account_profile.php';
require_once __DIR__ . '/../../includes/borrowing_management.php';

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
        if (empty($_SESSION['admin_users_csrf_token'])) {
            $_SESSION['admin_users_csrf_token'] = bin2hex(random_bytes(32));
        }
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll(), 'csrf_token' => $_SESSION['admin_users_csrf_token']]);
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
            $conflict = profileConflict($db, $name, $email);
            if ($conflict) sendJson(409, $conflict);

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
            if ((int)($e->errorInfo[1] ?? 0) === 1062) {
                sendJson(409, profileConflict($db, $name, $email) ?? ['success' => false, 'message' => 'This name or email is already associated with an account.']);
            }
            sendJson(409, ['success' => false, 'message' => 'Could not create user (email may already exist).']);
        }
        break;

    case 'PUT':
        // Admin changes roles, resets passwords, or restores temporarily locked accounts.
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

        if ($action === 'reset_password') {
            $resetUserId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($resetUserId === false) {
                sendJson(400, ['success' => false, 'message' => 'A valid user_id is required.']);
            }
            if ($resetUserId === (int)$current['user_id']) {
                sendJson(400, ['success' => false, 'message' => 'Use your account profile to change your own password.']);
            }
            $token = $body['csrf_token'] ?? null;
            if (!is_string($token) || empty($_SESSION['admin_users_csrf_token'])
                || !hash_equals($_SESSION['admin_users_csrf_token'], $token)) {
                sendJson(403, ['success' => false, 'message' => 'Your form has expired. Reload this page and try again.', 'code' => 'CSRF_INVALID']);
            }
            $password = $body['password'] ?? null;
            if (!is_string($password)) {
                sendJson(400, ['success' => false, 'message' => 'A new password is required.', 'code' => 'PASSWORD_INVALID']);
            }
            // Bcrypt truncates passwords after 72 bytes.
            if (strlen($password) > 72 || str_contains($password, "\0")) {
                sendJson(400, ['success' => false, 'message' => 'New password must be 72 bytes or fewer and contain no null characters.', 'code' => 'PASSWORD_INVALID']);
            }
            try {
                $db->beginTransaction();
                $stmt = $db->prepare('SELECT user_id, name, email, password_hash FROM users WHERE user_id = :id FOR UPDATE');
                $stmt->execute(['id' => $resetUserId]);
                $target = $stmt->fetch();
                if (!$target) {
                    $db->rollBack();
                    sendJson(404, ['success' => false, 'message' => 'User not found.']);
                }
                $passwordError = validateNewPassword($password, $target['name'], $target['email']);
                if ($passwordError !== null) {
                    $db->rollBack();
                    sendJson(400, ['success' => false, 'message' => $passwordError, 'code' => 'PASSWORD_INVALID']);
                }
                if (password_verify($password, $target['password_hash'])) {
                    $db->rollBack();
                    sendJson(400, ['success' => false, 'message' => 'Choose a password different from the current password.', 'code' => 'PASSWORD_INVALID']);
                }
                $stmt = $db->prepare('UPDATE users SET password_hash = :password_hash, failed_login_attempts = 0, locked_until = NULL WHERE user_id = :id');
                $stmt->execute(['password_hash' => password_hash($password, PASSWORD_BCRYPT), 'id' => $resetUserId]);
                $db->commit();
                sendJson(200, ['success' => true, 'message' => 'Password reset successfully.']);
            } catch (PDOException $error) {
                if ($db->inTransaction()) $db->rollBack();
                sendJson(500, ['success' => false, 'message' => 'Could not reset the password. Please try again.']);
            }
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
        try {
            $db->beginTransaction();
            $stmt = $db->prepare('SELECT user_id FROM users WHERE user_id = :id FOR UPDATE');
            $stmt->execute(['id' => $id]);
            if (!$stmt->fetch()) {
                $db->rollBack();
                sendJson(404, ['success' => false, 'message' => 'User not found.']);
            }
            $history = $db->prepare('SELECT request_id FROM borrowing_requests WHERE user_id = :id LIMIT 1');
            $history->execute(['id' => $id]);
            if ($history->fetch()) {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => 'A customer with borrowing history cannot be deleted.', 'code' => 'BORROWING_HISTORY_EXISTS']);
            }
            $auditSchema = borrowingAuditSchema($db);
            if (isset($auditSchema['columns']['changed_by_user_id'])) {
                $auditActor = $db->prepare('SELECT history_id FROM borrowing_status_history WHERE changed_by_user_id = :id LIMIT 1');
                $auditActor->execute(['id' => $id]);
                if ($auditActor->fetch()) {
                    $db->rollBack();
                    sendJson(409, ['success' => false, 'message' => 'An account recorded in borrowing status history cannot be deleted.', 'code' => 'BORROWING_HISTORY_EXISTS']);
                }
            }
            $db->prepare('DELETE FROM users WHERE user_id = :id')->execute(['id' => $id]);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            sendJson(409, ['success' => false, 'message' => 'This account is referenced by existing records and could not be deleted.', 'code' => 'USER_DELETE_CONFLICT']);
        }
        sendJson(200, ['success' => true, 'message' => 'User deleted.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
