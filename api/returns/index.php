<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

const RETURN_SELECT = '
    SELECT ret.return_id, ret.actual_return_date, ret.remarks,
           ret.processed_by_staff_id, staff.name AS processed_by_name,
           r.request_id, r.user_id, u.name AS user_name,
           r.equipment_id, r.requested_quantity, e.equipment_name
    FROM returns ret
    JOIN borrowing_requests r ON r.request_id = ret.request_id
    JOIN users u ON u.user_id = r.user_id
    JOIN equipment e ON e.equipment_id = r.equipment_id
    LEFT JOIN users staff ON staff.user_id = ret.processed_by_staff_id
';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        try {
            requireRole(['admin', 'staff']);
            $stmt = $db->query(RETURN_SELECT . ' ORDER BY ret.actual_return_date DESC');
            sendJson(200, ['success' => true, 'data' => $stmt->fetchAll()]);
        } catch (PDOException $e) {
            sendJson(500, ['success' => false, 'message' => 'Could not load current loans and returns. Please try again.']);
        }
        break;

    case 'POST':
        // Staff/admin mark an approved request as returned.
        $staff = requireRole(['admin', 'staff']);
        $body = getJsonBody();
        $requestId = $body['request_id'] ?? null;
        $remarks   = cleanText($body['remarks'] ?? '');
        $condition = $body['condition_status'] ?? 'good'; // good | damaged | missing | under_repair

        if (strlen($remarks) > 500) {
            sendJson(400, ['success' => false, 'message' => 'Remarks must be 500 characters or fewer.']);
        }
        if (!$requestId) {
            sendJson(400, ['success' => false, 'message' => 'request_id is required.']);
        }
        if (!in_array($condition, ['good', 'damaged', 'missing', 'under_repair'], true)) {
            sendJson(400, ['success' => false, 'message' => 'Invalid condition_status.']);
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
            if ($req['status'] !== 'approved') {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => 'Only approved (currently borrowed) requests can be returned.']);
            }

            $insert = $db->prepare(
                'INSERT INTO returns (request_id, processed_by_staff_id, returned_quantity, remarks)
                 VALUES (:request_id, :staff_id, :returned_quantity, :remarks)'
            );
            $insert->execute([
                'request_id' => $requestId, 'staff_id' => $staff['user_id'],
                'returned_quantity' => (int)$req['requested_quantity'], 'remarks' => $remarks,
            ]);
            $newReturnId = (int)$db->lastInsertId();

            $db->prepare('UPDATE borrowing_requests SET status = :status WHERE request_id = :id')
               ->execute(['status' => 'returned', 'id' => $requestId]);

            // Equipment condition report — the second half of "return processing":
            // logs whether the item came back good, damaged, missing, or needing repair.
            $db->prepare(
                'INSERT INTO equipment_condition_reports (equipment_id, reported_by_user_id, condition_status, notes)
                 VALUES (:equipment_id, :reported_by, :condition_status, :notes)'
            )->execute([
                'equipment_id'     => $req['equipment_id'],
                'reported_by'      => $staff['user_id'],
                'condition_status' => $condition,
                'notes'            => $remarks,
            ]);

            if ($condition === 'good') {
                $db->prepare(
                    'UPDATE equipment
                     SET available_quantity = LEAST(total_quantity, available_quantity + :quantity),
                         status = "available"
                     WHERE equipment_id = :id'
                )->execute(['quantity' => (int)$req['requested_quantity'], 'id' => $req['equipment_id']]);
            } else {
                $db->prepare('UPDATE equipment SET status = "maintenance" WHERE equipment_id = :id')
                   ->execute(['id' => $req['equipment_id']]);
            }

            notifyUser($db, (int)$req['user_id'], "Your returned item for request #{$requestId} has been processed.");

            $db->commit();
            sendJson(201, ['success' => true, 'message' => 'Return recorded.', 'data' => ['return_id' => $newReturnId]]);
        } catch (PDOException $e) {
            $db->rollBack();
            sendJson(500, ['success' => false, 'message' => 'Could not record the return. Please try again.']);
        }
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
