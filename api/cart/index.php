<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_cart.php';
$user = requireRole(['customer']);
$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'PUT', 'DELETE'], true)) sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
try {
    if ($method === 'GET' && isset($_GET['capabilities'])) $rows = cartCapabilities($db);
    else {
        requireCartSchema($db);
        $rows = $method === 'GET' ? readBorrowingCart($db, (int)$user['user_id']) : mutateBorrowingCart($db, (int)$user['user_id'], $method, getJsonBody());
    }
} catch (BorrowingFailure $e) {
    sendJson($e->httpStatus, ['success' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    sendJson(500, ['success' => false, 'message' => 'Could not update or load your cart. Please try again.']);
}

sendJson(200, ['success' => true, 'data' => $rows]);
