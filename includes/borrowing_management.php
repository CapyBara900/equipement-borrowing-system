<?php
require_once __DIR__ . '/borrowing_submission.php';

const BORROWING_ACTION_STATUSES = ['approve' => 'approved', 'reject' => 'rejected', 'pickup' => 'borrowed', 'return' => 'returned', 'cancel' => 'cancelled'];
const BORROWING_MANAGEMENT_ROLES = ['admin', 'staff'];

/** Part 3 owns this table. This helper never creates or alters database objects. */
function borrowingAuditSchema(PDO $db): array
{
    $columns = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'borrowing_status_history'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $missing = [];
    foreach (['history_id', 'request_id', 'from_status', 'to_status', 'changed_by_user_id', 'changed_at'] as $column) {
        if (!isset($columns[$column])) $missing[] = 'borrowing_status_history.' . $column;
    }
    if (isset($columns['changed_at']) && !preg_match('/^(datetime|timestamp)\b/i', $columns['changed_at'])) $missing[] = 'borrowing_status_history.changed_at timestamp precision';
    if (!$missing) {
        $engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('borrowing_status_history','borrowing_requests','equipment','users','roles','returns','equipment_condition_reports','notifications')")->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach (['borrowing_status_history', 'borrowing_requests', 'equipment', 'users', 'roles', 'returns', 'equipment_condition_reports', 'notifications'] as $table) {
            if (strtoupper((string)($engines[$table] ?? '')) !== 'INNODB') $missing[] = $table . ' InnoDB storage';
        }
    }
    return ['columns' => $columns, 'audit_available' => !$missing, 'missing_requirements' => $missing];
}

/** Resolve the current database role; a stale or forged session role cannot grant actions. */
function borrowingAuthenticatedUser(PDO $db, array $allowedRoles, bool $lock = false): array
{
    $session = currentUser();
    if (!$session) throw new BorrowingFailure(401, 'You must be logged in.', 'UNAUTHENTICATED');
    if (!in_array($session['role'], $allowedRoles, true)) throw new BorrowingFailure(403, 'You do not have permission to manage this borrowing.', 'FORBIDDEN');
    // Lock only this actor's user row, rather than a shared role row.
    $sql = $lock ? 'SELECT user_id, role_id, name, email FROM users WHERE user_id = :id FOR UPDATE'
        : 'SELECT u.user_id, u.name, u.email, r.role_name AS role FROM users u JOIN roles r ON r.role_id = u.role_id WHERE u.user_id = :id';
    $stmt = $db->prepare($sql);
    $stmt->execute(['id' => borrowingPositiveInt($session['user_id'], 'user_id')]);
    $user = $stmt->fetch();
    if (!$user) throw new BorrowingFailure(401, 'Your account is no longer available. Please sign in again.', 'UNAUTHENTICATED');
    if ($lock) {
        $role = $db->prepare('SELECT role_name FROM roles WHERE role_id = :id');
        $role->execute(['id' => $user['role_id']]);
        $user['role'] = $role->fetchColumn();
        unset($user['role_id']);
    }
    if (!in_array($user['role'], $allowedRoles, true)) throw new BorrowingFailure(403, 'You do not have permission to manage this borrowing.', 'FORBIDDEN');
    $user['user_id'] = (int)$user['user_id'];
    return $user;
}

function borrowingMutationStatus(array $body): string
{
    $action = $body['action'] ?? null;
    $status = $body['status'] ?? null;
    if ($action !== null && (!is_string($action) || !isset(BORROWING_ACTION_STATUSES[$action]))) throw new BorrowingFailure(400, 'action must be approve, reject, pickup, return, or cancel.');
    if ($status !== null && !is_string($status)) throw new BorrowingFailure(400, 'status must be a single borrowing status.');
    $status = $status !== null ? strtolower(trim($status)) : null;
    if ($action !== null) {
        $target = BORROWING_ACTION_STATUSES[$action];
        if ($status !== null && $status !== $target) throw new BorrowingFailure(400, 'action and status must describe the same transition.');
        return $target;
    }
    if (!in_array($status, array_values(BORROWING_ACTION_STATUSES), true)) throw new BorrowingFailure(400, 'A valid target borrowing status or action is required.');
    return $status;
}

function borrowingReturnDetails(array $body): array
{
    if (isset($body['remarks']) && !is_string($body['remarks'])) throw new BorrowingFailure(400, 'remarks must be text.');
    $remarks = cleanText($body['remarks'] ?? '');
    if (strlen($remarks) > 500) throw new BorrowingFailure(400, 'Remarks must be 500 characters or fewer.');
    $condition = $body['condition_status'] ?? 'good';
    if (!is_string($condition) || !in_array($condition, ['good', 'damaged', 'missing', 'under_repair'], true)) throw new BorrowingFailure(400, 'Invalid condition_status.');
    return ['condition_status' => $condition, 'remarks' => $remarks];
}

/** One item, one transition, one transaction. Pending requests already reserved their units. */
function changeBorrowingStatus(PDO $db, int $requestId, string $target, array $details = [], bool $customerCancellation = false): array
{
    $from = ['approved' => 'pending', 'rejected' => 'pending', 'borrowed' => 'approved', 'returned' => 'borrowed', 'cancelled' => 'pending'][$target] ?? null;
    if ($from === null) throw new BorrowingFailure(400, 'Invalid target borrowing status.');
    $returnDetails = $target === 'returned' ? borrowingReturnDetails($details) : [];
    $db->beginTransaction();
    try {
        // Staff manage approvals, rejection, pickup, and return. Keep existing cancellation permissions.
        $allowedRoles = $target === 'cancelled' ? ($customerCancellation ? ['admin', 'customer'] : ['admin']) : BORROWING_MANAGEMENT_ROLES;
        $actor = borrowingAuthenticatedUser($db, $allowedRoles, true);
        $stmt = $db->prepare('SELECT * FROM borrowing_requests WHERE request_id = :id FOR UPDATE');
        $stmt->execute(['id' => $requestId]);
        $row = $stmt->fetch();
        if (!$row || ($actor['role'] === 'customer' && (int)$row['user_id'] !== $actor['user_id'])) throw new BorrowingFailure(404, 'Request not found.', 'REQUEST_NOT_FOUND');
        if ($actor['role'] === 'customer' && $target !== 'cancelled') throw new BorrowingFailure(403, 'Only administrators and staff can perform borrowing management actions.', 'FORBIDDEN');
        if ($row['status'] !== $from) throw new BorrowingFailure(409, 'Only ' . $from . ' requests can be changed to ' . $target . '.', 'INVALID_STATUS_TRANSITION');

        $audit = borrowingAuditSchema($db);
        // Customers retain pending-only self cancellation while Part 3 is pending.
        if (in_array($actor['role'], BORROWING_MANAGEMENT_ROLES, true) && !$audit['audit_available']) throw new BorrowingFailure(503, 'Borrowing management requires the Part 3 status history table. No status or stock was changed.', 'BORROWING_AUDIT_SCHEMA_REQUIRED');
        if ($target === 'borrowed') {
            $schema = borrowingHistorySchema($db);
            if (!$schema['pickup_storage_available']) throw new BorrowingFailure(503, 'Pickup tracking requires the Part 3 pickup fields.', 'PICKUP_SCHEMA_REQUIRED');
            if ($row['picked_up_at'] !== null) throw new BorrowingFailure(409, 'A pickup has already been recorded.', 'INVALID_STATUS_TRANSITION');
            $today = pickupDateWindow()['min_date'];
            if (!$row['borrow_date'] || $today < $row['borrow_date']) throw new BorrowingFailure(409, 'This request is not yet due for pickup.', 'PICKUP_NOT_DUE');
            if (!$row['expected_return_date'] || $today > $row['expected_return_date']) throw new BorrowingFailure(409, 'The return date has passed. This request cannot be picked up.', 'PICKUP_WINDOW_EXPIRED');
        }

        $equipment = $db->prepare('SELECT equipment_id, total_quantity, available_quantity FROM equipment WHERE equipment_id = :id FOR UPDATE');
        $equipment->execute(['id' => $row['equipment_id']]);
        if (!$equipment->fetch()) throw new BorrowingFailure(404, 'Equipment not found.', 'EQUIPMENT_NOT_FOUND');
        $quantity = borrowingPositiveInt($row['requested_quantity'], 'requested_quantity');
        $now = (new DateTimeImmutable('now', borrowingTimezone()))->format('Y-m-d H:i:s');
        $returnId = null;
        if ($target === 'returned') {
            $existing = $db->prepare('SELECT return_id FROM returns WHERE request_id = :id');
            $existing->execute(['id' => $requestId]);
            if ($existing->fetch()) throw new BorrowingFailure(409, 'A return has already been recorded.', 'INVALID_STATUS_TRANSITION');
            $db->prepare('INSERT INTO returns (request_id, processed_by_staff_id, returned_quantity, remarks, actual_return_date) VALUES (:id, :actor, :quantity, :remarks, :returned_on)')->execute([
                'id' => $requestId, 'actor' => $actor['user_id'], 'quantity' => $quantity, 'remarks' => $returnDetails['remarks'], 'returned_on' => substr($now, 0, 10),
            ]);
            $returnId = (int)$db->lastInsertId();
            $db->prepare('INSERT INTO equipment_condition_reports (equipment_id, reported_by_user_id, condition_status, notes) VALUES (:id, :actor, :condition, :notes)')->execute([
                'id' => $row['equipment_id'], 'actor' => $actor['user_id'], 'condition' => $returnDetails['condition_status'], 'notes' => $returnDetails['remarks'],
            ]);
        }
        if ($target === 'borrowed') {
            $db->prepare("UPDATE borrowing_requests SET status = 'borrowed', picked_up_at = :changed_at, picked_up_by_staff_id = :actor WHERE request_id = :id")->execute(['changed_at' => $now, 'actor' => $actor['user_id'], 'id' => $requestId]);
        } else {
            $db->prepare('UPDATE borrowing_requests SET status = :status WHERE request_id = :id')->execute(['status' => $target, 'id' => $requestId]);
        }
        // Preserve the existing release calculation; approval and pickup never deduct again.
        if (in_array($target, ['rejected', 'cancelled'], true) || ($target === 'returned' && $returnDetails['condition_status'] === 'good')) {
            $sql = 'UPDATE equipment SET available_quantity = LEAST(total_quantity, available_quantity + :quantity)' . ($target === 'returned' ? ", status = 'available'" : '') . ' WHERE equipment_id = :id';
            $db->prepare($sql)->execute(['quantity' => $quantity, 'id' => $row['equipment_id']]);
        } elseif ($target === 'returned') {
            $db->prepare("UPDATE equipment SET status = 'maintenance' WHERE equipment_id = :id")->execute(['id' => $row['equipment_id']]);
        }
        if ($audit['audit_available']) {
            $db->prepare('INSERT INTO borrowing_status_history (request_id, from_status, to_status, changed_by_user_id, changed_at) VALUES (:id, :previous, :status, :actor, :changed_at)')->execute([
                'id' => $requestId, 'previous' => $from, 'status' => $target, 'actor' => $actor['user_id'], 'changed_at' => $now,
            ]);
        }
        $messages = ['approved' => 'Request approved.', 'rejected' => 'Request rejected.', 'borrowed' => 'Pickup recorded.', 'returned' => 'Return recorded.', 'cancelled' => 'Request cancelled.'];
        notifyUser($db, (int)$row['user_id'], "Your borrowing request #$requestId was $target.");
        $db->commit();
        $data = ['request_id' => $requestId, 'previous_status' => $from, 'status' => $target, 'changed_by_user_id' => $actor['user_id'], 'changed_at' => $now, 'audit_recorded' => $audit['audit_available']];
        if ($target === 'borrowed') $data += ['picked_up_at' => $now, 'picked_up_by_staff_id' => $actor['user_id']];
        if ($returnId !== null) $data += ['return_id' => $returnId, 'returned_quantity' => $quantity, 'actual_return_date' => substr($now, 0, 10)];
        return ['success' => true, 'message' => $messages[$target], 'data' => $data];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

function borrowingDatabaseFailure(PDOException $error): array
{
    $conflict = $error->getCode() === '40001' || in_array((int)($error->errorInfo[1] ?? 0), [1205, 1213], true);
    return [$conflict ? 409 : 500, ['success' => false, 'message' => $conflict ? 'Another operation is updating this borrowing. Refresh its saved status before retrying.' : 'Could not update borrowing. No changes were committed.', 'code' => $conflict ? 'RETRYABLE_CONFLICT' : 'BORROWING_UPDATE_FAILED']];
}
