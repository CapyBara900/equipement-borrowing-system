<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/dashboard.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
try {
    $user = borrowingAuthenticatedUser($db, ['customer', 'staff', 'admin']);
    $data = readDashboard($db, $user, dashboardOptions($_GET));
    sendJson(200, ['success' => true, 'data' => $data]);
} catch (BorrowingFailure $error) {
    sendJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage()]);
} catch (PDOException $error) {
    sendJson(500, ['success' => false, 'message' => 'Could not load your overview. Please try again.']);
}
