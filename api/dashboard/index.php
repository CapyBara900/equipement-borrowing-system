<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}

requireRole(['admin', 'staff']);

// ---- Summary counts (the "Total Requests / Pending / Completed / Cancelled" cards) ----
$counts = $db->query(
    "SELECT
        COUNT(*) AS total_requests,
        SUM(status = 'pending')  AS pending,
        SUM(status = 'approved') AS approved,
        SUM(status = 'returned') AS completed,
        SUM(status = 'rejected') AS cancelled
     FROM borrowing_requests"
)->fetch();

// ---- Monthly transactions for the last 6 months (chart data) ----
$monthly = $db->query(
    "SELECT DATE_FORMAT(request_date, '%Y-%m') AS month, COUNT(*) AS total
     FROM borrowing_requests
     WHERE request_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
     GROUP BY month
     ORDER BY month"
)->fetchAll();

// ---- Most requested equipment ----
$mostRequested = $db->query(
    "SELECT e.equipment_name, COUNT(*) AS request_count
     FROM borrowing_requests r
     JOIN equipment e ON e.equipment_id = r.equipment_id
     GROUP BY r.equipment_id, e.equipment_name
     ORDER BY request_count DESC
     LIMIT 5"
)->fetchAll();

// ---- Recent activity feed (latest requests + returns, merged) ----
$recentActivity = $db->query(
    "(SELECT 'request' AS type, r.request_id AS ref_id, u.name AS user_name,
             e.equipment_name, r.status, r.request_date AS event_date
      FROM borrowing_requests r
      JOIN users u ON u.user_id = r.user_id
      JOIN equipment e ON e.equipment_id = r.equipment_id
      ORDER BY r.request_date DESC LIMIT 10)
     UNION ALL
     (SELECT 'return' AS type, ret.request_id AS ref_id, u.name AS user_name,
             e.equipment_name, 'returned' AS status, ret.actual_return_date AS event_date
      FROM returns ret
      JOIN borrowing_requests r ON r.request_id = ret.request_id
      JOIN users u ON u.user_id = r.user_id
      JOIN equipment e ON e.equipment_id = r.equipment_id
      ORDER BY ret.actual_return_date DESC LIMIT 10)
     ORDER BY event_date DESC
     LIMIT 10"
)->fetchAll();

sendJson(200, [
    'success' => true,
    'data' => [
        'summary'          => $counts,
        'monthly'          => $monthly,
        'most_requested'   => $mostRequested,
        'recent_activity'  => $recentActivity,
    ],
]);
