<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_history.php';

const RETURN_SELECT = '
    SELECT ret.return_id, ret.actual_return_date, ret.returned_quantity, ret.remarks,
           ret.processed_by_staff_id, staff.name AS processed_by_name,
           r.request_id, r.user_id, u.name AS user_name, u.email AS user_email,
           r.equipment_id, r.requested_quantity, e.equipment_name,
           r.checkout_id, r.borrow_date, r.expected_return_date, r.status
    FROM returns ret
    JOIN borrowing_requests r ON r.request_id = ret.request_id
    JOIN users u ON u.user_id = r.user_id
    JOIN equipment e ON e.equipment_id = r.equipment_id
    LEFT JOIN users staff ON staff.user_id = ret.processed_by_staff_id
';

try {
    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            borrowingAuthenticatedUser($db, ['admin', 'staff']);
            $rows = $db->query(RETURN_SELECT . ' ORDER BY ret.actual_return_date DESC, ret.return_id DESC')->fetchAll();
            foreach ($rows as &$row) {
                foreach (['return_id', 'request_id', 'user_id', 'equipment_id', 'requested_quantity', 'returned_quantity'] as $field) $row[$field] = (int)$row[$field];
                foreach (['checkout_id', 'processed_by_staff_id'] as $field) $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
                $row['transaction_reference'] = $row['checkout_id'] !== null ? 'Checkout #' . $row['checkout_id'] : 'Request #' . $row['request_id'];
            }
            unset($row);
            $result = ['success' => true, 'data' => $rows];
            $httpStatus = 200;
            break;

        case 'POST':
            borrowingAuthenticatedUser($db, BORROWING_MANAGEMENT_ROLES);
            $body = getJsonBody();
            $requestId = borrowingPositiveInt($body['request_id'] ?? null, 'request_id');
            // The shared transition requires Borrowed; approval is not physical pickup evidence.
            $result = changeBorrowingStatus($db, $requestId, 'returned', $body);
            $httpStatus = 201;
            break;

        default:
            throw new BorrowingFailure(405, 'Method not allowed.', 'METHOD_NOT_ALLOWED');
    }
} catch (BorrowingFailure $error) {
    sendJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage(), 'code' => $error->errorCode]);
} catch (PDOException $error) {
    [$status, $payload] = borrowingDatabaseFailure($error);
    if ($_SERVER['REQUEST_METHOD'] === 'GET') $payload = ['success' => false, 'message' => 'Could not load return history. Please try again.', 'code' => 'BORROWING_READ_FAILED'];
    sendJson($status, $payload);
}
sendJson($httpStatus, $result);
