<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

const REQUEST_SELECT = '
    SELECT r.request_id, r.request_date, r.borrow_date, r.expected_return_date, r.status,
           r.user_id, u.name AS user_name,
           r.equipment_id, e.equipment_name
    FROM borrowing_requests r
    JOIN users u ON u.user_id = r.user_id
    JOIN equipment e ON e.equipment_id = r.equipment_id
';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
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
        break;

    case 'POST':
        $user = requireRole(['customer']);
        $body = getJsonBody();
        $equipmentId = $body['equipment_id'] ?? null;
        $borrowDate  = $body['borrow_date'] ?? null;
        $expectedReturnDate = $body['expected_return_date'] ?? null;

        if (!$equipmentId || !$borrowDate || !$expectedReturnDate) {
            sendJson(400, ['success' => false, 'message' => 'equipment_id, borrow_date, and expected_return_date are required.']);
        }
        $borrowDateObj = DateTime::createFromFormat('!Y-m-d', $borrowDate);
        $returnDateObj = DateTime::createFromFormat('!Y-m-d', $expectedReturnDate);
        $today = new DateTime('today');
        if (!$borrowDateObj || $borrowDateObj->format('Y-m-d') !== $borrowDate ||
            !$returnDateObj || $returnDateObj->format('Y-m-d') !== $expectedReturnDate) {
            sendJson(400, ['success' => false, 'message' => 'Please enter valid dates.']);
        }
        if ($borrowDateObj < $today) {
            sendJson(400, ['success' => false, 'message' => 'Pick up date cannot be in the past.']);
        }
        if ($returnDateObj < $borrowDateObj) {
            sendJson(400, ['success' => false, 'message' => 'Return date cannot be before the pick up date.']);
        }

        try {
            $db->beginTransaction();

            // Lock the equipment row so two people can't grab it at once.
            $check = $db->prepare('SELECT status FROM equipment WHERE equipment_id = :id FOR UPDATE');
            $check->execute(['id' => $equipmentId]);
            $equipment = $check->fetch();

            if (!$equipment) {
                $db->rollBack();
                sendJson(404, ['success' => false, 'message' => 'Equipment not found.']);
            }
            if ($equipment['status'] !== 'available') {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => 'That equipment is not available right now.']);
            }

            $stmt = $db->prepare(
                'INSERT INTO borrowing_requests (user_id, equipment_id, borrow_date, expected_return_date, status)
                 VALUES (:user_id, :equipment_id, :borrow_date, :expected_return_date, :status)'
            );
            $stmt->execute([
                'user_id'              => $user['user_id'],
                'equipment_id'         => $equipmentId,
                'borrow_date'          => $borrowDate,
                'expected_return_date' => $expectedReturnDate,
                'status'               => 'pending',
            ]);
            $newRequestId = (int)$db->lastInsertId();

            $db->prepare('UPDATE equipment SET status = "pending" WHERE equipment_id = :id')
               ->execute(['id' => $equipmentId]);

            $db->commit();
            sendJson(201, ['success' => true, 'message' => 'Request submitted.', 'data' => ['request_id' => $newRequestId]]);
        } catch (PDOException $e) {
            $db->rollBack();
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

            if ($newStatus === 'approved') {
                $equipmentCheck = $db->prepare('SELECT status FROM equipment WHERE equipment_id = :id FOR UPDATE');
                $equipmentCheck->execute(['id' => $req['equipment_id']]);
                $equipment = $equipmentCheck->fetch();
                if (!$equipment || $equipment['status'] !== 'pending') {
                    $db->rollBack();
                    sendJson(409, ['success' => false, 'message' => 'This equipment is no longer available. Review this request before approving it.']);
                }
            }

            $db->prepare('UPDATE borrowing_requests SET status = :status WHERE request_id = :id')
               ->execute(['status' => $newStatus, 'id' => $requestId]);

            if ($newStatus === 'approved') {
                $db->prepare('UPDATE equipment SET status = :status WHERE equipment_id = :id')
                   ->execute(['status' => 'borrowed', 'id' => $req['equipment_id']]);
            } else if ($newStatus === 'rejected') {
                $db->prepare('UPDATE equipment SET status = "available" WHERE equipment_id = :id')
                   ->execute(['id' => $req['equipment_id']]);
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
        $stmt = $db->prepare('SELECT * FROM borrowing_requests WHERE request_id = :id');
        $stmt->execute(['id' => $id]);
        $req = $stmt->fetch();
        if (!$req) {
            sendJson(404, ['success' => false, 'message' => 'Request not found.']);
        }
        $isOwner = (int)$req['user_id'] === (int)$user['user_id'];
        if (!$isOwner && !in_array($user['role'], ['admin', 'staff'], true)) {
            sendJson(403, ['success' => false, 'message' => 'Not your request.']);
        }
        if ($req['status'] !== 'pending') {
            sendJson(409, ['success' => false, 'message' => 'Only pending requests can be cancelled.']);
        }
        $db->prepare('DELETE FROM borrowing_requests WHERE request_id = :id')->execute(['id' => $id]);
        
        $db->prepare('UPDATE equipment SET status = "available" WHERE equipment_id = :id')
           ->execute(['id' => $req['equipment_id']]);

        sendJson(200, ['success' => true, 'message' => 'Request cancelled.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
