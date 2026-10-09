<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_history.php';

$method = $_SERVER['REQUEST_METHOD'];
$ownsTransaction = false;
try {
    switch ($method) {
        case 'GET':
            $user = borrowingAuthenticatedUser($db, ['customer', 'admin', 'staff']);
            $options = normalizeBorrowingHistoryQuery($_GET);
            $schema = borrowingHistorySchema($db);
            if ($options['capabilities']) {
                $result = ['success' => true, 'data' => [
                    'statuses' => BORROWING_HISTORY_STATUSES,
                    'pickup_available' => $schema['pickup_available'],
                    'pickup_storage_available' => $schema['pickup_storage_available'],
                    'management_available' => $schema['management_available'],
                    'audit_available' => $schema['audit_available'],
                    'missing_requirements' => $schema['missing_requirements'],
                    'transitions' => ['pending' => ['approved', 'rejected', 'cancelled'], 'approved' => ['borrowed'], 'borrowed' => ['returned'], 'returned' => [], 'rejected' => [], 'cancelled' => []],
                    'management_roles' => BORROWING_MANAGEMENT_ROLES,
                ]];
            } else {
                $result = readBorrowingHistory($db, $user, $options, $schema);
            }
            $httpStatus = 200;
            break;

        case 'POST':
            $user = borrowingAuthenticatedUser($db, ['customer']);
            $body = getJsonBody();
            $equipmentId = borrowingPositiveInt($body['equipment_id'] ?? null, 'equipment_id');
            $quantity = borrowingPositiveInt($body['requested_quantity'] ?? null, 'requested_quantity');
            [$pickup, $returned] = validateBorrowingDates($body['borrow_date'] ?? null, $body['expected_return_date'] ?? null);
            $db->beginTransaction();
            $ownsTransaction = true;
            $requests = createBorrowingRequests($db, (int)$user['user_id'], [['equipment_id' => $equipmentId, 'requested_quantity' => $quantity]], $pickup, $returned);
            $db->commit();
            $ownsTransaction = false;
            $result = ['success' => true, 'message' => 'Request submitted.', 'data' => ['request_id' => $requests[0]['request_id']]];
            $httpStatus = 201;
            break;

        case 'PUT':
            borrowingAuthenticatedUser($db, BORROWING_MANAGEMENT_ROLES);
            $body = getJsonBody();
            $requestId = borrowingPositiveInt($body['request_id'] ?? null, 'request_id');
            $status = borrowingMutationStatus($body);
            $result = changeBorrowingStatus($db, $requestId, $status, $body);
            $httpStatus = 200;
            break;

        case 'DELETE':
            borrowingAuthenticatedUser($db, ['customer', 'admin']);
            $id = borrowingPositiveInt($_GET['id'] ?? null, 'id');
            // Cancellation preserves the request and releases its pending reservation once.
            $result = changeBorrowingStatus($db, $id, 'cancelled', [], true);
            $httpStatus = 200;
            break;

        default:
            throw new BorrowingFailure(405, 'Method not allowed.', 'METHOD_NOT_ALLOWED');
    }
} catch (BorrowingFailure $error) {
    if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
    sendJson($error->httpStatus, ['success' => false, 'message' => $error->getMessage(), 'code' => $error->errorCode]);
} catch (PDOException $error) {
    if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
    [$status, $payload] = borrowingDatabaseFailure($error);
    if ($method === 'GET') $payload = ['success' => false, 'message' => 'Could not load borrowing history. Please try again.', 'code' => 'BORROWING_READ_FAILED'];
    sendJson($status, $payload);
}
sendJson($httpStatus, $result);
