<?php
// The future audit table is modeled in SQLite memory only. No configured database DDL.
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/borrowing_history.php';
require __DIR__ . '/../includes/category_management.php';
class ManagementFixturePDO extends PDO
{
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        if (str_contains($query, 'information_schema.COLUMNS')) {
            preg_match("/TABLE_NAME = '([^']+)'/", $query, $match);
            $query = 'SELECT column_name,column_type FROM fixture_columns WHERE table_name=' . $this->quote($match[1]);
        } elseif (str_contains($query, 'information_schema.TABLES')) {
            $query = 'SELECT table_name,engine FROM fixture_engines';
        }
        return parent::query($query, $fetchMode ?? PDO::FETCH_DEFAULT, ...$args);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace([' FOR UPDATE', 'LEAST('], ['', 'MIN('], $query), $options);
    }
}
class ManagementResponse extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status, array $payload): void { throw new ManagementResponse($status, $payload); }
function getJsonBody(): array { return $GLOBALS['managementBody']; }
function cleanText(?string $value): string { return trim(strip_tags($value ?? '')); }
function checkManagement(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function managementApi(string $endpoint, string $method, array $body = [], array $query = [], ?int $actor = 2, string $sessionRole = 'admin'): ManagementResponse
{
    global $db;
    $_SESSION = $actor === null ? [] : ['user_id' => $actor, 'role' => $sessionRole, 'name' => 'Fixture', 'email' => 'fixture@example.invalid'];
    $_SERVER['REQUEST_METHOD'] = $method; $_GET = $query; $GLOBALS['managementBody'] = $body;
    $source = preg_replace('/^<\?php/', '', file_get_contents(__DIR__ . '/../api/' . $endpoint . '/index.php'));
    $source = preg_replace('/require_once[^;]+;/', '', $source);
    $source = str_replace(['const RETURN_SELECT', 'RETURN_SELECT', 'const EQUIPMENT_SELECT', 'EQUIPMENT_SELECT', 'const USER_SELECT', 'USER_SELECT'], ['$returnSelect', '$returnSelect', '$equipmentSelect', '$equipmentSelect', '$userSelect', '$userSelect'], $source);
    if (function_exists('findRoleId')) $source = preg_replace('/function findRoleId\([^)]*\): \?int\s*\{.*?\}/s', '', $source);
    try { eval($source); } catch (ManagementResponse $response) { return $response; }
    throw new RuntimeException('Missing response.');
}
$db = new ManagementFixturePDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->sqliteCreateFunction('CONCAT', fn(...$parts) => implode('', $parts));
$db->exec(<<<'SQL'
CREATE TABLE fixture_columns(table_name TEXT,column_name TEXT,column_type TEXT);
CREATE TABLE fixture_engines(table_name TEXT,engine TEXT);
CREATE TABLE roles(role_id INTEGER PRIMARY KEY,role_name TEXT);
CREATE TABLE users(user_id INTEGER PRIMARY KEY,role_id INTEGER,name TEXT,email TEXT);
CREATE TABLE categories(category_id INTEGER PRIMARY KEY,category_name TEXT);
CREATE TABLE equipment(equipment_id INTEGER PRIMARY KEY,equipment_name TEXT,total_quantity INTEGER,available_quantity INTEGER,borrowing_time_limit_days INTEGER,status TEXT,category_id INTEGER,description TEXT,serial_number TEXT);
CREATE TABLE borrowing_requests(request_id INTEGER PRIMARY KEY AUTOINCREMENT,user_id INTEGER,equipment_id INTEGER,requested_quantity INTEGER,borrow_date TEXT,expected_return_date TEXT,status TEXT,checkout_id INTEGER,request_date TEXT DEFAULT CURRENT_TIMESTAMP,picked_up_at TEXT,picked_up_by_staff_id INTEGER);
CREATE TABLE borrowing_status_history(history_id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER,from_status TEXT,to_status TEXT,changed_by_user_id INTEGER,changed_at TEXT,UNIQUE(request_id,to_status));
CREATE TABLE notifications(user_id INTEGER,message TEXT);
CREATE TABLE returns(return_id INTEGER PRIMARY KEY AUTOINCREMENT,request_id INTEGER UNIQUE,processed_by_staff_id INTEGER,returned_quantity INTEGER,remarks TEXT,actual_return_date TEXT);
CREATE TABLE equipment_condition_reports(equipment_id INTEGER,reported_by_user_id INTEGER,condition_status TEXT,notes TEXT);
INSERT INTO roles VALUES(1,'customer'),(2,'admin'),(3,'staff');
INSERT INTO users VALUES(1,1,'Customer','customer@example.invalid'),(2,2,'Admin','admin@example.invalid'),(3,3,'Staff','staff@example.invalid'),(4,1,'Other customer','other@example.invalid'),(5,2,'Other admin','admin2@example.invalid');
INSERT INTO categories VALUES(1,'Audio/Visual');
INSERT INTO equipment VALUES(10,'Camera',5,5,7,'available',1,'Camera kit','TEST-10'),(20,'Projector',4,4,7,'available',1,'Portable projector','TEST-20');
SQL);
foreach (['borrowing_status_history', 'borrowing_requests', 'equipment', 'users', 'roles', 'returns', 'equipment_condition_reports', 'notifications'] as $table) $db->prepare('INSERT INTO fixture_engines VALUES(?,?)')->execute([$table, 'InnoDB']);
foreach (['status' => "enum('pending','approved','borrowed','returned','rejected','cancelled')", 'picked_up_at' => 'datetime', 'picked_up_by_staff_id' => 'int'] as $column => $type) $db->prepare('INSERT INTO fixture_columns VALUES(?,?,?)')->execute(['borrowing_requests', $column, $type]);
function installFixtureAuditMetadata(): void
{
    global $db;
    foreach (['history_id' => 'int', 'request_id' => 'int', 'from_status' => 'varchar(20)', 'to_status' => 'varchar(20)', 'changed_by_user_id' => 'int', 'changed_at' => 'datetime'] as $column => $type) $db->prepare('INSERT INTO fixture_columns VALUES(?,?,?)')->execute(['borrowing_status_history', $column, $type]);
}
function newManagementRequest(int $quantity = 2, int $owner = 1, int $equipment = 10, ?int $checkout = null, int $offset = 0): int
{
    global $db;
    $today = new DateTimeImmutable(pickupDateWindow()['min_date'], borrowingTimezone());
    $db->beginTransaction();
    $rows = createBorrowingRequests($db, $owner, [['equipment_id' => $equipment, 'requested_quantity' => $quantity]], $today->modify("+$offset days"), $today->modify('+3 days'), $checkout);
    $db->commit();
    return $rows[0]['request_id'];
}
function managementStock(int $id = 10): int { global $db; return (int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id=' . $id)->fetchColumn(); }
function managementRow(int $id): array { global $db; return $db->query('SELECT * FROM borrowing_requests WHERE request_id=' . $id)->fetch(); }
function managementSnapshot(): array
{
    global $db;
    $snapshot = [];
    foreach (['equipment', 'borrowing_requests', 'borrowing_status_history', 'returns', 'equipment_condition_reports', 'notifications'] as $table) $snapshot[$table] = $db->query('SELECT * FROM ' . $table)->fetchAll();
    return $snapshot;
}
function managementChange(int $id, string $status, array $extra = []): ManagementResponse { return managementApi('requests', 'PUT', ['request_id' => $id, 'status' => $status] + $extra); }

$id = newManagementRequest();
checkManagement(managementStock() === 3, 'Pending did not reserve stock');
$before = managementSnapshot();
foreach ([null, 1] as $actor) {
    $response = managementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], $actor);
    checkManagement($response->status === ($actor === null ? 401 : 403), 'Unauthorized actor could manage a request');
}
checkManagement(managementSnapshot() === $before, 'Unauthorized actions changed records');
$response = managementChange($id, 'approved');
checkManagement($response->status === 503 && $response->payload['code'] === 'BORROWING_AUDIT_SCHEMA_REQUIRED', 'Missing audit table did not block administrator');
checkManagement(managementSnapshot() === $before, 'Schema gate changed status or stock');
$response = managementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], 3, 'staff');
checkManagement($response->status === 503 && $response->payload['code'] === 'BORROWING_AUDIT_SCHEMA_REQUIRED' && managementSnapshot() === $before, 'Staff bypassed audit requirement');
$caps = managementApi('requests', 'GET', [], ['capabilities' => 1]);
checkManagement(!$caps->payload['data']['management_available'] && !$caps->payload['data']['pickup_available'], 'Capabilities claimed incomplete management was ready');
checkManagement($caps->payload['data']['management_roles'] === ['admin', 'staff'], 'Capabilities omit staff management permission');
checkManagement(managementApi('requests', 'DELETE', [], ['id' => $id], 1, 'customer')->status === 200, 'Existing customer cancellation was blocked by missing audit storage');
checkManagement(managementStock() === 5 && managementRow($id)['status'] === 'cancelled', 'Customer cancellation did not restore stock and preserve record');
installFixtureAuditMetadata();
$id = newManagementRequest();
foreach ([['request_id' => []], ['request_id' => $id, 'status' => []], ['request_id' => $id, 'action' => []], ['request_id' => $id, 'status' => 'pending'], ['request_id' => $id, 'action' => 'pickup', 'status' => 'rejected']] as $invalid) checkManagement(managementApi('requests', 'PUT', $invalid)->status === 400, 'Invalid transition input accepted');
checkManagement(managementChange($id, 'borrowed')->status === 409, 'Pending request bypassed approval');
checkManagement(managementChange(2147483647, 'approved')->status === 404, 'Missing request accepted');
checkManagement(managementApi('requests', 'GET', [], [], 2147483647)->status === 401, 'Deleted actor retained access');
checkManagement(managementChange($id, 'approved', ['changed_by_user_id' => 3])->status === 200, 'Approval failed');
checkManagement(managementStock() === 3, 'Approval deducted reserved stock twice');
$history = $db->query('SELECT * FROM borrowing_status_history WHERE request_id=' . $id)->fetchAll();
checkManagement(count($history) === 1 && (int)$history[0]['changed_by_user_id'] === 2 && $history[0]['from_status'] === 'pending' && $history[0]['to_status'] === 'approved' && strlen($history[0]['changed_at']) === 19, 'Actor, previous status or timestamp audit is missing');
checkManagement(managementChange($id, 'approved')->status === 409, 'Repeated approval accepted');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id])->status === 409, 'Approved request bypassed physical pickup');
checkManagement(managementChange($id, 'cancelled')->status === 409 && managementChange($id, 'rejected')->status === 409, 'Approved request rejected or cancelled');
checkManagement(managementApi('requests', 'PUT', ['request_id' => $id, 'action' => 'pickup'])->status === 200, 'Pickup failed');
checkManagement(managementStock() === 3 && managementRow($id)['picked_up_by_staff_id'] === 2, 'Pickup deducted stock or lost attribution');
checkManagement(managementApi('equipment', 'PUT', ['equipment_id' => 10, 'equipment_name' => 'Camera', 'total_quantity' => 5, 'borrowing_time_limit_days' => 7, 'status' => 'available', 'release_quantity' => 1])->status === 409, 'Borrowed units were mistaken for condition-held quantities');
checkManagement(managementChange($id, 'borrowed')->status === 409, 'Repeated pickup accepted');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id, 'remarks' => [], 'condition_status' => 'good'])->status === 400, 'Nontext return remarks accepted');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id, 'condition_status' => []])->status === 400, 'Invalid return condition accepted');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id, 'remarks' => str_repeat('x', 501)])->status === 400, 'Oversized remarks accepted');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id, 'condition_status' => 'good', 'remarks' => 'Checked', 'returned_quantity' => 999, 'changed_by_user_id' => 3])->status === 201, 'Borrowed return failed');
checkManagement(managementStock() === 5, 'Return did not restore reserved stock');
$row = managementRow($id);
checkManagement($row['status'] === 'returned' && $row['picked_up_at'] !== null, 'Return lost pickup evidence');
checkManagement(managementApi('returns', 'POST', ['request_id' => $id])->status === 409 && managementStock() === 5, 'Repeated return restored stock twice');
checkManagement((int)$db->query('SELECT COUNT(*) FROM borrowing_status_history WHERE request_id=' . $id)->fetchColumn() === 3, 'Lifecycle audit events missing or duplicated');
$detail = managementApi('requests', 'GET', [], ['id' => $id], 1, 'customer');
checkManagement(count($detail->payload['data']['status_history']) === 3 && $detail->payload['data']['returned_quantity'] === 2 && $detail->payload['data']['customer']['email'] === 'customer@example.invalid' && $detail->payload['data']['equipment']['serial_number'] === 'TEST-10', 'Complete customer borrowing details missing');
checkManagement(managementApi('requests', 'GET', [], ['id' => $id], 4, 'customer')->status === 404, 'Another customer saw private details');
checkManagement(managementApi('requests', 'DELETE', [], ['id' => $id], 3, 'staff')->status === 403, 'Staff cancellation remained authorized');
// Staff use the same endpoints, transition validation, reservation rules, and persistent audits as admins.
$staffRequest = newManagementRequest();
$staffChange = fn(string $target, array $extra = []) => managementApi('requests', 'PUT', ['request_id' => $staffRequest, 'status' => $target] + $extra, [], 3, 'staff');
foreach (['borrowed', 'returned'] as $target) checkManagement($staffChange($target)->status === 409, 'Staff skipped approval');
checkManagement($staffChange('cancelled')->status === 403 && managementApi('requests', 'DELETE', [], ['id' => $staffRequest], 3, 'staff')->status === 403, 'Staff gained cancellation permission');
checkManagement($staffChange('approved', ['changed_by_user_id' => 2])->status === 200 && managementStock() === 3, 'Staff approval failed or reserved stock twice');
checkManagement(managementApi('returns', 'POST', ['request_id' => $staffRequest], [], 3, 'staff')->status === 409, 'Staff skipped pickup');
foreach (['approved', 'rejected'] as $target) checkManagement($staffChange($target)->status === 409, 'Staff repeated approval or rejected approved equipment');
checkManagement(managementApi('requests', 'PUT', ['request_id' => $staffRequest, 'action' => 'pickup', 'picked_up_by_staff_id' => 2], [], 3, 'staff')->status === 200 && managementStock() === 3, 'Staff pickup failed or deducted stock twice');
checkManagement(managementRow($staffRequest)['picked_up_by_staff_id'] === 3 && $staffChange('borrowed')->status === 409, 'Staff pickup attribution or duplicate protection failed');
checkManagement(managementApi('returns', 'POST', ['request_id' => $staffRequest, 'processed_by_staff_id' => 2, 'returned_quantity' => 999], [], 3, 'staff')->status === 201 && managementStock() === 5, 'Staff return failed or restored incorrect quantity');
checkManagement($staffChange('returned')->status === 409 && managementStock() === 5, 'Staff restored returned stock twice');
$staffReturn = $db->query('SELECT * FROM returns WHERE request_id=' . $staffRequest)->fetch();
checkManagement((int)$staffReturn['processed_by_staff_id'] === 3 && (int)$staffReturn['returned_quantity'] === 2, 'Client input overrode staff return attribution or quantity');
foreach (['admin' => 2, 'staff' => 3, 'customer' => 1] as $role => $actor) {
    $detail = managementApi('requests', 'GET', [], ['id' => $staffRequest], $actor, $role)->payload['data'];
    checkManagement($detail['status'] === 'returned' && count($detail['status_history']) === 3, 'Staff changes absent from ' . $role . ' history');
    foreach ($detail['status_history'] as $event) checkManagement($event['changed_by_user_id'] === 3 && strlen($event['changed_at']) === 19, 'Staff audit attribution missing');
}
$staffRejected = newManagementRequest();
checkManagement(managementApi('requests', 'PUT', ['request_id' => $staffRejected, 'action' => 'reject'], [], 3, 'staff')->status === 200 && managementStock() === 5, 'Staff rejection failed or lost reservation');
foreach (array_values(BORROWING_ACTION_STATUSES) as $target) checkManagement(managementApi('requests', 'PUT', ['request_id' => $staffRejected, 'status' => $target], [], 3, 'staff')->status === ($target === 'cancelled' ? 403 : 409), 'Staff changed a terminal request');
foreach (['rejected', 'cancelled'] as $target) {
    $request = newManagementRequest();
    checkManagement(managementChange($request, $target)->status === 200 && managementStock() === 5, 'Pending release failed: ' . $target);
    $snapshot = managementSnapshot();
    foreach (array_values(BORROWING_ACTION_STATUSES) as $attempt) checkManagement(managementChange($request, $attempt)->status === 409, 'Terminal request changed again');
    checkManagement(managementSnapshot() === $snapshot, 'Terminal record or stock was changed');
}
foreach (['damaged', 'missing', 'under_repair'] as $condition) {
    $request = newManagementRequest(1);
    managementChange($request, 'approved'); managementChange($request, 'borrowed');
    checkManagement(managementChange($request, 'returned', ['condition_status' => $condition])->status === 200 && managementStock() === 4, 'Condition-held stock was released: ' . $condition);
    checkManagement(managementChange($request, 'returned')->status === 409 && managementStock() === 4, 'Condition return repeated');
    // Test fixture reset only, representing separate inventories between scenarios.
    $db->exec("UPDATE equipment SET available_quantity=5,status='available' WHERE equipment_id=10");
}
$future = newManagementRequest(1, 1, 10, null, 1);
managementChange($future, 'approved');
checkManagement(managementChange($future, 'borrowed')->payload['code'] === 'PICKUP_NOT_DUE', 'Future pickup allowed');
$db->prepare('UPDATE borrowing_requests SET borrow_date=?,expected_return_date=? WHERE request_id=?')->execute(['2000-01-01', '2000-01-02', $future]);
checkManagement(managementChange($future, 'borrowed')->payload['code'] === 'PICKUP_WINDOW_EXPIRED', 'Expired pickup allowed');
// Full audit storage is required, including time precision and transactional engines.
$pending = newManagementRequest(1);
$db->exec("UPDATE fixture_columns SET column_type='date' WHERE table_name='borrowing_status_history' AND column_name='changed_at'");
checkManagement(managementChange($pending, 'approved')->status === 503, 'Date-only audit storage accepted');
$db->exec("UPDATE fixture_columns SET column_type='datetime' WHERE table_name='borrowing_status_history' AND column_name='changed_at'; UPDATE fixture_engines SET engine='MyISAM' WHERE table_name='notifications'");
checkManagement(managementChange($pending, 'approved')->status === 503, 'Nontransactional notification writes accepted');
$db->exec("UPDATE fixture_engines SET engine='InnoDB' WHERE table_name='notifications'");
// Inject audit failures at release, pickup, and return boundaries.
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON borrowing_status_history BEGIN SELECT RAISE(ABORT,'Private injected audit SQL'); END");
foreach (['approved', 'rejected'] as $target) {
    $snapshot = managementSnapshot();
    $failure = managementApi('requests', 'PUT', ['request_id' => $pending, 'status' => $target], [], 3, 'staff');
    checkManagement($failure->status === 500 && managementSnapshot() === $snapshot, 'Staff audit failure partially changed status or stock');
}
foreach (['approved', 'rejected', 'cancelled'] as $target) {
    $snapshot = managementSnapshot();
    $failure = managementChange($pending, $target);
    checkManagement($failure->status === 500 && !str_contains(json_encode($failure->payload), 'Private'), 'Audit failure leaked private SQL');
    checkManagement(managementSnapshot() === $snapshot, 'Audit failure partially changed stock or status');
}
$db->exec('DROP TRIGGER fail_audit');
managementChange($pending, 'approved');
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON borrowing_status_history BEGIN SELECT RAISE(ABORT,'audit failure'); END");
$snapshot = managementSnapshot();
checkManagement(managementChange($pending, 'borrowed')->status === 500 && managementSnapshot() === $snapshot, 'Failed pickup partially committed');
$db->exec('DROP TRIGGER fail_audit'); managementChange($pending, 'borrowed');
$db->exec("CREATE TRIGGER fail_audit BEFORE INSERT ON borrowing_status_history BEGIN SELECT RAISE(ABORT,'audit failure'); END");
$snapshot = managementSnapshot();
checkManagement(managementChange($pending, 'returned')->status === 500 && managementSnapshot() === $snapshot, 'Failed return committed status, stock, condition report or return record');
$db->exec('DROP TRIGGER fail_audit');
$db->exec("CREATE TRIGGER fail_notice BEFORE INSERT ON notifications BEGIN SELECT RAISE(ABORT,'notice failure'); END");
$snapshot = managementSnapshot();
checkManagement(managementChange($pending, 'returned')->status === 500 && managementSnapshot() === $snapshot, 'Notification failure did not roll back the audit and inventory');
$db->exec('DROP TRIGGER fail_notice'); managementChange($pending, 'returned');
// Actual database role revocation overrides the still-admin session.
$db->exec('UPDATE users SET role_id=1 WHERE user_id=2');
checkManagement(managementChange($pending, 'approved')->status === 403, 'Revoked admin retained borrowing authority');
$db->exec('UPDATE users SET role_id=2 WHERE user_id=2');
$db->exec('UPDATE users SET role_id=1 WHERE user_id=3');
checkManagement(managementApi('requests', 'PUT', ['request_id' => $staffRequest, 'status' => 'approved'], [], 3, 'staff')->status === 403, 'Revoked staff retained borrowing authority');
$db->exec('UPDATE users SET role_id=3 WHERE user_id=3');
$db->exec("UPDATE equipment SET available_quantity=5,status='available' WHERE equipment_id=10");
$one = newManagementRequest(1, 1, 10, 77);
$two = newManagementRequest(1, 1, 20, 77, 1);
managementChange($one, 'approved');
$group = managementApi('requests', 'GET', [], ['view' => 'transactions', 'status' => 'approved', 'limit' => 1]);
checkManagement($group->status === 200 && count($group->payload['data'][0]['items']) === 1 && $group->payload['data'][0]['status'] === 'approved', 'Admin status pagination included an unrelated checkout sibling');
checkManagement(managementApi('requests', 'GET', [], ['view' => 'transactions', 'id' => $two])->payload['data']['transaction_id'] === 'request:' . $two, 'Admin transaction identity does not match the independently updated request');
$activeLoan = newManagementRequest(1, 1, 20);
managementChange($activeLoan, 'approved');
managementChange($activeLoan, 'borrowed');
foreach (['admin' => 2, 'staff' => 3] as $role => $actor) {
    $all = managementApi('requests', 'GET', [], ['view' => 'transactions', 'limit' => 50], $actor, $role)->payload;
    $ids = array_column($all['data'], 'transaction_id');
    checkManagement(count($ids) === count(array_unique($ids)) && $all['total'] === count($ids), 'All Requests duplicated or omitted transactions');
    $count = 0;
    foreach (BORROWING_HISTORY_STATUSES as $status) {
        $filtered = managementApi('requests', 'GET', [], ['view' => 'transactions', 'status' => $status, 'limit' => 50], $actor, $role)->payload;
        checkManagement($filtered['total'] > 0, 'Missing fixture for status ' . $status);
        checkManagement($filtered['total'] === count($filtered['data']), 'Status total does not count independent requests');
        foreach ($filtered['data'] as $transaction) checkManagement($transaction['status'] === $status && $transaction['statuses'] === [$status] && count($transaction['items']) === 1 && $transaction['items'][0]['status'] === $status, 'Status tab contains another current status');
        $count += $filtered['total'];
    }
    checkManagement($count === $all['total'], 'Status tab totals do not partition All Requests');
    $checkoutRows = managementApi('requests', 'GET', [], ['view' => 'transactions', 'checkout_id' => 77], $actor, $role)->payload['data'];
    checkManagement(count($checkoutRows) === 2 && count(array_unique(array_column($checkoutRows, 'status'))) === 2, 'Independent checkout statuses were combined');
    foreach ($checkoutRows as $transaction) checkManagement(str_contains($transaction['transaction_reference'], 'Checkout #77'), 'Checkout reference was lost');
}
checkManagement(managementApi('requests', 'GET', [], ['view' => 'transactions', 'checkout_id' => 77], 4, 'customer')->payload['data'] === [], 'Customer grouped view exposed other owners');
checkManagement(managementApi('requests', 'GET', [], ['status' => 'Borrowed'])->status === 200, 'Status normalization failed');
foreach (['pending', 'approved', 'borrowed', 'returned', 'rejected', 'cancelled'] as $status) {
    $response = managementApi('requests', 'GET', [], ['status' => $status]);
    checkManagement($response->status === 200, 'Valid status filter rejected');
    foreach ($response->payload['data'] as $entry) checkManagement($entry['status'] === $status, 'Filter invented a status');
}
$snapshot = managementSnapshot();
checkManagement(managementApi('equipment', 'DELETE', [], ['id' => 10])->status === 409, 'Equipment deletion erased borrowing history');
checkManagement(managementSnapshot() === $snapshot, 'Blocked equipment deletion changed records');
checkManagement(managementApi('users', 'DELETE', [], ['id' => 1])->status === 409, 'Customer deletion erased borrowing history');
checkManagement(managementSnapshot() === $snapshot, 'Blocked customer deletion changed records');
checkManagement(managementApi('users', 'DELETE', [], ['id' => 2], 5)->status === 409, 'Audit actor deletion erased attribution');
$exception = new PDOException('Private deadlock'); $exception->errorInfo = ['40001', 1213];
checkManagement(borrowingDatabaseFailure($exception)[0] === 409 && borrowingDatabaseFailure($exception)[1]['code'] === 'RETRYABLE_CONFLICT', 'Concurrency error not mapped to retryable response');
echo "PASS: isolated audited lifecycle, live admin/staff authorization, schema gates, pending reservations, no double deductions/restorations, conditions, ownership/grouping, rollback on audit/notification failure, history preservation, and safe conflicts.\n";
