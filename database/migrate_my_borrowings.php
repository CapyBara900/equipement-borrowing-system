<?php
// CLI only. DDL commits implicitly; reruns validate and resume completed steps.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration from the command line.'); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/my_borrowings_migration.php';
try {
    $result = runMyBorrowingsMigration((new Database())->getConnection(), in_array('--dry-run', $argv, true));
    foreach ($result['steps'] as $step) echo $step['action'] . ' ' . $step['name'] . ($step['action'] === 'PLAN' ? ': ' . $step['sql'] : '') . "\n";
    echo ($result['dry_run'] ? 'Dry run complete. ' : 'My Borrowings migration complete. ') . 'Original row fingerprints verified across ' . $result['preserved_tables'] . ' tables (' . $result['preserved_rows'] . " rows).\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'Migration stopped: ' . $error->getMessage() . "\n");
    exit(1);
}
