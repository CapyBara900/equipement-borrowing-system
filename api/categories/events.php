<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/category_management.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
requireLogin();
// Streaming must never hold the PHP session lock and block normal API requests.
session_write_close();
header('Content-Type: text/event-stream; charset=UTF-8');
header('X-Accel-Buffering: no');
while (ob_get_level() > 0) ob_end_flush();
set_time_limit(35);
echo "retry: 1000\n\n";
flush();
$previous = '';
$until = microtime(true) + 25;
do {
    try {
        $json = json_encode(allCategories($db), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $revision = hash('sha256', $json);
        if ($revision !== $previous) {
            echo "event: categories\ndata: $json\n\n";
            $previous = $revision;
        } else echo ": keepalive\n\n";
        flush();
    } catch (Throwable $e) {
        echo "event: unavailable\ndata: {}\n\n";
        flush();
        break;
    }
    if (connection_aborted()) break;
    usleep(1000000);
} while (microtime(true) < $until);
