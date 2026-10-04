<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

const EQUIPMENT_SELECT = '
    SELECT e.equipment_id, e.equipment_name, e.description, e.serial_number, e.status,
           e.category_id, c.category_name, e.created_at
    FROM equipment e
    LEFT JOIN categories c ON c.category_id = e.category_id
';

$allowedSort = ['equipment_name', 'status', 'created_at'];

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        $user = requireLogin();

        // ----------------------------------------------------------------
        // Build the SELECT differently for customers vs admin/staff.
        //
        // • Customers receive a computed "display_status":
        //     - 'pending'     → their OWN pending request on this item
        //     - 'borrowed'    → their OWN approved request (item active)
        //     - 'unavailable' → another customer already holds pending/approved
        //     - actual status → everything else (available, maintenance, …)
        //   The real equipment.status is never mutated; 'unavailable' only
        //   exists in this query result, never in the database.
        //
        // • Admin / staff receive:
        //     - e.status (raw, unmodified — must stay 'pending' so the
        //       approval workflow can check and act on it correctly)
        //     - pending_request_id, pending_user_id, pending_user_name
        //       when a pending borrowing request exists for the item.
        // ----------------------------------------------------------------

        if ($user['role'] === 'customer') {
            // LEFT JOIN scoped to the CURRENT customer's own active requests.
            // br.user_id IS NULL  → no row matched → someone else (or nobody)
            //   has an active request → show 'unavailable' when equipment is
            //   pending/borrowed.
            // br.user_id IS NOT NULL → this customer owns the active request
            //   → show the real status ('pending' or 'borrowed').
            $baseSelect = "
                SELECT e.equipment_id, e.equipment_name, e.description,
                       e.serial_number,
                       CASE
                           WHEN e.status IN ('pending', 'borrowed') AND br.user_id IS NULL
                               THEN 'unavailable'
                           ELSE e.status
                       END AS status,
                       br.request_id AS my_request_id,
                       e.category_id, c.category_name, e.created_at
                FROM equipment e
                LEFT JOIN categories c ON c.category_id = e.category_id
                LEFT JOIN borrowing_requests br
                       ON br.equipment_id = e.equipment_id
                      AND br.user_id      = :current_user_id
                      AND br.status       IN ('pending', 'approved')
            ";
        } else {
            // Admin / staff: raw status + pending request info for management.
            // We join the MOST RECENT pending request so staff can see who
            // submitted it without visiting the separate Requests page.
            $baseSelect = "
                SELECT e.equipment_id, e.equipment_name, e.description,
                       e.serial_number, e.status,
                       br.request_id  AS pending_request_id,
                       br.user_id     AS pending_user_id,
                       u_req.name     AS pending_user_name,
                       e.category_id, c.category_name, e.created_at
                FROM equipment e
                LEFT JOIN categories c ON c.category_id = e.category_id
                LEFT JOIN borrowing_requests br
                       ON br.equipment_id = e.equipment_id
                      AND br.status       = 'pending'
                LEFT JOIN users u_req ON u_req.user_id = br.user_id
            ";
        }

        if (isset($_GET['id'])) {
            $stmt = $db->prepare($baseSelect . ' WHERE e.equipment_id = :id');
            $binds = ['id' => $_GET['id']];
            if ($user['role'] === 'customer') {
                $binds['current_user_id'] = $user['user_id'];
            }
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

        if ($user['role'] === 'customer') {
            $params['current_user_id'] = $user['user_id'];
        }

        if (!empty($_GET['search'])) {
            $conditions[] = '(e.equipment_name LIKE :search OR e.description LIKE :search OR e.serial_number LIKE :search)';
            $params['search'] = '%' . $_GET['search'] . '%';
        }
        if (!empty($_GET['category_id'])) {
            $conditions[] = 'e.category_id = :category_id';
            $params['category_id'] = $_GET['category_id'];
        }
        if (!empty($_GET['status'])) {
            if ($user['role'] === 'customer') {
                // Filter against the computed customer-facing status (may be 'unavailable').
                $conditions[] = "(CASE WHEN e.status IN ('pending', 'borrowed') AND br.user_id IS NULL THEN 'unavailable' ELSE e.status END) = :status";
            } else {
                // Admin/staff filter against the real database status.
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
        break;

    case 'POST':
        requireRole(['admin']);
        $body = getJsonBody();
        $name = cleanText($body['equipment_name'] ?? '');
        $description = cleanText($body['description'] ?? '');
        $serialNumber = cleanText($body['serial_number'] ?? '');
        $categoryId = $body['category_id'] ?? null;
        $status = $body['status'] ?? 'available';
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'equipment_name is required.']);
        }
        if (strlen($name) > 150 || strlen($description) > 500 || strlen($serialNumber) > 100) {
            sendJson(400, ['success' => false, 'message' => 'One or more equipment fields are too long.']);
        }
        if (!in_array($status, ['available', 'borrowed', 'maintenance', 'pending'], true)) {
            sendJson(400, ['success' => false, 'message' => 'Invalid equipment status.']);
        }
        try {
            $stmt = $db->prepare(
                'INSERT INTO equipment (equipment_name, description, serial_number, category_id, status)
                 VALUES (:name, :description, :serial_number, :category_id, :status)'
            );
            $stmt->execute([
                'name'          => $name,
                'description'   => $description,
                'serial_number' => $serialNumber ?: null,
                'category_id'   => $categoryId ?: null,
                'status'        => $status,
            ]);
            sendJson(201, ['success' => true, 'message' => 'Equipment added.', 'data' => ['equipment_id' => (int)$db->lastInsertId()]]);
        } catch (PDOException $e) {
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
        $categoryId = $body['category_id'] ?? null;
        $status = $body['status'] ?? 'available';
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'equipment_name is required.']);
        }
        if (strlen($name) > 150 || strlen($description) > 500 || strlen($serialNumber) > 100) {
            sendJson(400, ['success' => false, 'message' => 'One or more equipment fields are too long.']);
        }
        if (!in_array($status, ['available', 'borrowed', 'maintenance', 'pending'], true)) {
            sendJson(400, ['success' => false, 'message' => 'Invalid equipment status.']);
        }
        $stmt = $db->prepare(
            'UPDATE equipment
             SET equipment_name = :name, description = :description, serial_number = :serial_number,
                 category_id = :category_id, status = :status
             WHERE equipment_id = :id'
        );
        $stmt->execute([
            'name'          => $name,
            'description'   => $description,
            'serial_number' => $serialNumber ?: null,
            'category_id'   => $categoryId ?: null,
            'status'        => $status,
            'id'            => $id,
        ]);
        sendJson(200, ['success' => true, 'message' => 'Equipment updated.']);
        break;

    case 'DELETE':
        requireRole(['admin']);
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'id query param is required.']);
        }
        $stmt = $db->prepare('DELETE FROM equipment WHERE equipment_id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            sendJson(404, ['success' => false, 'message' => 'Equipment not found.']);
        }
        sendJson(200, ['success' => true, 'message' => 'Equipment deleted.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
