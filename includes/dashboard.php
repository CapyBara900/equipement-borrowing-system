<?php
require_once __DIR__ . '/borrowing_management.php';

/** All filters are fixed SQL clauses; user input is never interpolated into SQL. */
function dashboardOptions(array $query): array
{
    $filter = $query['filter'] ?? 'active';
    if (!is_string($filter) || !in_array($filter, ['active','all','pending','approved','borrowed','returned','rejected','cancelled','due_today','overdue'], true)) {
        throw new BorrowingFailure(400, 'Choose a valid dashboard filter.');
    }
    $page = borrowingPositiveInt($query['page'] ?? 1, 'page');
    $limit = borrowingPositiveInt($query['limit'] ?? 8, 'limit');
    if ($limit > 20 || $page > 1000000) throw new BorrowingFailure(400, 'Invalid dashboard page size or page.');
    return ['filter' => $filter, 'page' => $page, 'limit' => $limit];
}

function readDashboard(PDO $db, array $user, array $options): array
{
    $customer = $user['role'] === 'customer';
    $scope = $customer ? 'r.user_id = ?' : '1 = 1';
    $scopeArgs = $customer ? [(int)$user['user_id']] : [];
    $today = (new DateTimeImmutable('now', borrowingTimezone()))->format('Y-m-d');
    $summaryQuery = $db->prepare("SELECT COUNT(*) AS total_requests,
        COALESCE(SUM(r.status = 'pending'),0) AS pending,
        COALESCE(SUM(r.status = 'approved'),0) AS approved,
        COALESCE(SUM(r.status = 'borrowed'),0) AS borrowed,
        COALESCE(SUM(r.status = 'returned'),0) AS completed,
        COALESCE(SUM(r.status = 'rejected'),0) AS rejected,
        COALESCE(SUM(r.status = 'cancelled'),0) AS cancelled,
        COALESCE(SUM(r.status = 'borrowed' AND r.expected_return_date = ?),0) AS due_today,
        COALESCE(SUM(r.status = 'borrowed' AND r.expected_return_date < ?),0) AS overdue,
        COALESCE(SUM(CASE WHEN r.status = 'borrowed' THEN r.requested_quantity ELSE 0 END),0) AS borrowed_units,
        MIN(CASE WHEN r.status = 'borrowed' AND r.expected_return_date >= ? THEN r.expected_return_date END) AS next_due_date
        FROM borrowing_requests r WHERE $scope");
    $summaryQuery->execute(array_merge([$today, $today, $today], $scopeArgs));
    $summary = $summaryQuery->fetch();
    foreach ($summary as $key => $value) if ($key !== 'next_due_date') $summary[$key] = (int)$value;
    if (!$customer) {
        $summary['total_units'] = (int)$db->query('SELECT COALESCE(SUM(total_quantity),0) FROM equipment')->fetchColumn();
        $summary['utilization'] = $summary['total_units'] ? round(100 * $summary['borrowed_units'] / $summary['total_units'], 1) : 0;
    }
    $filter = $options['filter'];
    $filterArgs = [];
    if ($filter === 'active') $condition = "r.status IN ('approved','borrowed')";
    elseif ($filter === 'all') $condition = '1 = 1';
    elseif ($filter === 'due_today' || $filter === 'overdue') {
        $condition = "r.status = 'borrowed' AND r.expected_return_date " . ($filter === 'due_today' ? '= ?' : '< ?');
        $filterArgs = [$today];
    } else { $condition = 'r.status = ?'; $filterArgs = [$filter]; }
    $args = array_merge($scopeArgs, $filterArgs);
    $count = $db->prepare("SELECT COUNT(*) FROM borrowing_requests r WHERE $scope AND $condition");
    $count->execute($args);
    $total = (int)$count->fetchColumn();
    $pages = max(1, (int)ceil($total / $options['limit']));
    $page = min($options['page'], $pages);
    $offset = ($page - 1) * $options['limit'];
    $rows = $db->prepare("SELECT r.request_id, r.user_id, r.equipment_id, r.status, r.request_date,
        r.borrow_date, r.expected_return_date, r.requested_quantity, e.equipment_name, u.name AS user_name,
        ret.actual_return_date
        FROM borrowing_requests r JOIN equipment e ON e.equipment_id = r.equipment_id
        JOIN users u ON u.user_id = r.user_id LEFT JOIN returns ret ON ret.request_id = r.request_id
        WHERE $scope AND $condition
        ORDER BY CASE r.status WHEN 'borrowed' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END,
        CASE WHEN r.status = 'borrowed' THEN COALESCE(r.expected_return_date, '9999-12-31')
             WHEN r.status = 'approved' THEN COALESCE(r.borrow_date, '9999-12-31') END,
        r.request_date DESC, r.request_id DESC LIMIT ? OFFSET ?");
    foreach ($args as $index => $arg) $rows->bindValue($index + 1, $arg);
    $rows->bindValue(count($args) + 1, $options['limit'], PDO::PARAM_INT);
    $rows->bindValue(count($args) + 2, $offset, PDO::PARAM_INT);
    $rows->execute();

    // Request creation and recorded returns are real events, scoped just like the metrics.
    $activity = $db->prepare("(SELECT 'request' AS type, r.request_id AS ref_id, u.name AS user_name,
        e.equipment_name, r.status, r.request_date AS event_date FROM borrowing_requests r
        JOIN users u ON u.user_id = r.user_id JOIN equipment e ON e.equipment_id = r.equipment_id
        WHERE $scope ORDER BY r.request_date DESC, r.request_id DESC LIMIT 8)
        UNION ALL
        (SELECT 'return' AS type, r.request_id AS ref_id, u.name AS user_name, e.equipment_name,
        'returned' AS status, ret.actual_return_date AS event_date FROM returns ret
        JOIN borrowing_requests r ON r.request_id = ret.request_id JOIN users u ON u.user_id = r.user_id
        JOIN equipment e ON e.equipment_id = r.equipment_id WHERE $scope
        ORDER BY ret.actual_return_date DESC, ret.return_id DESC LIMIT 8)
        ORDER BY event_date DESC, ref_id DESC LIMIT 8");
    $activity->execute(array_merge($scopeArgs, $scopeArgs));
    $monthly = $mostRequested = [];
    if (!$customer) {
        $firstMonth = (new DateTimeImmutable($today, borrowingTimezone()))->modify('first day of this month')->modify('-5 months')->format('Y-m-d');
        $report = $db->prepare("SELECT DATE_FORMAT(request_date, '%Y-%m') AS month, COUNT(*) AS total
            FROM borrowing_requests WHERE request_date >= ? GROUP BY month ORDER BY month");
        $report->execute([$firstMonth]);
        $monthly = $report->fetchAll();
        $mostRequested = $db->query("SELECT e.equipment_name, COUNT(*) AS request_count FROM borrowing_requests r
            JOIN equipment e ON e.equipment_id = r.equipment_id GROUP BY r.equipment_id, e.equipment_name
            ORDER BY request_count DESC, e.equipment_name LIMIT 5")->fetchAll();
    }
    return ['role' => $user['role'], 'today' => $today, 'timezone' => borrowingTimezone()->getName(),
        'summary' => $summary, 'borrowings' => $rows->fetchAll(),
        'pagination' => ['filter' => $filter, 'page' => $page, 'pages' => $pages, 'limit' => $options['limit'], 'total' => $total],
        'recent_activity' => $activity->fetchAll(), 'monthly' => $monthly, 'most_requested' => $mostRequested];
}
