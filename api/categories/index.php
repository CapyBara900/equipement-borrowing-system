<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/category_management.php';

$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'GET') requireRole(['admin', 'customer']);
elseif (in_array($method, ['POST', 'PUT', 'DELETE'], true)) requireRole(['admin']);
else sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);

try {
    if ($method === 'GET') {
        if (isset($_GET['id'])) {
            $stmt = $db->prepare('SELECT * FROM categories WHERE category_id = ?');
            $stmt->execute([categoryId($_GET['id'])]);
            $category = $stmt->fetch();
            if (!$category) sendJson(404, ['success' => false, 'message' => 'Category not found.']);
            sendJson(200, ['success' => true, 'data' => $category]);
        }
        // Complete snapshot for dropdowns/live synchronization; keep paginated callers compatible.
        if (($_GET['all'] ?? '') === '1') sendJson(200, ['success' => true, 'data' => allCategories($db)]);
        $search = trim($_GET['search'] ?? '');
        $page = max(1, (int)($_GET['page'] ?? 1));
        $limit = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $sql = 'SELECT c.*, (SELECT COUNT(*) FROM equipment e WHERE e.category_id = c.category_id) AS equipment_count FROM categories c';
        if ($search !== '') $sql .= ' WHERE c.category_name LIKE :name_search OR c.description LIKE :description_search';
        $stmt = $db->prepare($sql . ' ORDER BY c.category_name, c.category_id LIMIT :limit OFFSET :offset');
        if ($search !== '') {
            $stmt->bindValue(':name_search', "%$search%");
            $stmt->bindValue(':description_search', "%$search%");
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $limit, PDO::PARAM_INT);
        $stmt->execute();
        sendJson(200, ['success' => true, 'data' => $stmt->fetchAll(), 'page' => $page, 'limit' => $limit]);
    }

    if ($method === 'POST' || $method === 'PUT') {
        $body = getJsonBody();
        $payload = categoryPayload($body);
        $id = $method === 'PUT' ? categoryId($body['category_id'] ?? null) : null;
        if ($id !== null) {
            $check = $db->prepare('SELECT category_id FROM categories WHERE category_id = ?');
            $check->execute([$id]);
            if (!$check->fetchColumn()) sendJson(404, ['success' => false, 'message' => 'Category not found.']);
        }
        if ($id === null) {
            $stmt = $db->prepare('INSERT INTO categories (category_name, description) VALUES (?, ?)');
            $stmt->execute([$payload['category_name'], $payload['description']]);
            $id = (int)$db->lastInsertId();
        } else {
            $stmt = $db->prepare('UPDATE categories SET category_name = ?, description = ? WHERE category_id = ?');
            $stmt->execute([$payload['category_name'], $payload['description'], $id]);
            // An unchanged name is a valid update, but a concurrently deleted row is not.
            $check->execute([$id]);
            if (!$check->fetchColumn()) sendJson(404, ['success' => false, 'message' => 'Category not found.']);
        }
        sendJson($method === 'POST' ? 201 : 200, ['success' => true,
            'message' => $method === 'POST' ? 'Category added.' : 'Category updated.',
            'data' => ['category_id' => $id] + $payload]);
    }

    $id = categoryId($_GET['id'] ?? null);
    $db->beginTransaction();
    // Lock the parent first. Foreign-key checks serialize concurrent equipment assignments.
    $stmt = $db->prepare('SELECT category_id FROM categories WHERE category_id = ? FOR UPDATE');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        $db->rollBack();
        sendJson(404, ['success' => false, 'message' => 'Category not found.']);
    }
    $assigned = $db->prepare('SELECT equipment_id FROM equipment WHERE category_id = ? LIMIT 1 FOR UPDATE');
    $assigned->execute([$id]);
    if ($assigned->fetchColumn()) {
        $db->rollBack();
        sendJson(409, ['success' => false, 'code' => 'CATEGORY_IN_USE',
            'message' => 'This category is assigned to equipment. Reassign all equipment to another category before deleting it.']);
    }
    $stmt = $db->prepare('DELETE FROM categories WHERE category_id = ?');
    $stmt->execute([$id]);
    $db->commit();
    sendJson(200, ['success' => true, 'message' => 'Category deleted.']);
} catch (InvalidArgumentException $e) {
    if ($db->inTransaction()) $db->rollBack();
    sendJson(400, ['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    if ($db->inTransaction()) $db->rollBack();
    $mysqlCode = (int)($e->errorInfo[1] ?? 0);
    if ($mysqlCode === 1062) sendJson(409, ['success' => false, 'code' => 'CATEGORY_DUPLICATE',
        'message' => 'A category with this name already exists. Capitalization and extra spaces do not make a different category.']);
    if ($mysqlCode === 1451) sendJson(409, ['success' => false, 'code' => 'CATEGORY_IN_USE',
        'message' => 'This category is assigned to equipment. Reassign all equipment to another category before deleting it.']);
    sendJson(500, ['success' => false, 'message' => 'Could not save or load categories. Please try again.']);
}
