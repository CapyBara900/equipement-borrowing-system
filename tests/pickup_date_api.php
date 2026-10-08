<?php
// Uses the configured local database and removes its test records afterward.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/borrowing_submission.php';
class PickupApiResult extends Exception {
    public function __construct(public int $status, public array $payload) {}
}
function sendJson(int $status, array $payload): void { throw new PickupApiResult($status, $payload); }
function getJsonBody(): array { return $GLOBALS['testBody']; }
function verify(bool $condition, string $message): void { if (!$condition) throw new Exception($message); }
function requestsApi(array $body): PickupApiResult {
    global $db, $userId;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_SESSION = ['user_id' => $userId, 'name' => 'Test', 'email' => 'test@example.invalid', 'role' => 'customer'];
    $GLOBALS['testBody'] = $body;
    $source = file_get_contents(__DIR__ . '/../api/requests/index.php');
    $source = preg_replace('/^<\?php/', '', $source);
    $source = preg_replace('/require_once[^;]+;/', '', $source);
    $source = str_replace('const REQUEST_SELECT', '$requestSelect', $source);
    $source = str_replace('REQUEST_SELECT', '$requestSelect', $source);
    try { eval($source); } catch (PickupApiResult $result) { return $result; }
    throw new Exception('Missing response');
}
$originalTimezone = getenv('APP_TIMEZONE');
try {
    putenv('APP_TIMEZONE=Asia/Manila');
    $window = pickupDateWindow(new DateTimeImmutable('2026-10-07T16:30:00Z'));
    verify($window['min_date'] === '2026-10-08' && $window['max_date'] === '2026-10-15', 'Manila midnight boundary');
    putenv('APP_TIMEZONE=America/Los_Angeles');
    $window = pickupDateWindow(new DateTimeImmutable('2026-10-07T16:30:00Z'));
    verify($window['min_date'] === '2026-10-07' && $window['max_date'] === '2026-10-14', 'Configured timezone must control date');
    putenv('APP_TIMEZONE=America/New_York');
    $window = pickupDateWindow(new DateTimeImmutable('2026-03-07T06:00:00Z'));
    verify($window['min_date'] === '2026-03-07' && $window['max_date'] === '2026-03-14', 'DST uses calendar days');
    putenv('APP_TIMEZONE=Asia/Manila');
    $window = pickupDateWindow(new DateTimeImmutable('2026-12-30T06:00:00Z'));
    verify($window['max_date'] === '2027-01-06', 'Year boundary');
} finally {
    putenv($originalTimezone === false ? 'APP_TIMEZONE' : 'APP_TIMEZONE=' . $originalTimezone);
}
$db = (new Database())->getConnection();
$userId = null; $equipmentId = null;
try {
    $roleId = $db->query("SELECT role_id FROM roles WHERE role_name = 'customer'")->fetchColumn();
    $db->prepare('INSERT INTO users (role_id, name, email, password_hash) VALUES (?, ?, ?, ?)')->execute([$roleId, 'Pickup regression fixture', uniqid('pickup-') . '@example.invalid', password_hash('TemporaryTest123!', PASSWORD_DEFAULT)]);
    $userId = (int)$db->lastInsertId();
    $db->prepare('INSERT INTO equipment (equipment_name, total_quantity, available_quantity, borrowing_time_limit_days) VALUES (?, 20, 20, 5)')->execute(['Pickup regression fixture']);
    $equipmentId = (int)$db->lastInsertId();
    $today = new DateTimeImmutable(pickupDateWindow()['min_date'], borrowingTimezone());
    foreach ([-1, 0, 1, 2, 3, 4, 5, 6, 7, 8] as $offset) {
        $pickup = $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $before = (int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id = ' . $equipmentId)->fetchColumn();
        $body = ['equipment_id' => $equipmentId, 'borrow_date' => $pickup->format('Y-m-d'), 'expected_return_date' => $pickup->modify('+5 days')->format('Y-m-d'), 'requested_quantity' => 1];
        $result = requestsApi($body);
        $valid = $offset >= 0 && $offset <= 7;
        verify($result->status === ($valid ? 201 : 400), 'Pickup offset ' . $offset . ': ' . json_encode($result->payload));
        $after = (int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id = ' . $equipmentId)->fetchColumn();
        verify($after === $before - ($valid ? 1 : 0), 'Inventory changed incorrectly for pickup offset ' . $offset);
        if (!$valid) verify(str_contains($result->payload['message'], '7 calendar days'), 'Missing clear pickup error');
    }
    $body['borrow_date'] = $today->format('Y-m-d');
    $body['expected_return_date'] = $today->modify('+6 days')->format('Y-m-d');
    verify(requestsApi($body)->status === 400, 'Equipment return limit was bypassed');
    $body['borrow_date'] = '2026-02-30';
    verify(requestsApi($body)->status === 400, 'Invalid calendar date accepted');
    echo "PASS: timezone configuration, midnight/DST/year boundaries, all pickup offsets, backend bypass prevention, return limit, and stock preservation.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($equipmentId) $db->prepare('DELETE FROM equipment WHERE equipment_id = ?')->execute([$equipmentId]);
    if ($userId) $db->prepare('DELETE FROM users WHERE user_id = ?')->execute([$userId]);
}
