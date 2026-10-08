<?php
/** Canonical display names are also the input to the database's case-insensitive unique index. */
function normalizeCategoryName(mixed $value): string
{
    if (!is_string($value) || !preg_match('//u', $value)) {
        throw new InvalidArgumentException('Category name must be valid text.');
    }
    if (preg_match('/[<>\p{C}]/u', preg_replace('/[\t\n\r\f\v]/', '', $value))) {
        throw new InvalidArgumentException('Category name cannot contain markup or control characters.');
    }
    $name = trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value));
    if ($name === '') throw new InvalidArgumentException('Category name is required.');
    if (preg_match_all('/./us', $name) > 100) throw new InvalidArgumentException('Category name must be 100 characters or fewer.');
    if (preg_match('/[<>\p{C}]/u', $name) || !preg_match('/[\p{L}\p{N}]/u', $name)) {
        throw new InvalidArgumentException('Category name must contain a letter or number and cannot contain markup or control characters.');
    }
    return $name;
}

function categoryPayload(array $body): array
{
    $name = normalizeCategoryName($body['category_name'] ?? null);
    $description = $body['description'] ?? '';
    if (!is_string($description) || !preg_match('//u', $description)) throw new InvalidArgumentException('Description must be valid text.');
    $description = cleanText($description);
    if (preg_match_all('/./us', $description) > 500) throw new InvalidArgumentException('Description must be 500 characters or fewer.');
    return ['category_name' => $name, 'description' => $description];
}

function categoryId(mixed $value): int
{
    if ((!is_int($value) && (!is_string($value) || !ctype_digit($value)))
        || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1) {
        throw new InvalidArgumentException('Category ID must be a positive whole number.');
    }
    return (int)$value;
}

function categoryAssignmentId(PDO $db, mixed $value): ?int
{
    if ($value === null || $value === '') return null;
    $id = categoryId($value);
    $stmt = $db->prepare('SELECT category_id FROM categories WHERE category_id = ?');
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) throw new InvalidArgumentException('The selected category no longer exists. Choose another category.');
    return $id;
}

function allCategories(PDO $db): array
{
    return $db->query('SELECT c.category_id, c.category_name, c.description,
        (SELECT COUNT(*) FROM equipment e WHERE e.category_id = c.category_id) AS equipment_count
        FROM categories c ORDER BY c.category_name, c.category_id')->fetchAll();
}
