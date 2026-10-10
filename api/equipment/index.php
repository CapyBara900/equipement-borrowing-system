<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/category_management.php';

const EQUIPMENT_SELECT = '
    SELECT e.equipment_id, e.equipment_name, e.description, e.serial_number, e.status,
           e.category_id, e.total_quantity, e.available_quantity, e.borrowing_time_limit_days, c.category_name, e.created_at
    FROM equipment e
    LEFT JOIN categories c ON c.category_id = e.category_id
';

$allowedSort = ['equipment_name', 'status', 'created_at'];

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        try {
        $user = requireRole(['admin', 'customer']);

        // ----------------------------------------------------------------
        // Customers see only universal catalog availability. Their personal
        // request history is provided by the requests endpoint instead.
        //
        // Admins receive:
        //     - e.status (raw, unmodified — must stay 'pending' so the
        //       approval workflow can check and act on it correctly)
        //     - pending_request_id, pending_user_id, pending_user_name
        //       when a pending borrowing request exists for the item.
        // ----------------------------------------------------------------

        if ($user['role'] === 'customer') {
            $baseSelect = "
                SELECT e.equipment_id, e.equipment_name, e.description,
                       e.serial_number,
                       CASE
                           WHEN e.available_quantity > 0 THEN 'available'
                           ELSE 'unavailable'
                       END AS status,
                       e.category_id, e.total_quantity, e.available_quantity, e.borrowing_time_limit_days,
                       c.category_name, e.created_at
                FROM equipment e
                LEFT JOIN categories c ON c.category_id = e.category_id
            ";
        } else {
            // Admins: raw status + pending request info for management.
            // We join the MOST RECENT pending request so admins can see who
            // submitted it without visiting the separate Requests page.
            $baseSelect = "
                SELECT e.equipment_id, e.equipment_name, e.description,
                       e.serial_number,
                       CASE WHEN e.available_quantity > 0 THEN 'available' ELSE 'unavailable' END AS status,
                       e.status AS equipment_status,
                       GREATEST(0, e.total_quantity - e.available_quantity - COALESCE((SELECT SUM(active.requested_quantity) FROM borrowing_requests active WHERE active.equipment_id = e.equipment_id AND active.status IN ('pending','approved','borrowed')), 0)) AS held_quantity,
                       e.total_quantity, e.available_quantity, e.borrowing_time_limit_days,
                       br.request_id  AS pending_request_id,
                       br.user_id     AS pending_user_id,
                       u_req.name     AS pending_user_name,
                       e.category_id, c.category_name, e.created_at
                FROM equipment e
                LEFT JOIN categories c ON c.category_id = e.category_id
                LEFT JOIN borrowing_requests br
                       ON br.equipment_id = e.equipment_id
                      AND br.status       = 'pending'
                       AND br.request_id = (SELECT MAX(br2.request_id) FROM borrowing_requests br2
                                            WHERE br2.equipment_id = e.equipment_id
                                              AND br2.status = 'pending')
                LEFT JOIN users u_req ON u_req.user_id = br.user_id
            ";
        }

        if (isset($_GET['id'])) {
            $stmt = $db->prepare($baseSelect . ' WHERE e.equipment_id = :id');
            $binds = ['id' => $_GET['id']];
            $stmt->execute($binds);
            $item = $stmt->fetch();
            if (!$item) {
                sendJson(404, ['success' => false, 'message' => 'Equipment not found.']);
            }
            sendJson(200, ['success' => true, 'data' => $item]);
        }

        $sql = $baseSelect;
        $conditions = [];
        $params = [];

        if (!empty($_GET['search'])) {
            $conditions[] = '(e.equipment_name LIKE :search OR e.description LIKE :search OR e.serial_number LIKE :search)';
            $params['search'] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['category_id'])) {
            $conditions[] = 'e.category_id = :category_id';
            $params['category_id'] = $_GET['category_id'];
        }
        if (!empty($_GET['status'])) {
            if (in_array($_GET['status'], ['available', 'unavailable'], true)) {
                $conditions[] = "(CASE WHEN e.available_quantity > 0 THEN 'available' ELSE 'unavailable' END) = :status";
            } else {
                // These filters describe the equipment workflow/condition,
                // not catalog availability.
                $conditions[] = 'e.status = :status';
            }
            $params['status'] = $_GET['status'];
        }
        if ($conditions) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        $sortBy = in_array($_GET['sort_by'] ?? '', $allowedSort, true) ? $_GET['sort_by'] : 'equipment_name';
        $sortDir = (strtoupper($_GET['sort_dir'] ?? '') === 'DESC') ? 'DESC' : 'ASC';
        $sql .= " ORDER BY e.$sortBy $sortDir";

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
        } catch (PDOException $e) {
            sendJson(500, ['success' => false, 'message' => 'Could not load equipment. Please try again.']);
        }
        break;

    case 'POST':
        requireRole(['admin']);
        $body = getJsonBody();
        $name = cleanText($body['equipment_name'] ?? '');
        $description = cleanText($body['description'] ?? '');
        $serialNumber = cleanText($body['serial_number'] ?? '');
        try { $categoryId = categoryAssignmentId($db, $body['category_id'] ?? null); }
        catch (InvalidArgumentException $e) { sendJson(400, ['success' => false, 'message' => $e->getMessage()]); }
        $status = $body['status'] ?? 'available';
        $borrowingLimit = $body['borrowing_time_limit_days'] ?? null;
        if ((!is_int($borrowingLimit) && (!is_string($borrowingLimit) || !ctype_digit($borrowingLimit))) || (int)$borrowingLimit < 1 || (int)$borrowingLimit > 3650) {
            sendJson(400, ['success' => false, 'message' => 'Borrowing time limit must be a whole number between 1 and 3650 days.']);
        }
        $totalQuantity = $body['total_quantity'] ?? null;
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'equipment_name is required.']);
        }
        if (filter_var($totalQuantity, FILTER_VALIDATE_INT) === false || (int)$totalQuantity < 1) {
            sendJson(400, ['success' => false, 'message' => 'total_quantity must be a positive whole number.']);
        }
        if (strlen($name) > 150 || strlen($description) > 500 || strlen($serialNumber) > 100) {
            sendJson(400, ['success' => false, 'message' => 'One or more equipment fields are too long.']);
        }
        if (!in_array($status, ['available', 'borrowed', 'maintenance', 'pending'], true)) {
            sendJson(400, ['success' => false, 'message' => 'Invalid equipment status.']);
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO equipment (equipment_name, description, serial_number, category_id, total_quantity, available_quantity, borrowing_time_limit_days, status)
                 VALUES (:name, :description, :serial_number, :category_id, :total_quantity, :available_quantity, :borrowing_time_limit_days, :status)'
            );
            $stmt->execute([
                'borrowing_time_limit_days' => (int)$borrowingLimit,
                'name'          => $name,
                'description'   => $description,
                'serial_number' => $serialNumber ?: null,
                'category_id'   => $categoryId ?: null,
                'total_quantity' => (int)$totalQuantity,
                // A new item has no committed units yet. The workflow status
                // describes its condition, while availability is inventory.
                'available_quantity' => (int)$totalQuantity,
                'status'        => $status,
            ]);
            sendJson(201, ['success' => true, 'message' => 'Equipment added.', 'data' => ['equipment_id' => (int)$db->lastInsertId()]]);
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1452) sendJson(409, ['success' => false, 'message' => 'The selected category no longer exists. Choose another category.']);
            sendJson(409, ['success' => false, 'message' => 'Could not add equipment. The serial number may already be in use.']);
        }
        break;

    case 'PUT':
        requireRole(['admin']);
        $body = getJsonBody();
        $id = $body['equipment_id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'equipment_id is required.']);
        }
        $name = cleanText($body['equipment_name'] ?? '');
        $description = cleanText($body['description'] ?? '');
        $serialNumber = cleanText($body['serial_number'] ?? '');
        try { $categoryId = categoryAssignmentId($db, $body['category_id'] ?? null); }
        catch (InvalidArgumentException $e) { sendJson(400, ['success' => false, 'message' => $e->getMessage()]); }
        $status = $body['status'] ?? 'available';
        $borrowingLimit = $body['borrowing_time_limit_days'] ?? null;
        if ((!is_int($borrowingLimit) && (!is_string($borrowingLimit) || !ctype_digit($borrowingLimit))) || (int)$borrowingLimit < 1 || (int)$borrowingLimit > 3650) {
            sendJson(400, ['success' => false, 'message' => 'Borrowing time limit must be a whole number between 1 and 3650 days.']);
        }
        $totalQuantity = $body['total_quantity'] ?? null;
        $releaseQuantity = $body['release_quantity'] ?? 0;
        if ((!is_int($releaseQuantity) && (!is_string($releaseQuantity) || !ctype_digit($releaseQuantity))) || filter_var($releaseQuantity, FILTER_VALIDATE_INT) === false || (int)$releaseQuantity < 0 || (int)$releaseQuantity > 2147483647) {
            sendJson(400, ['success' => false, 'message' => 'release_quantity must be a nonnegative whole number.']);
        }
        $releaseQuantity = (int)$releaseQuantity;
        if ($releaseQuantity > 0 && $status !== 'available') sendJson(400, ['success' => false, 'message' => 'Cleared units must be marked available for reuse.']);
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'equipment_name is required.']);
        }
        if (filter_var($totalQuantity, FILTER_VALIDATE_INT) === false || (int)$totalQuantity < 1) {
            sendJson(400, ['success' => false, 'message' => 'total_quantity must be a positive whole number.']);
        }
        if (strlen($name) > 150 || strlen($description) > 500 || strlen($serialNumber) > 100) {
            sendJson(400, ['success' => false, 'message' => 'One or more equipment fields are too long.']);
        }
        if (!in_array($status, ['available', 'borrowed', 'maintenance', 'pending'], true)) {
            sendJson(400, ['success' => false, 'message' => 'Invalid equipment status.']);
        }
        try {
            $db->beginTransaction();
            $currentStmt = $db->prepare(
                'SELECT total_quantity, available_quantity
                 FROM equipment WHERE equipment_id = :id FOR UPDATE'
            );
            $currentStmt->execute(['id' => $id]);
            $current = $currentStmt->fetch();
            if (!$current) {
                $db->rollBack();
                sendJson(404, ['success' => false, 'message' => 'Equipment not found.']);
            }
            $unavailable = (int)$current['total_quantity'] - (int)$current['available_quantity'];
            if ($releaseQuantity > 0) {
                // The locked equipment row serializes this with reservation/restoration.
                $active = $db->prepare("SELECT COALESCE(SUM(requested_quantity),0) FROM borrowing_requests WHERE equipment_id = ? AND status IN ('pending','approved','borrowed')");
                $active->execute([$id]);
                $held = $unavailable - (int)$active->fetchColumn();
                if ($releaseQuantity > $held) {
                    $db->rollBack();
                    sendJson(409, ['success' => false, 'message' => 'Only condition-held units may be cleared; active reservations cannot be released.']);
                }
                $unavailable -= $releaseQuantity;
            }
            if ((int)$totalQuantity < $unavailable) {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => "Total quantity cannot be lower than the {$unavailable} units currently unavailable."]);
            }
            // Preserve both active reservations and condition-related holds.
            // A status change alone never clears unavailable stock.
            $available = (int)$totalQuantity - $unavailable;
            $stmt = $db->prepare(
                'UPDATE equipment
                 SET equipment_name = :name, description = :description, serial_number = :serial_number,
                     category_id = :category_id, total_quantity = :total_quantity,
                     available_quantity = :available_quantity, borrowing_time_limit_days = :borrowing_time_limit_days, status = :status
                 WHERE equipment_id = :id'
            );
            $stmt->execute([
                'borrowing_time_limit_days' => (int)$borrowingLimit,
                'name' => $name, 'description' => $description, 'serial_number' => $serialNumber ?: null,
                'category_id' => $categoryId ?: null, 'total_quantity' => (int)$totalQuantity,
                'available_quantity' => $available, 'status' => $status, 'id' => $id,
            ]);
            if ($releaseQuantity > 0) {
                $db->prepare("INSERT INTO equipment_condition_reports (equipment_id, reported_by_user_id, condition_status, notes) VALUES (?, ?, 'good', ?)")
                    ->execute([$id, currentUser()['user_id'], $releaseQuantity . ' units cleared for reuse by an administrator.']);
            }
            $db->commit();
            sendJson(200, ['success' => true, 'message' => 'Equipment updated.']);
        } catch (PDOException $e) {
            if ($db->inTransaction()) $db->rollBack();
            if ((int)($e->errorInfo[1] ?? 0) === 1452) sendJson(409, ['success' => false, 'message' => 'The selected category no longer exists. Choose another category.']);
            sendJson(409, ['success' => false, 'message' => 'Could not update equipment.']);
        }
        break;

    case 'DELETE':
        requireRole(['admin']);
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'id query param is required.']);
        }
        try {
            $db->beginTransaction();
            $lock = $db->prepare('SELECT equipment_id FROM equipment WHERE equipment_id = :id FOR UPDATE');
            $lock->execute(['id' => $id]);
            if (!$lock->fetch()) {
                $db->rollBack();
                sendJson(404, ['success' => false, 'message' => 'Equipment not found.']);
            }
            $history = $db->prepare('SELECT request_id FROM borrowing_requests WHERE equipment_id = :id LIMIT 1');
            $history->execute(['id' => $id]);
            if ($history->fetch()) {
                $db->rollBack();
                sendJson(409, ['success' => false, 'message' => 'Equipment with borrowing history cannot be deleted.', 'code' => 'BORROWING_HISTORY_EXISTS']);
            }
            $db->prepare('DELETE FROM equipment WHERE equipment_id = :id')->execute(['id' => $id]);
            $db->commit();
        } catch (PDOException $error) {
            if ($db->inTransaction()) $db->rollBack();
            sendJson(409, ['success' => false, 'message' => 'Could not delete equipment. Its existing records were preserved.', 'code' => 'EQUIPMENT_DELETE_CONFLICT']);
        }
        sendJson(200, ['success' => true, 'message' => 'Equipment deleted.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
