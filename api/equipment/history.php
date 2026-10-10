<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_management.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') sendJson(405, ['success'=>false, 'message'=>'Method not allowed.']);
try {
    $user = borrowingAuthenticatedUser($db, ['admin','customer']);
    $id = borrowingPositiveInt($_GET['equipment_id'] ?? null, 'equipment_id');
    $exists = $db->prepare('SELECT equipment_id FROM equipment WHERE equipment_id = ?');
    $exists->execute([$id]);
    if (!$exists->fetchColumn()) throw new BorrowingFailure(404, 'Equipment not found.');
    $customer = $user['role'] === 'customer';
    $scope = $customer ? ' AND r.user_id = ?' : '';
    $history = $db->prepare("SELECT r.request_id, r.status, r.request_date, r.borrow_date, r.expected_return_date,
        r.requested_quantity, ret.actual_return_date" . ($customer ? '' : ', u.name AS user_name') . "
        FROM borrowing_requests r LEFT JOIN returns ret ON ret.request_id = r.request_id
        JOIN users u ON u.user_id = r.user_id WHERE r.equipment_id = ? $scope
        ORDER BY r.request_date DESC, r.request_id DESC LIMIT 10");
    $history->execute($customer ? [$id, $user['user_id']] : [$id]);
    $conditions = [];
    if (!$customer) {
        $reports = $db->prepare('SELECT cr.condition_status, cr.notes, cr.logged_at, u.name AS reported_by_name
            FROM equipment_condition_reports cr LEFT JOIN users u ON u.user_id = cr.reported_by_user_id
            WHERE cr.equipment_id = ? ORDER BY cr.logged_at DESC, cr.report_id DESC LIMIT 10');
        $reports->execute([$id]); $conditions = $reports->fetchAll();
    }
    sendJson(200, ['success'=>true, 'data'=>['scope'=>$customer ? 'own' : 'all', 'borrowings'=>$history->fetchAll(), 'conditions'=>$conditions, 'limit'=>10]]);
} catch (BorrowingFailure $error) {
    sendJson($error->httpStatus, ['success'=>false, 'message'=>$error->getMessage()]);
} catch (PDOException $error) {
    sendJson(500, ['success'=>false, 'message'=>'Could not load item history. Please try again.']);
}
