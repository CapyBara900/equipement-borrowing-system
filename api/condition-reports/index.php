<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

const CONDITION_SELECT = '
    SELECT cr.report_id, cr.condition_status, cr.notes, cr.logged_at,
           cr.equipment_id, e.equipment_name,
           cr.reported_by_user_id, u.name AS reported_by_name
    FROM equipment_condition_reports cr
    JOIN equipment e ON e.equipment_id = cr.equipment_id
    LEFT JOIN users u ON u.user_id = cr.reported_by_user_id
';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        requireRole(['admin']);
        $sql = CONDITION_SELECT;
        $params = [];
        if (!empty($_GET['equipment_id'])) {
            $sql .= ' WHERE cr.equipment_id = :equipment_id';
            $params['equipment_id'] = $_GET['equipment_id'];
        }
        $sql .= ' ORDER BY cr.logged_at DESC';
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll()]);
        break;

    case 'POST':
        // Admins can log a condition report any time, not just at return
        // (e.g. a routine inspection, or damage noticed outside a return).
        $user = requireRole(['admin']);
        $body = getJsonBody();
        $equipmentId = $body['equipment_id'] ?? null;
        $condition   = $body['condition_status'] ?? null;
        $notes       = cleanText($body['notes'] ?? '');

        if (!$equipmentId || !in_array($condition, ['good', 'damaged', 'missing', 'under_repair'], true)) {
            sendJson(400, ['success' => false, 'message' => 'equipment_id and a valid condition_status are required.']);
        }

        try {
            $db->beginTransaction();

            $stmt = $db->prepare(
                'INSERT INTO equipment_condition_reports (equipment_id, reported_by_user_id, condition_status, notes)
                 VALUES (:equipment_id, :reported_by, :condition_status, :notes)'
            );
            $stmt->execute([
                'equipment_id'     => $equipmentId,
                'reported_by'      => $user['user_id'],
                'condition_status' => $condition,
                'notes'            => $notes,
            ]);
            $newId = (int)$db->lastInsertId();

            if ($condition !== 'good') {
                $db->prepare('UPDATE equipment SET status = :status WHERE equipment_id = :id')
                   ->execute(['status' => 'maintenance', 'id' => $equipmentId]);
            }

            $db->commit();
            sendJson(201, ['success' => true, 'message' => 'Condition report logged.', 'data' => ['report_id' => $newId]]);
        } catch (PDOException $e) {
            $db->rollBack();
            sendJson(500, ['success' => false, 'message' => 'Could not log the condition report. Please try again.']);
        }
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
