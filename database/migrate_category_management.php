<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration from the command line.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/response.php';
require_once __DIR__ . '/../includes/category_management.php';
$db = (new Database())->getConnection();
$dry = in_array('--dry-run', $argv, true);
if ((int)$db->query("SELECT GET_LOCK('ebs_category_migration', 10)")->fetchColumn() !== 1) throw new RuntimeException('Another category migration is running.');
try {
    $db->beginTransaction();
    $rows = $db->query('SELECT category_id, category_name FROM categories FOR UPDATE')->fetchAll();
    // Preflight the exact target collation before changing any existing record.
    $db->exec('CREATE TEMPORARY TABLE category_name_preflight (
        category_id INT PRIMARY KEY,
        category_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL UNIQUE
    ) ENGINE=InnoDB');
    $insert = $db->prepare('INSERT INTO category_name_preflight VALUES (?, ?)');
    foreach ($rows as &$row) {
        $row['normalized'] = normalizeCategoryName($row['category_name']);
        try { $insert->execute([$row['category_id'], $row['normalized']]); }
        catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            throw new RuntimeException('Existing category names collide after normalization. Rename the duplicate categories before running this migration. No equipment or category assignments have been changed.');
        }
    }
    unset($row);
    if (!$dry) {
        $update = $db->prepare('UPDATE categories SET category_name = ? WHERE category_id = ?');
        foreach ($rows as $row) if ($row['category_name'] !== $row['normalized']) $update->execute([$row['normalized'], $row['category_id']]);
    }
    $db->commit();
    $column = $db->query("SELECT COLLATION_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'categories' AND COLUMN_NAME = 'category_name'")->fetchColumn();
    if ($column !== 'utf8mb4_unicode_ci') {
        $sql = 'ALTER TABLE categories MODIFY category_name VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL';
        if (!$dry) $db->exec($sql);
        echo ($dry ? 'PLAN ' : 'APPLIED ') . $sql . "\n";
    }
    $stmt = $db->query("SELECT CONSTRAINT_NAME, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'equipment' AND REFERENCED_TABLE_NAME = 'categories'");
    $foreignKey = $stmt->fetch();
    if (!$foreignKey || !in_array($foreignKey['DELETE_RULE'], ['RESTRICT', 'NO ACTION'], true)) {
        $drop = $foreignKey ? 'DROP FOREIGN KEY ' . '`' . str_replace('`', '``', $foreignKey['CONSTRAINT_NAME']) . '`' . ', ' : '';
        $sql = 'ALTER TABLE equipment ' . $drop . 'ADD CONSTRAINT fk_equipment_category_restrict FOREIGN KEY (category_id) REFERENCES categories(category_id) ON DELETE RESTRICT';
        if (!$dry) $db->exec($sql);
        echo ($dry ? 'PLAN ' : 'APPLIED ') . $sql . "\n";
    }
    echo $dry ? "Dry run complete; no existing data changed.\n" : "Category migration complete; category IDs, equipment, and assignments preserved.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    $db->query("SELECT RELEASE_LOCK('ebs_category_migration')");
}
