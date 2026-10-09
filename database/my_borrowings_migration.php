<?php
require_once __DIR__ . '/../includes/borrowing_cart.php';
require_once __DIR__ . '/../includes/borrowing_history.php';

function historyMigrationIdentifier(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

/** Hash original columns without exposing account data or requiring a data dump. */
function historyMigrationSnapshot(PDO $db, ?array $baseline = null): array
{
    $result = [];
    $tables = $baseline === null ? $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN) : array_keys($baseline);
    foreach ($tables as $table) {
        $name = historyMigrationIdentifier($table);
        $columns = $baseline[$table]['columns'] ?? $db->query('SHOW COLUMNS FROM ' . $name)->fetchAll(PDO::FETCH_COLUMN);
        $key = $db->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY' ORDER BY SEQ_IN_INDEX");
        $key->execute([$table]); $order = $key->fetchAll(PDO::FETCH_COLUMN) ?: $columns;
        $fields = implode(',', array_map('historyMigrationIdentifier', $columns));
        $sort = implode(',', array_map('historyMigrationIdentifier', $order));
        $rows = $db->query('SELECT ' . $fields . ' FROM ' . $name . ' ORDER BY ' . $sort);
        $hash = hash_init('sha256'); $count = 0;
        while ($row = $rows->fetch(PDO::FETCH_NUM)) { hash_update($hash, json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE) . "\n"); $count++; }
        $result[$table] = ['columns' => $columns, 'rows' => $count, 'sha256' => hash_final($hash)];
    }
    return $result;
}

function historyMigrationMetadata(PDO $db): array
{
    $columns = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_requests' ORDER BY ORDINAL_POSITION")->fetchAll();
    $byName = []; foreach ($columns as $column) $byName[$column['COLUMN_NAME']] = $column;
    $indexes = $db->query("SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_requests' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll();
    $byIndex = []; foreach ($indexes as $index) { $byIndex[$index['INDEX_NAME']]['columns'][] = $index['COLUMN_NAME']; $byIndex[$index['INDEX_NAME']]['non_unique'] = (int)$index['NON_UNIQUE']; }
    $checks = $db->query("SELECT cc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME='borrowing_requests'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $foreign = $db->query("SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,rc.DELETE_RULE,rc.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND rc.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='borrowing_requests' AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION")->fetchAll();
    return ['columns' => $byName, 'indexes' => $byIndex, 'checks' => $checks, 'foreign' => $foreign];
}

function historyMigrationCompactCheck(string $clause): string
{
    $clause = strtolower(str_replace('`', '', $clause));
    // MySQL may emit explicit character-set introducers on string literals.
    $clause = preg_replace("/_(?:utf8mb4|utf8mb3|utf8|latin1|ascii)(?=')/", '', $clause);
    // MariaDB serializes DATE(column) as CAST(column AS DATE) in metadata.
    $clause = preg_replace('/cast\(\s*picked_up_at\s+as\s+date\s*\)/', 'date(picked_up_at)', $clause);
    return preg_replace('/[\s()]+/', '', $clause);
}
function historyMigrationChecks(): array
{
    return [
        'chk_request_pickup_evidence' => "(picked_up_at IS NULL AND picked_up_by_staff_id IS NULL AND status <> 'borrowed') OR (picked_up_at IS NOT NULL AND picked_up_by_staff_id IS NOT NULL AND status IN ('borrowed','returned'))",
        'chk_request_pickup_dates' => 'picked_up_at IS NULL OR (borrow_date IS NOT NULL AND expected_return_date IS NOT NULL AND DATE(picked_up_at) >= borrow_date AND DATE(picked_up_at) <= expected_return_date)',
    ];
}

function historyMigrationPreflight(PDO $db, array $metadata): void
{
    $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
    $minimum = str_contains($version, 'MariaDB') ? '10.2.1' : '8.0.16';
    if (version_compare(preg_replace('/[^0-9.].*/', '', $version), $minimum, '<')) throw new RuntimeException('Enforced CHECK constraints require MariaDB 10.2.1+ or MySQL 8.0.16+.');
    $caps = cartCapabilities($db);
    if (!$caps['checkout_available']) throw new RuntimeException('Apply the base and dated borrowing cart migrations first: ' . implode(', ', $caps['missing_requirements']));
    $status = $metadata['columns']['status'] ?? [];
    $beforeEnum = "enum('pending','approved','rejected','returned','cancelled')";
    $afterEnum = "enum('pending','approved','rejected','returned','cancelled','borrowed')";
    if (!in_array(strtolower($status['COLUMN_TYPE'] ?? ''), [$beforeEnum,$afterEnum], true) || ($status['IS_NULLABLE'] ?? '') !== 'NO' || trim((string)($status['COLUMN_DEFAULT'] ?? ''), "'") !== 'pending') throw new RuntimeException('Incompatible request status definition; no existing values will be replaced.');
    if (($metadata['indexes']['PRIMARY']['columns'] ?? []) !== ['request_id']) throw new RuntimeException('Requests must retain their existing request_id primary key.');
    foreach ([['user_id','users','user_id'],['equipment_id','equipment','equipment_id'],['checkout_id','borrowing_checkouts','checkout_id']] as $expected) {
        $found = false; foreach ($metadata['foreign'] as $fk) if ([$fk['COLUMN_NAME'],$fk['REFERENCED_TABLE_NAME'],$fk['REFERENCED_COLUMN_NAME']] === $expected) $found = true;
        if (!$found) throw new RuntimeException('Missing existing relationship: ' . $expected[0]);
    }
    if (!isset($metadata['checks']['chk_request_quantity_positive'])) throw new RuntimeException('Apply the request quantity constraint before migrating.');
    $userType = (string)$db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='user_id'")->fetchColumn();
    if (preg_replace('/\([0-9]+\)/', '', strtolower($userType)) !== 'int') throw new RuntimeException('users.user_id must be signed INT for this pickup actor migration.');
    foreach (['picked_up_at' => 'datetime','picked_up_by_staff_id' => 'int'] as $column => $type) {
        if (!isset($metadata['columns'][$column])) continue;
        $actual = $metadata['columns'][$column];
        if (preg_replace('/\([0-9]+\)/', '', strtolower($actual['COLUMN_TYPE'])) !== $type || $actual['IS_NULLABLE'] !== 'YES' || !in_array($actual['COLUMN_DEFAULT'], [null,'NULL'], true) || $actual['EXTRA'] !== '') throw new RuntimeException('Incompatible existing pickup column: ' . $column);
    }
    foreach (['idx_requests_pickup_staff' => ['picked_up_by_staff_id'], 'idx_requests_user_status_date' => ['user_id','status','request_date']] as $name => $columns) {
        if (isset($metadata['indexes'][$name]) && ($metadata['indexes'][$name]['columns'] !== $columns || $metadata['indexes'][$name]['non_unique'] !== 1)) throw new RuntimeException('Incompatible existing index: ' . $name);
    }
    foreach (historyMigrationChecks() as $name => $clause) if (isset($metadata['checks'][$name]) && historyMigrationCompactCheck($metadata['checks'][$name]) !== historyMigrationCompactCheck($clause)) throw new RuntimeException('Incompatible existing constraint: ' . $name);
    foreach ($metadata['foreign'] as $fk) if ($fk['CONSTRAINT_NAME'] === 'fk_request_pickup_staff' && [$fk['COLUMN_NAME'],$fk['REFERENCED_TABLE_NAME'],$fk['REFERENCED_COLUMN_NAME'],$fk['DELETE_RULE'],$fk['UPDATE_RULE']] !== ['picked_up_by_staff_id','users','user_id','RESTRICT','RESTRICT']) throw new RuntimeException('Incompatible pickup actor foreign key.');
    $checks = [
        'request quantities' => 'SELECT COUNT(*) FROM borrowing_requests WHERE requested_quantity < 1 OR requested_quantity IS NULL',
        'equipment quantities' => 'SELECT COUNT(*) FROM equipment WHERE total_quantity < 1 OR available_quantity < 0 OR available_quantity > total_quantity',
        'customer relationships' => 'SELECT COUNT(*) FROM borrowing_requests r LEFT JOIN users u ON u.user_id=r.user_id WHERE u.user_id IS NULL',
        'equipment relationships' => 'SELECT COUNT(*) FROM borrowing_requests r LEFT JOIN equipment e ON e.equipment_id=r.equipment_id WHERE e.equipment_id IS NULL',
        'checkout ownership' => 'SELECT COUNT(*) FROM borrowing_requests r LEFT JOIN borrowing_checkouts c ON c.checkout_id=r.checkout_id WHERE r.checkout_id IS NOT NULL AND (c.checkout_id IS NULL OR c.user_id <> r.user_id)',
        'active reservations' => "SELECT COUNT(*) FROM equipment e LEFT JOIN (SELECT equipment_id,SUM(requested_quantity) AS quantity FROM borrowing_requests WHERE status IN ('pending','approved','borrowed') GROUP BY equipment_id) active ON active.equipment_id=e.equipment_id WHERE e.available_quantity + COALESCE(active.quantity,0) > e.total_quantity",
    ];
    $hasPickup = isset($metadata['columns']['picked_up_at'],$metadata['columns']['picked_up_by_staff_id']);
    if (!$hasPickup) $checks['untracked borrowed records'] = "SELECT COUNT(*) FROM borrowing_requests WHERE status='borrowed'";
    else {
        foreach (historyMigrationChecks() as $label => $clause) $checks[$label] = 'SELECT COUNT(*) FROM borrowing_requests WHERE NOT (' . $clause . ')';
        $checks['pickup actor relationships'] = 'SELECT COUNT(*) FROM borrowing_requests r LEFT JOIN users u ON u.user_id=r.picked_up_by_staff_id WHERE r.picked_up_by_staff_id IS NOT NULL AND u.user_id IS NULL';
    }
    foreach ($checks as $label => $sql) if ((int)$db->query($sql)->fetchColumn() !== 0) throw new RuntimeException('Existing ' . $label . ' are inconsistent. Migration does not rewrite history or stock.');
}

function runMyBorrowingsMigration(PDO $db, bool $dryRun = false): array
{
    if ($db->inTransaction()) throw new RuntimeException('Schema migration cannot run inside an application transaction.');
    if ((int)$db->query("SELECT GET_LOCK('ebs_borrowing_cart_v3_migration',10)")->fetchColumn() !== 1) throw new RuntimeException('Another borrowing migration is running.');
    try {
        $metadata = historyMigrationMetadata($db);
        historyMigrationPreflight($db, $metadata);
        $before = historyMigrationSnapshot($db);
        $source = preg_replace('/^--(?! @when ).*$/m', '', file_get_contents(__DIR__ . '/migration_my_borrowings.sql'));
        $steps = [];
        foreach (explode(';', $source) as $statement) {
            $sql = trim(preg_replace('/--[^\r\n]*/', '', $statement)); if ($sql === '') continue;
            if (!preg_match('/-- @when (enum|column|index|constraint) borrowing_requests ([a-z_]+)/', $statement, $when)) throw new RuntimeException('Migration statement is missing its resume condition.');
            [, $kind, $name] = $when;
            $skip = match ($kind) {
                'enum' => str_contains($metadata['columns']['status']['COLUMN_TYPE'], "'$name'"),
                'column' => isset($metadata['columns'][$name]),
                'index' => isset($metadata['indexes'][$name]),
                'constraint' => isset($metadata['checks'][$name]) || in_array($name,array_column($metadata['foreign'],'CONSTRAINT_NAME'),true),
            };
            $steps[] = ['action' => $skip ? 'SKIP' : ($dryRun ? 'PLAN' : 'APPLIED'), 'name' => $name, 'sql' => preg_replace('/\s+/', ' ', $sql)];
            if (!$skip && !$dryRun) $db->exec($sql);
        }
        if (!$dryRun) {
            $actual = historyMigrationMetadata($db);
            historyMigrationPreflight($db, $actual);
            if (!borrowingHistorySchema($db)['pickup_storage_available']) throw new RuntimeException('Pickup schema verification failed.');
            foreach (['idx_requests_pickup_staff','idx_requests_user_status_date'] as $name) if (!isset($actual['indexes'][$name])) throw new RuntimeException('Missing migrated index: ' . $name);
            foreach (array_keys(historyMigrationChecks()) as $name) if (!isset($actual['checks'][$name])) throw new RuntimeException('Missing migrated constraint: ' . $name);
            if (!in_array('fk_request_pickup_staff',array_column($actual['foreign'],'CONSTRAINT_NAME'),true)) throw new RuntimeException('Missing pickup actor relationship.');
        }
        $after = historyMigrationSnapshot($db, $before);
        if ($before !== $after) throw new RuntimeException('Existing-row fingerprints changed during migration. Investigate concurrent application activity; no automatic data rewrite is attempted.');
        return ['dry_run' => $dryRun, 'steps' => $steps, 'preserved_tables' => count($before), 'preserved_rows' => array_sum(array_column($before,'rows')), 'fingerprints_verified' => true];
    } finally { $db->query("SELECT RELEASE_LOCK('ebs_borrowing_cart_v3_migration')"); }
}
