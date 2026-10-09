<?php
require_once __DIR__ . '/borrowing_submission.php';
require_once __DIR__ . '/borrowing_management.php';

const BORROWING_HISTORY_STATUSES = ['pending', 'approved', 'borrowed', 'returned', 'rejected', 'cancelled'];

/** Read only. Schema changes belong to Part 3. */
function borrowingHistorySchema(PDO $db): array
{
    $columns = $db->query("SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'borrowing_requests'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $missing = [];
    if (!str_contains((string)($columns['status'] ?? ''), "'borrowed'")) $missing[] = 'borrowing_requests.status borrowed value';
    foreach (['picked_up_at', 'picked_up_by_staff_id'] as $column) if (!isset($columns[$column])) $missing[] = 'borrowing_requests.' . $column;
    $audit = borrowingAuditSchema($db);
    return ['columns' => $columns, 'pickup_storage_available' => !$missing, 'pickup_available' => !$missing && $audit['audit_available'], 'management_available' => !$missing && $audit['audit_available'], 'audit_available' => $audit['audit_available'], 'missing_requirements' => array_merge($missing, $audit['missing_requirements'])];
}

function borrowingHistoryScalar(array $query, string $key, string $default = ''): string
{
    if (!array_key_exists($key, $query)) return $default;
    if (!is_string($query[$key]) && !is_int($query[$key])) throw new BorrowingFailure(400, "$key must be a single value.");
    return (string)$query[$key];
}

function borrowingHistoryDate(string $value, string $field, bool $end): string
{
    if ($value === '') return '';
    $format = strlen($value) === 10 ? 'Y-m-d' : 'Y-m-d H:i:s';
    $date = DateTimeImmutable::createFromFormat('!' . $format, $value, borrowingTimezone());
    if (!$date || $date->format($format) !== $value) throw new BorrowingFailure(400, "$field must be a valid date or timestamp.");
    return $format === 'Y-m-d' ? $value . ($end ? ' 23:59:59' : ' 00:00:00') : $value;
}

function normalizeBorrowingHistoryQuery(array $query): array
{
    $view = borrowingHistoryScalar($query, 'view', 'requests');
    if (!in_array($view, ['requests', 'transactions'], true)) throw new BorrowingFailure(400, 'view must be requests or transactions.');
    $status = strtolower(trim(borrowingHistoryScalar($query, 'status')));
    if ($status === 'all') $status = '';
    if ($status !== '' && !in_array($status, BORROWING_HISTORY_STATUSES, true)) throw new BorrowingFailure(400, 'Invalid borrowing status.');
    $from = borrowingHistoryDate(borrowingHistoryScalar($query, 'date_from'), 'date_from', false);
    $to = borrowingHistoryDate(borrowingHistoryScalar($query, 'date_to'), 'date_to', true);
    if ($from !== '' && $to !== '' && $from > $to) throw new BorrowingFailure(400, 'date_from must be on or before date_to.');
    $page = borrowingPositiveInt($query['page'] ?? 1, 'page');
    $limit = borrowingPositiveInt($query['limit'] ?? 20, 'limit');
    if ($limit > 50) throw new BorrowingFailure(400, 'limit must be between 1 and 50.');
    $id = array_key_exists('id', $query) ? borrowingPositiveInt($query['id'], 'id') : null;
    $checkout = array_key_exists('checkout_id', $query) ? borrowingPositiveInt($query['checkout_id'], 'checkout_id') : null;
    if ($id !== null && $checkout !== null) throw new BorrowingFailure(400, 'Use id or checkout_id, not both.');
    $capabilities = borrowingHistoryScalar($query, 'capabilities');
    if ($capabilities !== '' && $capabilities !== '1') throw new BorrowingFailure(400, 'capabilities must be 1.');
    return ['view' => $view, 'status' => $status, 'date_from' => $from, 'date_to' => $to, 'page' => $page, 'limit' => $limit, 'id' => $id, 'checkout_id' => $checkout, 'capabilities' => $capabilities === '1'];
}

function borrowingHistorySelect(array $schema): string
{
    $pickup = isset($schema['columns']['picked_up_at']) ? 'r.picked_up_at' : 'NULL AS picked_up_at';
    $staff = isset($schema['columns']['picked_up_by_staff_id']) ? 'r.picked_up_by_staff_id' : 'NULL AS picked_up_by_staff_id';
    $pickupJoin = isset($schema['columns']['picked_up_by_staff_id']) ? 'pickup.user_id = r.picked_up_by_staff_id' : '1 = 0';
    return "SELECT r.request_id, r.request_date, r.borrow_date, r.expected_return_date, r.requested_quantity, r.status, r.checkout_id,
                   r.user_id, u.name AS user_name, u.email AS user_email,
                   r.equipment_id, e.equipment_name, e.description AS equipment_description, e.serial_number, e.category_id, c.category_name,
                   $pickup, $staff, pickup.name AS picked_up_by_name,
                   ret.return_id, ret.actual_return_date, ret.returned_quantity, ret.remarks AS return_remarks,
                   ret.processed_by_staff_id, processor.name AS processed_by_name
            FROM borrowing_requests r JOIN users u ON u.user_id = r.user_id JOIN equipment e ON e.equipment_id = r.equipment_id
            LEFT JOIN categories c ON c.category_id = e.category_id
            LEFT JOIN users pickup ON $pickupJoin
            LEFT JOIN returns ret ON ret.request_id = r.request_id
            LEFT JOIN users processor ON processor.user_id = ret.processed_by_staff_id";
}

function borrowingHistoryRow(array $row): array
{
    foreach (['request_id', 'user_id', 'equipment_id', 'requested_quantity'] as $field) $row[$field] = (int)$row[$field];
    foreach (['checkout_id', 'picked_up_by_staff_id'] as $field) $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
    $row['picked_up_at'] = $row['picked_up_at'] ?? null;
    $row['transaction_id'] = $row['checkout_id'] !== null ? 'checkout:' . $row['checkout_id'] : 'request:' . $row['request_id'];
    $row['transaction_reference'] = $row['checkout_id'] !== null ? 'Checkout #' . $row['checkout_id'] : 'Request #' . $row['request_id'];
    foreach (['category_id', 'return_id', 'returned_quantity', 'processed_by_staff_id'] as $field) $row[$field] = isset($row[$field]) ? (int)$row[$field] : null;
    $row['customer'] = ['user_id' => $row['user_id'], 'name' => $row['user_name'], 'email' => $row['user_email'] ?? null];
    $row['equipment'] = ['equipment_id' => $row['equipment_id'], 'name' => $row['equipment_name'], 'description' => $row['equipment_description'] ?? null, 'serial_number' => $row['serial_number'] ?? null, 'category_id' => $row['category_id'], 'category_name' => $row['category_name'] ?? null];
    // Status comes only from the request record; planned pickup dates and equipment status are not evidence of pickup.
    return $row;
}

function borrowingHistoryRows(PDO $db, array $rows, array $schema): array
{
    $rows = array_map('borrowingHistoryRow', $rows);
    $events = [];
    if ($rows && $schema['audit_available']) {
        $ids = array_values(array_unique(array_column($rows, 'request_id')));
        foreach (array_chunk($ids, 50) as $batch) {
            $params = []; $placeholders = [];
            foreach ($batch as $index => $id) { $params['id' . $index] = $id; $placeholders[] = ':id' . $index; }
            $sql = 'SELECT h.history_id, h.request_id, h.from_status, h.to_status, h.changed_by_user_id, h.changed_at, actor.name AS changed_by_name FROM borrowing_status_history h LEFT JOIN users actor ON actor.user_id = h.changed_by_user_id WHERE h.request_id IN (' . implode(',', $placeholders) . ') ORDER BY h.changed_at, h.history_id';
            foreach (borrowingHistoryQuery($db, $sql, $params)->fetchAll() as $event) {
                foreach (['history_id', 'request_id', 'changed_by_user_id'] as $field) $event[$field] = (int)$event[$field];
                $events[$event['request_id']][] = $event;
            }
        }
    }
    foreach ($rows as &$row) {
        $row['status_history'] = $events[$row['request_id']] ?? [];
        $row['status_history_available'] = $schema['audit_available'];
    }
    unset($row);
    return $rows;
}

function borrowingHistoryWhere(array $user, array $options): array
{
    $conditions = []; $params = [];
    if ($user['role'] === 'customer') { $conditions[] = 'r.user_id = :owner'; $params['owner'] = (int)$user['user_id']; }
    foreach (['checkout_id' => '=', 'status' => '=', 'date_from' => '>=', 'date_to' => '<='] as $key => $operator) {
        if ($options[$key] === null || $options[$key] === '') continue;
        $column = str_starts_with($key, 'date_') ? 'request_date' : $key;
        $conditions[] = "r.$column $operator :$key"; $params[$key] = $options[$key];
    }
    return [$conditions ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
}

function borrowingHistoryQuery(PDO $db, string $sql, array $params): PDOStatement
{
    $stmt = $db->prepare($sql);
    foreach ($params as $name => $value) $stmt->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
    $stmt->execute();
    return $stmt;
}

/** Current status belongs to a request, even within a shared checkout. */
function borrowingHistoryTransaction(array $row): array
{
    $reference = 'Request #' . $row['request_id'];
    if ($row['checkout_id'] !== null) $reference .= ' (Checkout #' . $row['checkout_id'] . ')';
    return [
        'transaction_id' => 'request:' . $row['request_id'],
        'transaction_reference' => $reference,
        'checkout_id' => $row['checkout_id'],
        'request_date' => $row['request_date'],
        'customer' => $row['customer'],
        'status' => $row['status'],
        'statuses' => [$row['status']],
        'items' => [$row],
    ];
}

function readBorrowingHistory(PDO $db, array $user, array $options, array $schema): array
{
    if ($options['view'] === 'transactions') {
        // Reuse the request query: status filters and pagination must never hydrate unrelated checkout siblings.
        $requestOptions = $options;
        $requestOptions['view'] = 'requests';
        $result = readBorrowingHistory($db, $user, $requestOptions, $schema);
        $result['data'] = $options['id'] !== null
            ? borrowingHistoryTransaction($result['data'])
            : array_map('borrowingHistoryTransaction', $result['data']);
        $result['record_type'] = 'transactions';
        return $result;
    }
    $select = borrowingHistorySelect($schema);
    if ($options['id'] !== null) {
        $params = ['id' => $options['id']];
        $scope = '';
        if ($user['role'] === 'customer') { $scope = ' AND r.user_id = :owner'; $params['owner'] = (int)$user['user_id']; }
        $row = borrowingHistoryQuery($db, $select . ' WHERE r.request_id = :id' . $scope, $params)->fetch();
        // Same response for unknown IDs and records outside the customer's account.
        if (!$row) throw new BorrowingFailure(404, 'Request not found.', 'REQUEST_NOT_FOUND');
        $row = borrowingHistoryRows($db, [$row], $schema)[0];
        return ['success' => true, 'data' => $row];
    }
    [$where, $params] = borrowingHistoryWhere($user, $options);
    $offset = ($options['page'] - 1) * $options['limit'];
    $total = (int)borrowingHistoryQuery($db, 'SELECT COUNT(*) FROM borrowing_requests r' . $where, $params)->fetchColumn();
    $rows = borrowingHistoryQuery($db, $select . $where . ' ORDER BY r.request_date DESC, r.request_id DESC LIMIT :limit OFFSET :offset', $params + ['limit' => $options['limit'], 'offset' => $offset])->fetchAll();
    $data = borrowingHistoryRows($db, $rows, $schema);
    return ['success' => true, 'data' => $data, 'page' => $options['page'], 'limit' => $options['limit'], 'total' => $total, 'has_more' => $offset + count($data) < $total, 'record_type' => $options['view']];
}

/** Compatibility entry point. The authenticated administrator or staff member is resolved server-side. */
function pickupBorrowingRequest(PDO $db, int $requestId, int $staffId): array
{
    return changeBorrowingStatus($db, $requestId, 'borrowed');
}
