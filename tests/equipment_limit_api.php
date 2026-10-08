<?php
// Uses the configured local database; creates and removes one equipment fixture.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
class EquipmentApiResult extends Exception {
    public function __construct(public int $status, public array $payload) {}
}
function sendJson(int $status, array $payload): void { throw new EquipmentApiResult($status, $payload); }
function getJsonBody(): array { return $GLOBALS['testBody']; }
function cleanText(?string $value): string { return trim(strip_tags($value ?? '')); }
function equipmentApi(string $method, string $role, array $body = [], array $query = []): EquipmentApiResult {
    global $db;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SESSION = ['user_id' => 0, 'name' => 'Test', 'email' => 'test@example.invalid', 'role' => $role];
    $_GET = $query;
    $GLOBALS['testBody'] = $body;
    $source = file_get_contents(__DIR__ . '/../api/equipment/index.php');
    $source = preg_replace('/^<\?php\s*require_once[^;]+;/', '', $source);
    $source = str_replace('const EQUIPMENT_SELECT', '$equipmentSelect', $source);
    $source = str_replace('EQUIPMENT_SELECT', '$equipmentSelect', $source);
    try { eval($source); } catch (EquipmentApiResult $result) { return $result; }
    throw new Exception('Missing API response');
}
function verify(bool $condition, string $message): void {
    if (!$condition) throw new Exception($message);
}
$db = (new Database())->getConnection();
$id = null;
try {
    $body = ['equipment_name' => 'Borrowing limit regression fixture', 'total_quantity' => 3, 'borrowing_time_limit_days' => 5];
    $result = equipmentApi('POST', 'staff', $body);
    verify($result->status === 201, 'Staff create failed: ' . json_encode($result->payload));
    $id = $result->payload['data']['equipment_id'];
    $body['equipment_id'] = $id;
    // Represent two committed units; editing a limit must retain this availability.
    $db->prepare('UPDATE equipment SET available_quantity = 1 WHERE equipment_id = ?')->execute([$id]);
    foreach (['admin', 'staff'] as $role) {
        foreach ([1, 2, 5, 10, 3650, '1', '3650', '0005'] as $days) {
            $body['borrowing_time_limit_days'] = $days;
            $result = equipmentApi('PUT', $role, $body);
            verify($result->status === 200, $role . ' save failed for ' . $days);
            $result = equipmentApi('GET', $role, [], ['id' => $id]);
            verify($result->status === 200, 'Equipment reload failed');
            verify((int)$result->payload['data']['borrowing_time_limit_days'] === (int)$days, 'Saved limit mismatch');
            verify((int)$result->payload['data']['available_quantity'] === 1, 'Availability changed');
            verify((int)$result->payload['data']['total_quantity'] === 3, 'Total quantity changed');
        }
        foreach ([null, '', 0, -1, 1.5, 3651, '1.0', '1e2', true, false, [], 'abc'] as $invalid) {
            $body['borrowing_time_limit_days'] = $invalid;
            verify(equipmentApi('PUT', $role, $body)->status === 400, 'Invalid limit accepted');
            $result = equipmentApi('GET', $role, [], ['id' => $id]);
            verify((int)$result->payload['data']['borrowing_time_limit_days'] === 5, 'Invalid save changed stored value');
        }
    }
    verify(equipmentApi('PUT', 'customer', $body)->status === 403, 'Customer could edit equipment');
    echo "PASS: admin/staff database save and reload, boundaries, invalid inputs, authorization, and quantities.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($id) $db->prepare('DELETE FROM equipment WHERE equipment_id = ?')->execute([$id]);
}
