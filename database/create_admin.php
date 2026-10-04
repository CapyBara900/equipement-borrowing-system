<?php
/**
 * Run this ONCE from the command line to create the first admin account:
 *   php database/create_admin.php "Admin Name" admin@ebs.com "SomeStrongPassword123"
 *
 * Delete or restrict access to this file after you've created your admin(s) —
 * it is not meant to be reachable over the web.
 */

require_once __DIR__ . '/../config/database.php';

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('This script may only be run from the command line.');
}

[$script, $name, $email, $password] = array_pad($argv, 4, null);

if (!$name || !$email || !$password) {
    fwrite(STDERR, "Usage: php create_admin.php \"Name\" email@example.com \"Password123\"\n");
    exit(1);
}

$db = (new Database())->getConnection();

$roleStmt = $db->prepare('SELECT role_id FROM roles WHERE role_name = :role_name');
$roleStmt->execute(['role_name' => 'admin']);
$role = $roleStmt->fetch();
if (!$role) {
    fwrite(STDERR, "The 'admin' role was not found — did you run database/schema.sql first?\n");
    exit(1);
}

$hashed = password_hash($password, PASSWORD_BCRYPT);
$email = strtolower($email);

$stmt = $db->prepare(
    'INSERT INTO users (role_id, name, email, password_hash) VALUES (:role_id, :name, :email, :password_hash)
     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role_id = VALUES(role_id)'
);
$stmt->execute(['role_id' => $role['role_id'], 'name' => $name, 'email' => $email, 'password_hash' => $hashed]);

echo "Admin account ready for {$email}\n";
