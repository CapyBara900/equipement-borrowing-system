<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_cart.php';
$user = requireRole(['customer']);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
try {
    requireCartSchema($db);
    $rows = importBorrowingCart($db, (int)$user['user_id'], getJsonBody());
} catch (BorrowingFailure $e) {
    sendJson($e->httpStatus, ['success' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode]);
} catch (Throwable $e) {
    sendJson(500, ['success' => false, 'message' => 'Browser cart import was not confirmed. Your previous browser cart is preserved.']);
}
sendJson(200, ['success' => true, 'data' => $rows]);
