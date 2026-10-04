<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $user = requireRole(['customer']);
        $sql = 'SELECT notification_id, message, is_read, created_at FROM notifications WHERE user_id = :user_id';
        $params = ['user_id' => $user['user_id']];
        if (isset($_GET['unread_only']) && $_GET['unread_only'] === '1') {
            $sql .= ' AND is_read = 0';
        }
        $sql .= ' ORDER BY created_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'PUT':
        // Mark one (or all) of the current user's notifications as read.
        $user = requireRole(['customer']);
        $body = getJsonBody();

        if (!empty($body['mark_all'])) {
            $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = :user_id');
            $stmt->execute(['user_id' => $user['user_id']]);
            sendJson(200, ['success' => true, 'message' => 'All notifications marked as read.']);
        }

        $id = $body['notification_id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'notification_id (or mark_all) is required.']);
        }
        $stmt = $db->prepare('UPDATE notifications SET is_read = 1 WHERE notification_id = :id AND user_id = :user_id');
        $stmt->execute(['id' => $id, 'user_id' => $user['user_id']]);
        if ($stmt->rowCount() === 0) {
            sendJson(404, ['success' => false, 'message' => 'Notification not found.']);
        }
        sendJson(200, ['success' => true, 'message' => 'Notification marked as read.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
