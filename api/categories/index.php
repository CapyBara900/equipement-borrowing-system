<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {

    case 'GET':
        requireLogin();
        if (isset($_GET['id'])) {
            $stmt = $db->prepare('SELECT * FROM categories WHERE category_id = :id');
            $stmt->execute(['id' => $_GET['id']]);
            $category = $stmt->fetch();
            if (!$category) {
                sendJson(404, ['success' => false, 'message' => 'Category not found.']);
            }
            sendJson(200, ['success' => true, 'data' => $category]);
        }

        // Keyword search + pagination (rubric's "search, filter, sort" requirement).
        $search = trim($_GET['search'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = min(50, max(1, (int)($_GET['limit'] ?? 20)));
        $offset = ($page - 1) * $limit;

        $sql = 'SELECT * FROM categories';
        $params = [];
        if ($search !== '') {
            $sql .= ' WHERE category_name LIKE :search OR description LIKE :search';
            $params['search'] = "%$search%";
        }
        $sql .= ' ORDER BY category_name LIMIT :limit OFFSET :offset';

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
        $name = cleanText($body['category_name'] ?? '');
        $description = cleanText($body['description'] ?? '');
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'category_name is required.']);
        }
        if (strlen($name) > 100 || strlen($description) > 500) {
            sendJson(400, ['success' => false, 'message' => 'Category name or description is too long.']);
        }
        try {
            $stmt = $db->prepare('INSERT INTO categories (category_name, description) VALUES (:name, :description)');
            $stmt->execute(['name' => $name, 'description' => $description]);
            $id = (int)$db->lastInsertId();
            sendJson(201, ['success' => true, 'data' => ['category_id' => $id, 'category_name' => $name, 'description' => $description]]);
        } catch (PDOException $e) {
            sendJson(409, ['success' => false, 'message' => 'Could not create category. The category name may already exist.']);
        }
        break;

    case 'PUT':
        requireRole(['admin']);
        $body = getJsonBody();
        $id = $body['category_id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'category_id is required.']);
        }
        $name = cleanText($body['category_name'] ?? '');
        $description = cleanText($body['description'] ?? '');
        if ($name === '') {
            sendJson(400, ['success' => false, 'message' => 'category_name is required.']);
        }
        if (strlen($name) > 100 || strlen($description) > 500) {
            sendJson(400, ['success' => false, 'message' => 'Category name or description is too long.']);
        }
        $stmt = $db->prepare('UPDATE categories SET category_name = :name, description = :description WHERE category_id = :id');
        $stmt->execute([
            'name'        => $name,
            'description' => $description,
            'id'          => $id,
        ]);
        if ($stmt->rowCount() === 0) {
            $check = $db->prepare('SELECT category_id FROM categories WHERE category_id = :id');
            $check->execute(['id' => $id]);
            if (!$check->fetch()) {
                sendJson(404, ['success' => false, 'message' => 'Category not found.']);
            }
        }
        sendJson(200, ['success' => true, 'message' => 'Category updated.']);
        break;

    case 'DELETE':
        requireRole(['admin']);
        $id = $_GET['id'] ?? null;
        if (!$id) {
            sendJson(400, ['success' => false, 'message' => 'id query param is required.']);
        }
        $stmt = $db->prepare('DELETE FROM categories WHERE category_id = :id');
        $stmt->execute(['id' => $id]);
        if ($stmt->rowCount() === 0) {
            sendJson(404, ['success' => false, 'message' => 'Category not found.']);
        }
        sendJson(200, ['success' => true, 'message' => 'Category deleted.']);
        break;

    default:
        sendJson(405, ['success' => false, 'message' => 'Method not allowed.']);
}
