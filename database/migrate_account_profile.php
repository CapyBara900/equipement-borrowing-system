<?php
// Add constraints without deleting, merging, or renumbering existing accounts.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only.'); }
require_once __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$dryRun = in_array('--dry-run', $argv, true);
try {
    foreach (['name', 'email'] as $field) {
        $duplicates = $db->query("SELECT GROUP_CONCAT(user_id ORDER BY user_id) AS ids FROM users
            GROUP BY $field COLLATE utf8mb4_unicode_ci HAVING COUNT(*) > 1")->fetchAll();
        if ($duplicates) {
            throw new RuntimeException("Duplicate $field values exist for user IDs: " . implode('; ', array_column($duplicates, 'ids')) . '. Resolve these before migrating. No schema changes were made.');
        }
    }
    $indexes = $db->query('SHOW INDEX FROM users')->fetchAll();
    $uniqueColumns = [];
    $groups = [];
    foreach ($indexes as $index) {
        if ((int)$index['Non_unique'] === 0) $groups[$index['Key_name']][] = $index;
    }
    foreach ($groups as $group) {
        if (count($group) === 1 && $group[0]['Sub_part'] === null) $uniqueColumns[] = $group[0]['Column_name'];
    }
    $alter = [
        'MODIFY name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
        'MODIFY email VARCHAR(254) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL',
        'MODIFY password_hash VARCHAR(255) NOT NULL',
    ];
    if (!in_array('name', $uniqueColumns, true)) $alter[] = 'ADD UNIQUE KEY uq_users_profile_name (name)';
    if (!in_array('email', $uniqueColumns, true)) $alter[] = 'ADD UNIQUE KEY uq_users_profile_email (email)';
    $sql = 'ALTER TABLE users ' . implode(', ', $alter);
    if ($dryRun) {
        echo "PASS: duplicate preflight. Planned SQL:\n$sql;\n";
    } else {
        $db->exec($sql);
        echo "PASS: profile name/email constraints and hash storage are ready. Existing accounts preserved.\n";
    }
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}
