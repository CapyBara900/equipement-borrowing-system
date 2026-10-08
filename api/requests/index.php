<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/borrowing_submission.php';

const REQUEST_SELECT = '
    SELECT r.request_id, r.request_date, r.borrow_date, r.expected_return_date, r.requested_quantity, r.status, r.checkout_id,
           r.user_id, u.name AS user_name,
           r.equipment_id, e.equipment_name
    FROM borrowing_requests r
    JOIN users u ON u.user_id = r.user_id
    JOIN equipment e ON e.equipment_id = r.equipment_id
';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        try {
        $user = requireLogin();
        if (isset($_GET['id'])) {
            $stmt = $db->prepare(REQUEST_SELECT . ' WHERE r.request_id = :id');
            $stmt->execute(['id' => $_GET['id']]);
            $req = $stmt->fetch();
            if (!$req) {
                sendJson(404, ['success' => false, 'message' => 'Request not found.']);
            }
            if ($user['role'] === 'customer' && (int)$req['user_id'] !== (int)$user['user_id']) {
                sendJson(403, ['success' => false, 'message' => 'Not your request.']);
            }
            sendJson(200, ['success' => true, 'data' => $req]);
        }

        $sql = REQUEST_SELECT;
        $conditions = [];
        $params = [];
        if ($user['role'] === 'customer') {
            $conditions[] = 'r.user_id = :user_id';
            $params['user_id'] = $user['user_id'];
        }
        if (isset($_GET['checkout_id'])) {
            $conditions[] = 'r.checkout_id = :checkout_id';
            $params['checkout_id'] = borrowingPositiveInt($_GET['checkout_id'], 'checkout_id');
        }
        if (!empty($_GET['status'])) {
            $conditions[] = 'r.status = :status';
            $params['status'] = $_GET['status'];
        }
        if (!empty($_GET['date_from'])) {
            $conditions[] = 'r.request_date >= :date_from';
            $params['date_from'] = $_GET['date_from'];
        }
        if (!empty($_GET['date_to'])) {
            $conditions[] = 'r.request_date <= :date_to';
            $params['date_to'] = $_GET['date_to'];
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }
        $sql .= ' ORDER BY r.request_date DESC';

        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;
        $sql .= ' LIMIT :limit OFFSET :offset';

        $stmt = $db->prepare($sql);
        foreach ($params as $key => $val) {
            $stmt->bindValue(":$key", $val);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll(), 'page' => $page, 'limit' => $limit]);
        } catch (BorrowingFailure $e) {
            sendJson($e->httpStatus, ['success' => false, 'message' => $e->getMessage()]);
        } catch (PDOException $e) {
            sendJson(500, ['success' => false, 'message' => 'Could not load requests. Please try again.']);
        }
        break;

    case 'POST':
        $user = requireRole(['customer']);
        $body = getJsonBody();
        try {
            $equipmentId = borrowingPositiveInt($body['equipment_id'] ?? null, 'equipment_id');
            $quantity = borrowingPositiveInt($body['requested_quantity'] ?? null, 'requested_quantity');
            // Preserve same-day single-item requests for existing interfaces.
            [$pickup, $returned] = validateBorrowingDates($body['borrow_date'] ?? null, $body['expected_return_date'] ?? null);
            $db->beginTransaction();
            $requests = createBorrowingRequests($db, (int)$user['user_id'], [['equipment_id' => $equipmentId, 'requested_quantity' => $quantity]], $pickup, $returned);
            $db->commit();
            sendJson(201, ['success' => true, 'message' => 'Request submitted.', 'data' => ['request_id' => $requests[0]['request_id']]]);
        } catch (BorrowingFailure $e) {
            if ($db->inTransaction()) $db->rollBack();
            sendJson($e->httpStatus, ['success' => false, 'message' => $e->getMessage(), 'code' => $e->errorCode]);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            sendJson(500, ['success' => false, 'message' => 'Could not submit request. Please try again.']);
        }
        break;

    case 'PUT':
        // Staff/admin approve or reject a pending request.
        requireRole(['admin', 'staff']);
        $body = getJsonBody();
        $requestId = $body['request_id'] ?? null;
        $newStatus = $body['status'] ?? null;

        if (!$requestId || !in_array($newStatus, ['approved', 'rejected'], true)) {
            sendJson(400, ['success' => false, 'message' => 'request_id and a valid status (approved/rejected) are required.']);
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare('SELECT * FROM borrowing_requests WHERE request_id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $req = $stmt->fetch();

            if (!$req) {
                $db->rollBack();
                sendJson(404, ['success' => false, 'message' => 'Request not found.']);
            }
            if ($req['status'] !== 'pending') {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => 'Only pending requests can be approved or rejected.']);
            }

            $db->prepare('UPDATE borrowing_requests SET status = :status WHERE request_id = :id')
               ->execute(['status' => $newStatus, 'id' => $requestId]);

            if ($newStatus === 'rejected') {
                $release = $db->prepare(
                    'UPDATE equipment
                     SET available_quantity = LEAST(total_quantity, available_quantity + :quantity)
                     WHERE equipment_id = :id'
                );
                $release->execute(['quantity' => (int)$req['requested_quantity'], 'id' => $req['equipment_id']]);
            }

            notifyUser($db, (int)$req['user_id'], "Your borrowing request #{$requestId} was {$newStatus}.");

            $db->commit();
            sendJson(200, ['success' => true, 'message' => "Request $newStatus."]);
        } catch (PDOException $e) {
            $db->rollBack();
            sendJson(500, ['success' => false, 'message' => 'Could not update the request. Please try again.']);
        }
        break;

    case 'DELETE':
        // A customer may cancel their own request while it's still pending.
        $user = requireLogin();
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'id query param is required.']);
        }
        try {
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM borrowing_requests WHERE request_id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch();
        if (!$req) {
            $db->rollBack();
            sendJson(404, ['success' => false, 'message' => 'Request not found.']);
        }
        $isOwner = (int)$req['user_id'] === (int)$user['user_id'];
        if (!$isOwner && !in_array($user['role'], ['admin', 'staff'], true)) {
            $db->rollBack();
            sendJson(403, ['success' => false, 'message' => 'Not your request.']);
        }
        if ($req['status'] !== 'pending') {
            $db->rollBack();
            sendJson(409, ['success' => false, 'message' => 'Only pending requests can be cancelled.']);
        }
        $db->prepare("UPDATE borrowing_requests SET status = 'cancelled' WHERE request_id = :id")->execute(['id' => $id]);
        $db->prepare(
            'UPDATE equipment SET available_quantity = LEAST(total_quantity, available_quantity + :quantity)
             WHERE equipment_id = :id'
        )->execute(['quantity' => (int)$req['requested_quantity'], 'id' => $req['equipment_id']]);
        $db->commit();

        sendJson(200, ['success' => true, 'message' => 'Request cancelled.']);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            sendJson(500, ['success' => false, 'message' => 'Could not cancel the request. Please try again.']);
        }
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
