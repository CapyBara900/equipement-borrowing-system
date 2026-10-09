<?php
// CLI-only additive migration. MySQL DDL commits implicitly; completed steps are resumable.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration from the command line.'); }
require __DIR__ . '/../config/database.php';
$db = (new Database())->getConnection();
$lock = $db->query("SELECT GET_LOCK('ebs_borrowing_cart_v3_migration', 10)")->fetchColumn();
if ((int)$lock !== 1) throw new RuntimeException('Another borrowing cart migration is running.');
try {
    $version = $db->query('SELECT VERSION()')->fetchColumn();
    $minimum = str_contains($version, 'MariaDB') ? '10.2.1' : '8.0.16';
    if (version_compare(preg_replace('/[^0-9.].*/', '', $version), $minimum, '<')) throw new RuntimeException('Enforced CHECK constraints require MariaDB 10.2.1+ or MySQL 8.0.16+.');
    $tables = $db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach (['users','equipment','borrowing_requests','returns'] as $table) if (($tables[$table] ?? '') !== 'InnoDB') throw new RuntimeException("$table must exist and use InnoDB. No data was changed.");
    $checks = [
        'equipment quantities' => 'SELECT COUNT(*) FROM equipment WHERE total_quantity < 1 OR available_quantity > total_quantity',
        'request quantities' => 'SELECT COUNT(*) FROM borrowing_requests WHERE requested_quantity < 1',
        'return quantities' => 'SELECT COUNT(*) FROM returns WHERE returned_quantity < 1',
        'active reservations' => "SELECT COUNT(*) FROM (SELECT e.equipment_id FROM equipment e LEFT JOIN borrowing_requests r ON r.equipment_id=e.equipment_id AND r.status IN ('pending','approved','borrowed') GROUP BY e.equipment_id HAVING MAX(e.available_quantity) + COALESCE(SUM(r.requested_quantity),0) > MAX(e.total_quantity)) invalid_stock",
    ];
    foreach ($checks as $label => $query) if ((int)$db->query($query)->fetchColumn() !== 0) throw new RuntimeException("Existing $label are inconsistent. Resolve them explicitly before migration; this script does not rewrite history or stock.");
    // Do not silently accept a partially compatible externally created table.
    foreach (['borrowing_cart_items'=>['user_id','equipment_id','quantity','created_at','updated_at'], 'borrowing_checkouts'=>['checkout_id','user_id','idempotency_key','payload_hash','response_json','borrow_date','expected_return_date','created_at']] as $table=>$required) {
        if (!isset($tables[$table])) continue;
        $columns=$db->query('SHOW COLUMNS FROM '.$table)->fetchAll(PDO::FETCH_COLUMN);
        if ($tables[$table] !== 'InnoDB' || array_diff($required,$columns)) throw new RuntimeException("Existing $table has an incompatible definition. It was left unchanged.");
    }
    $dryRun = in_array('--dry-run', $argv, true);
    $source = preg_replace('/^--(?! @when ).*$/m', '', file_get_contents(__DIR__.'/migration_borrowing_cart.sql'));
    foreach (explode(';', $source) as $statement) {
        $when = null;
        if (preg_match('/-- @when (column|index|constraint|enum) ([a-z_]+) ([a-z_]+)/', $statement, $matches)) $when = array_slice($matches,1);
        $sql = trim(preg_replace('/--[^\r\n]*/', '', $statement));
        if ($sql === '') continue;
        if ($when) {
            [$kind,$table,$name]=$when;
            // Borrowing Management replaces this FK with a restrictive relationship.
            // Reuse it by its columns, so rerunning this older migration cannot add
            // a duplicate SET NULL foreign key under the retired constraint name.
            if ($kind === 'constraint' && $table === 'borrowing_requests' && $name === 'fk_request_checkout') {
                $relationship = $db->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_requests' AND COLUMN_NAME='checkout_id' AND REFERENCED_TABLE_NAME='borrowing_checkouts' AND REFERENCED_COLUMN_NAME='checkout_id'")->fetchColumn();
                if ((int)$relationship > 0) { echo "SKIP existing checkout relationship\n"; continue; }
            }
            if ($name === 'uq_requests_checkout_equipment' && (int)$db->query("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_requests' AND INDEX_NAME='uq_requests_checkout_dates'")->fetchColumn() > 0) { echo "SKIP superseded equipment-only group index\n"; continue; }
            if ($kind === 'enum') {
                $query=$db->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='status'");
                $query->execute([$table]);
                if (str_contains((string)$query->fetchColumn(), "'$name'")) { echo "SKIP enum $table.$name\n"; continue; }
            } else {
            [$metadata,$field] = match ($kind) {
                'column'=>['COLUMNS','COLUMN_NAME'],
                'index'=>['STATISTICS','INDEX_NAME'],
                'constraint'=>['TABLE_CONSTRAINTS','CONSTRAINT_NAME'],
            };
            $query=$db->prepare("SELECT COUNT(*) FROM information_schema.$metadata WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND $field=?");
            $query->execute([$table,$name]);
            if ((int)$query->fetchColumn()>0) { echo "SKIP $kind $table.$name\n"; continue; }
            }
        }
        if (!$dryRun) $db->exec($sql);
        echo ($dryRun?'PLAN ':'APPLIED ') . preg_replace('/\s+/', ' ', strtok($sql,"\n")) . "\n";
    }
    if (!$dryRun) {
        require_once __DIR__.'/../includes/borrowing_cart.php';
        $dated = (int)$db->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_cart_items' AND COLUMN_NAME='cart_item_id'")->fetchColumn() > 0;
        $capabilities = $dated ? cartCapabilities($db) : ['checkout_available' => true];
        if (!$capabilities['checkout_available']) throw new RuntimeException('Migration readiness verification failed: ' . implode(', ', $capabilities['missing_requirements']));
    }
    echo $dryRun ? "Dry run complete; no schema/data changes.\n" : "Borrowing cart migration complete. Existing stock and request records were not rewritten.\n";
} finally {
    $db->query("SELECT RELEASE_LOCK('ebs_borrowing_cart_v3_migration')");
}
