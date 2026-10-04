<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireRole(['admin']);

$stmt = $db->query('SELECT role_id, role_name FROM roles ORDER BY role_id');
sendJson(200, ['success' => true, 'data' => $stmt->fetchAll()]);
