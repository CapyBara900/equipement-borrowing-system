<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Run this migration from the command line.'); }
require __DIR__ . '/../config/database.php';
require __DIR__ . '/borrowing_management_migration.php';
try {
    foreach (array_slice($argv, 1) as $argument) if ($argument !== '--dry-run') throw new RuntimeException('Usage: php database/migrate_borrowing_management.php [--dry-run]');
    $db = (new Database())->getConnection();
    $result = runBorrowingManagementMigration($db, in_array('--dry-run', $argv, true), function(array $step): void { echo $step['action'] . ' ' . $step['name'] . "\n"; });
    echo json_encode(array_diff_key($result, ['steps' => true]), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
    echo $result['dry_run'] ? "Dry run complete; no schema/data changes.\n" : "Borrowing Management database migration verified.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\nCompleted DDL is resumable; no existing rows are rewritten.\n");
    exit(1);
}
