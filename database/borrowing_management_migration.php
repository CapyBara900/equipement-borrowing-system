<?php
require_once __DIR__ . '/my_borrowings_migration.php';

/** Inventory object definitions; never assume that a matching name means compatibility. */
function managementMigrationMetadata(PDO $db): array
{
    $tables = $db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $result = [];
    foreach ($tables as $table => $engine) {
        $entry = ['engine' => $engine, 'columns' => [], 'indexes' => [], 'checks' => [], 'foreign' => []];
        $query = $db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $query->execute([$table]);
        foreach ($query as $row) $entry['columns'][$row['COLUMN_NAME']] = $row;
        $query = $db->prepare('SELECT INDEX_NAME,COLUMN_NAME,NON_UNIQUE,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
        $query->execute([$table]);
        foreach ($query as $row) {
            $entry['indexes'][$row['INDEX_NAME']]['columns'][] = $row['COLUMN_NAME'];
            $entry['indexes'][$row['INDEX_NAME']]['non_unique'] = (int)$row['NON_UNIQUE'];
            $entry['indexes'][$row['INDEX_NAME']]['prefixes'][] = $row['SUB_PART'];
        }
        $query = $db->prepare('SELECT cc.CONSTRAINT_NAME,cc.CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS cc JOIN information_schema.TABLE_CONSTRAINTS tc ON tc.CONSTRAINT_SCHEMA=cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME=cc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA=DATABASE() AND tc.TABLE_NAME=?');
        $query->execute([$table]); $entry['checks'] = $query->fetchAll(PDO::FETCH_KEY_PAIR);
        $query = $db->prepare('SELECT k.CONSTRAINT_NAME,k.COLUMN_NAME,k.REFERENCED_TABLE_NAME,k.REFERENCED_COLUMN_NAME,rc.DELETE_RULE,rc.UPDATE_RULE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS rc ON rc.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND rc.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND rc.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME=? AND k.REFERENCED_TABLE_NAME IS NOT NULL ORDER BY k.CONSTRAINT_NAME,k.ORDINAL_POSITION');
        $query->execute([$table]);
        foreach ($query as $row) {
            $name = $row['CONSTRAINT_NAME'];
            $entry['foreign'][$name]['columns'][] = $row['COLUMN_NAME'];
            $entry['foreign'][$name]['references'][] = $row['REFERENCED_COLUMN_NAME'];
            $entry['foreign'][$name]['table'] = $row['REFERENCED_TABLE_NAME'];
            $entry['foreign'][$name]['delete'] = $row['DELETE_RULE'];
            $entry['foreign'][$name]['update'] = $row['UPDATE_RULE'];
        }
        $result[$table] = $entry;
    }
    return $result;
}

function managementMigrationIndex(array $metadata, string $table, string $name, array $columns, bool $unique): bool
{
    $actual = $metadata[$table]['indexes'][$name] ?? null;
    if ($actual === null) return false;
    if ($actual['columns'] !== $columns || $actual['non_unique'] !== ($unique ? 0 : 1) || array_filter($actual['prefixes'], fn($value) => $value !== null)) throw new RuntimeException("Incompatible index $table.$name; no objects were replaced.");
    return true;
}

function managementMigrationAuditCheck(): string
{
    return "(from_status='pending' AND to_status IN ('approved','rejected','cancelled')) OR (from_status='approved' AND to_status='borrowed') OR (from_status='borrowed' AND to_status='returned')";
}

function managementMigrationVerifyAudit(array $metadata): void
{
    $table = $metadata['borrowing_status_history'] ?? null;
    if ($table === null) return;
    if ($table['engine'] !== 'InnoDB') throw new RuntimeException('Existing audit storage must use InnoDB.');
    $types = ['history_id' => 'int', 'request_id' => 'int', 'changed_by_user_id' => 'int', 'changed_at' => 'datetime', 'from_status' => "enum('pending','approved','borrowed','returned','rejected','cancelled')", 'to_status' => "enum('pending','approved','borrowed','returned','rejected','cancelled')"];
    foreach ($types as $name => $type) {
        $column = $table['columns'][$name] ?? [];
        $actual = strtolower($column['COLUMN_TYPE'] ?? '');
        if ($type === 'int') $actual = preg_replace('/\([0-9]+\)/', '', $actual);
        if ($actual !== $type || ($column['IS_NULLABLE'] ?? '') !== 'NO' || ($column['EXTRA'] ?? '') !== ($name === 'history_id' ? 'auto_increment' : '')) throw new RuntimeException('Incompatible existing audit column: ' . $name);
    }
    foreach ($table['columns'] as $name => $column) if (!isset($types[$name]) && $column['IS_NULLABLE'] === 'NO' && $column['COLUMN_DEFAULT'] === null && $column['EXTRA'] === '') throw new RuntimeException('Additional audit column requires a value the API cannot supply: ' . $name);
    foreach (['PRIMARY' => [['history_id'], true], 'uq_borrowing_history_request_status' => [['request_id','to_status'], true], 'idx_borrowing_history_request_time' => [['request_id','changed_at','history_id'], false], 'idx_borrowing_history_actor_time' => [['changed_by_user_id','changed_at'], false]] as $name => [$columns, $unique]) {
        if (!managementMigrationIndex($metadata, 'borrowing_status_history', $name, $columns, $unique)) throw new RuntimeException('Incomplete existing audit index: ' . $name);
    }
    foreach (['fk_borrowing_history_request' => ['request_id','borrowing_requests','request_id'], 'fk_borrowing_history_actor' => ['changed_by_user_id','users','user_id']] as $name => [$column, $parent, $key]) {
        $expected = ['columns' => [$column], 'references' => [$key], 'table' => $parent, 'delete' => 'RESTRICT', 'update' => 'RESTRICT'];
        if (($table['foreign'][$name] ?? null) !== $expected) throw new RuntimeException('Incompatible existing audit relationship: ' . $name);
    }
    if (historyMigrationCompactCheck($table['checks']['chk_borrowing_history_transition'] ?? '') !== historyMigrationCompactCheck(managementMigrationAuditCheck())) throw new RuntimeException('Existing audit transition constraint is incompatible or missing.');
}

function managementMigrationPreflight(PDO $db, array $metadata): void
{
    historyMigrationPreflight($db, historyMigrationMetadata($db));
    if (!borrowingHistorySchema($db)['pickup_storage_available']) throw new RuntimeException('Apply the My Borrowings pickup migration first.');
    if (!(int)$db->query('SELECT @@SESSION.foreign_key_checks')->fetchColumn()) throw new RuntimeException('Foreign key enforcement must be enabled.');
    $version = (string)$db->query('SELECT VERSION()')->fetchColumn();
    if (str_contains($version, 'MariaDB') && !(int)$db->query('SELECT @@SESSION.check_constraint_checks')->fetchColumn()) throw new RuntimeException('CHECK enforcement must be enabled.');
    if (!str_contains($version, 'MariaDB') && (int)$db->query("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND CONSTRAINT_TYPE='CHECK' AND ENFORCED='NO'")->fetchColumn()) throw new RuntimeException('Existing CHECK constraints must be enforced.');
    foreach (['roles','users','equipment','borrowing_requests','borrowing_checkouts','borrowing_cart_items','returns','equipment_condition_reports','notifications'] as $table) if (($metadata[$table]['engine'] ?? null) !== 'InnoDB') throw new RuntimeException("$table must exist and use InnoDB.");
    foreach (['users' => 'user_id', 'equipment' => 'equipment_id', 'borrowing_requests' => 'request_id', 'borrowing_checkouts' => 'checkout_id'] as $table => $key) {
        $column = $metadata[$table]['columns'][$key] ?? [];
        if (preg_replace('/\([0-9]+\)/', '', strtolower($column['COLUMN_TYPE'] ?? '')) !== 'int' || ($column['IS_NULLABLE'] ?? '') !== 'NO' || !managementMigrationIndex($metadata, $table, 'PRIMARY', [$key], true)) throw new RuntimeException("Incompatible primary identifier $table.$key.");
    }
    foreach (['borrowing_requests' => ['user_id' => 'NO','equipment_id' => 'NO','checkout_id' => 'YES'], 'borrowing_checkouts' => ['user_id' => 'NO'], 'returns' => ['request_id' => 'NO','processed_by_staff_id' => 'YES'], 'equipment_condition_reports' => ['equipment_id' => 'NO','reported_by_user_id' => 'YES']] as $table => $columns) foreach ($columns as $name => $nullable) {
        $column = $metadata[$table]['columns'][$name] ?? [];
        if (preg_replace('/\([0-9]+\)/', '', strtolower($column['COLUMN_TYPE'] ?? '')) !== 'int' || ($column['IS_NULLABLE'] ?? '') !== $nullable) throw new RuntimeException("Incompatible relationship type/nullability $table.$name.");
    }
    foreach (['equipment' => ['total_quantity','available_quantity'], 'borrowing_requests' => ['requested_quantity'], 'returns' => ['returned_quantity']] as $table => $columns) foreach ($columns as $name) {
        $column = $metadata[$table]['columns'][$name] ?? [];
        if (preg_replace('/\([0-9]+\)/', '', strtolower($column['COLUMN_TYPE'] ?? '')) !== 'int unsigned' || ($column['IS_NULLABLE'] ?? '') !== 'NO') throw new RuntimeException("Incompatible quantity type/nullability $table.$name.");
    }
    $uniqueReturn = false;
    foreach ($metadata['returns']['indexes'] as $name => $index) if ($index['columns'] === ['request_id'] && $index['non_unique'] === 0) $uniqueReturn = managementMigrationIndex($metadata, 'returns', $name, ['request_id'], true);
    if (!$uniqueReturn) throw new RuntimeException('Existing return-per-request unique key must be preserved.');
    $checks = [
        'request date order' => 'SELECT COUNT(*) FROM borrowing_requests WHERE borrow_date IS NOT NULL AND expected_return_date IS NOT NULL AND expected_return_date < borrow_date',
        'checkout customer relationships' => 'SELECT COUNT(*) FROM borrowing_checkouts c LEFT JOIN users u ON u.user_id=c.user_id WHERE u.user_id IS NULL',
        'return relationships and quantities' => 'SELECT COUNT(*) FROM returns t LEFT JOIN borrowing_requests r ON r.request_id=t.request_id WHERE r.request_id IS NULL OR t.returned_quantity < 1 OR t.returned_quantity > r.requested_quantity',
        'return administrator relationships' => 'SELECT COUNT(*) FROM returns t LEFT JOIN users u ON u.user_id=t.processed_by_staff_id WHERE t.processed_by_staff_id IS NOT NULL AND u.user_id IS NULL',
        'condition equipment relationships' => 'SELECT COUNT(*) FROM equipment_condition_reports t LEFT JOIN equipment e ON e.equipment_id=t.equipment_id WHERE e.equipment_id IS NULL',
        'condition actor relationships' => 'SELECT COUNT(*) FROM equipment_condition_reports t LEFT JOIN users u ON u.user_id=t.reported_by_user_id WHERE t.reported_by_user_id IS NOT NULL AND u.user_id IS NULL',
        'request statuses' => "SELECT COUNT(*) FROM borrowing_requests WHERE status NOT IN ('pending','approved','borrowed','returned','rejected','cancelled') OR status IS NULL",
    ];
    managementMigrationVerifyAudit($metadata);
    if (isset($metadata['borrowing_status_history'])) {
        $checks['audit request relationships'] = 'SELECT COUNT(*) FROM borrowing_status_history h LEFT JOIN borrowing_requests r ON r.request_id=h.request_id WHERE r.request_id IS NULL';
        $checks['audit actor relationships'] = 'SELECT COUNT(*) FROM borrowing_status_history h LEFT JOIN users u ON u.user_id=h.changed_by_user_id WHERE u.user_id IS NULL';
        $checks['audit transitions'] = 'SELECT COUNT(*) FROM borrowing_status_history WHERE NOT (' . managementMigrationAuditCheck() . ')';
    }
    foreach ($checks as $label => $sql) if ((int)$db->query($sql)->fetchColumn()) throw new RuntimeException("Existing $label are inconsistent. Resolve explicitly; migration does not repair data or recalculate stock.");
}

/** Return the validated and resumable execution plan, including renamed legacy FKs. */
function managementMigrationPlan(array $metadata): array
{
    $steps = [];
    $source = preg_replace('/^--(?! @when ).*$/m', '', file_get_contents(__DIR__ . '/migration_borrowing_management.sql'));
    foreach (explode(';', $source) as $statement) {
        $sql = trim(preg_replace('/--[^\r\n]*/', '', $statement));
        if ($sql === '') continue;
        if (!preg_match('/-- @when (table|index|check|foreign) ([a-z_]+) ([a-z_]+)/', $statement, $match)) throw new RuntimeException('Migration step has no resume condition.');
        [, $kind, $table, $name] = $match; $skip = false;
        if ($kind === 'table') $skip = isset($metadata[$table]);
        elseif ($kind === 'index') {
            if (!preg_match('/ADD (UNIQUE KEY|INDEX) [a-z_]+ \(([^)]+)\)/', $sql, $index)) throw new RuntimeException('Invalid migration index.');
            $skip = managementMigrationIndex($metadata, $table, $name, array_map('trim', explode(',', $index[2])), $index[1] === 'UNIQUE KEY');
        } elseif ($kind === 'check') {
            if (!preg_match('/CHECK \((.*)\);?$/s', $sql, $check)) throw new RuntimeException('Invalid migration check.');
            if (isset($metadata[$table]['checks'][$name])) {
                if (historyMigrationCompactCheck($metadata[$table]['checks'][$name]) !== historyMigrationCompactCheck($check[1])) throw new RuntimeException("Incompatible constraint $table.$name.");
                $skip = true;
            }
        } else {
            if (!preg_match('/FOREIGN KEY \(([^)]+)\) REFERENCES ([a-z_]+)\(([^)]+)\)/', $sql, $fk)) throw new RuntimeException('Invalid migration relationship.');
            $columns = array_map('trim', explode(',', $fk[1])); $refs = array_map('trim', explode(',', $fk[3]));
            $actualName = null;
            foreach ($metadata[$table]['foreign'] as $existingName => $existing) {
                if ($existingName === $name && $existing['columns'] !== $columns) throw new RuntimeException("Conflicting constraint $table.$name.");
                if ($existing['columns'] !== $columns) continue;
                if ($existing['table'] !== $fk[2] || $existing['references'] !== $refs || $actualName !== null) throw new RuntimeException("Incompatible relationship on $table." . implode(',', $columns));
                $actualName = $existingName;
                $skip = in_array($existing['delete'], ['RESTRICT','NO ACTION'], true) && in_array($existing['update'], ['RESTRICT','NO ACTION'], true);
            }
            $sql = preg_replace('/DROP FOREIGN KEY [a-z_]+, /', '', $sql);
            if (!$skip && $actualName !== null) $sql = preg_replace_callback('/ADD CONSTRAINT/', fn() => 'DROP FOREIGN KEY ' . historyMigrationIdentifier($actualName) . ', ADD CONSTRAINT', $sql, 1);
        }
        $steps[] = ['skip' => $skip, 'name' => "$table.$name", 'sql' => $sql];
    }
    return $steps;
}

/** Consistent local recovery export; no credentials or row contents appear in console output. */
function managementMigrationBackup(PDO $db, array $snapshot): string
{
    $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ebs-before-borrowing-management-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '.sql';
    $file = fopen($path, 'xb');
    if ($file === false) throw new RuntimeException('Cannot create recovery export; no schema changes attempted.');
    chmod($path, 0600);
    $write = function(string $text) use ($file): void { if (fwrite($file, $text) !== strlen($text)) throw new RuntimeException('Recovery export write failed; no schema changes attempted.'); };
    try {
        $write("-- Recovery export: import into a separate empty database. Contains private application data.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
        foreach ($snapshot as $table => $entry) {
            $identifier = historyMigrationIdentifier($table);
            $write($db->query('SHOW CREATE TABLE ' . $identifier)->fetch(PDO::FETCH_NUM)[1] . ";\n");
            $columns = implode(',', array_map('historyMigrationIdentifier', $entry['columns']));
            $query = $db->query('SELECT ' . $columns . ' FROM ' . $identifier);
            while ($row = $query->fetch(PDO::FETCH_NUM)) $write('INSERT INTO ' . $identifier . ' (' . $columns . ') VALUES (' . implode(',', array_map(fn($value) => $value === null ? 'NULL' : $db->quote((string)$value), $row)) . ");\n");
        }
        $write("SET FOREIGN_KEY_CHECKS=1;\n");
        if (!fflush($file)) throw new RuntimeException('Cannot flush recovery export.');
    } finally { fclose($file); }
    return $path;
}

function runBorrowingManagementMigration(PDO $db, bool $dryRun = false, ?callable $progress = null): array
{
    if ($db->inTransaction()) throw new RuntimeException('DDL migration cannot run inside an application transaction.');
    if ((int)$db->query("SELECT GET_LOCK('ebs_borrowing_cart_v3_migration',10)")->fetchColumn() !== 1) throw new RuntimeException('Another borrowing migration is running.');
    try {
        $metadata = managementMigrationMetadata($db);
        managementMigrationPreflight($db, $metadata);
        $plan = managementMigrationPlan($metadata); // Validate every step before the first DDL.
        $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->beginTransaction();
        try {
            $before = historyMigrationSnapshot($db);
            $backup = !$dryRun && count(array_filter($plan, fn($step) => !$step['skip'])) ? managementMigrationBackup($db, $before) : null;
            $db->commit();
            if ($backup !== null && $progress !== null) $progress(['action' => 'RECOVERY_EXPORT', 'name' => $backup]);
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
        $steps = [];
        foreach ($plan as $step) {
            if (!$step['skip'] && !$dryRun) $db->exec($step['sql']);
            $completed = ['action' => $step['skip'] ? 'SKIP' : ($dryRun ? 'PLAN' : 'APPLIED'), 'name' => $step['name']];
            $steps[] = $completed;
            if ($progress !== null) $progress($completed);
        }
        if (!$dryRun) {
            $actual = managementMigrationMetadata($db);
            managementMigrationPreflight($db, $actual);
            if (array_filter(managementMigrationPlan($actual), fn($step) => !$step['skip'])) throw new RuntimeException('Migration postflight found incomplete objects; rerun after investigation.');
            if (!borrowingHistorySchema($db)['management_available']) throw new RuntimeException('Existing backend does not report complete management storage.');
        }
        $after = historyMigrationSnapshot($db, $before);
        if ($before !== $after) throw new RuntimeException('Existing-row fingerprints changed. Investigate concurrent writes; migration never rewrites rows. DDL commits implicitly and completed steps are resumable.');
        return ['dry_run' => $dryRun, 'steps' => $steps, 'backup_path' => $backup, 'preserved_tables' => count($before), 'preserved_rows' => array_sum(array_column($before, 'rows')), 'fingerprints_verified' => true, 'table_fingerprints' => $before, 'management_available' => borrowingHistorySchema($db)['management_available']];
    } finally { $db->query("SELECT RELEASE_LOCK('ebs_borrowing_cart_v3_migration')"); }
}
