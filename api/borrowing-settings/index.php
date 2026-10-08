<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_dates.php';
requireLogin();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
sendJson(200, ['success' => true, 'data' => pickupDateWindow()]);
