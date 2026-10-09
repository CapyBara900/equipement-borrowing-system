<?php
// Real MySQL integration with temporary rows only. Never applies DDL or migrations.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/borrowing_history.php';
class ManagementMysqlResponse extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status, array $payload): void { throw new ManagementMysqlResponse($status, $payload); }
function getJsonBody(): array { return $GLOBALS['mysqlManagementBody']; }
function cleanText(?string $value): string { return trim(strip_tags($value ?? '')); }
function verifyManagementMysql(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function mysqlManagementApi(string $endpoint, string $method, array $body = [], array $query = [], string $role = 'admin', ?int $actor = null): ManagementMysqlResponse
{
    global $db, $actors;
    $_SESSION = ['user_id' => $actor ?? $actors[$role], 'role' => $role, 'name' => 'Fixture', 'email' => 'fixture@example.invalid'];
    $_SERVER['REQUEST_METHOD'] = $method; $_GET = $query; $GLOBALS['mysqlManagementBody'] = $body;
    $source = preg_replace('/^<\?php/', '', file_get_contents(__DIR__ . '/../api/' . $endpoint . '/index.php'));
    $source = preg_replace('/require_once[^;]+;/', '', $source);
    $source = str_replace(['const RETURN_SELECT', 'RETURN_SELECT'], ['$returnSelect', '$returnSelect'], $source);
    try { eval($source); } catch (ManagementMysqlResponse $response) { return $response; }
    throw new RuntimeException('Missing API response.');
}
$db = (new Database())->getConnection(); $actors = []; $item = null; $checkout = null;
$audit = borrowingAuditSchema($db);
try {
    foreach (['customer', 'admin', 'staff'] as $role) {
        $lookup = $db->prepare('SELECT role_id FROM roles WHERE role_name=?'); $lookup->execute([$role]);
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$lookup->fetchColumn(), 'Borrowing management MySQL fixture', uniqid('management-') . '@example.invalid', password_hash('FixturePassword123!', PASSWORD_DEFAULT)]);
        $actors[$role] = (int)$db->lastInsertId();
    }
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,5,5,7)')->execute(['Borrowing management MySQL fixture']);
    $item = (int)$db->lastInsertId();
    $start = new DateTimeImmutable(pickupDateWindow()['min_date'], borrowingTimezone());
    $end = $start->modify('+3 days');
    $create = function(int $quantity = 2) use ($db, $actors, $item, $start, $end): int {
        $db->beginTransaction();
        $rows = createBorrowingRequests($db, $actors['customer'], [['equipment_id' => $item, 'requested_quantity' => $quantity]], $start, $end);
        $db->commit(); return $rows[0]['request_id'];
    };
    $stock = function() use ($db, $item): int { $stmt = $db->prepare('SELECT available_quantity FROM equipment WHERE equipment_id=?'); $stmt->execute([$item]); return (int)$stmt->fetchColumn(); };
    $id = $create();
    verifyManagementMysql($stock() === 3, 'Pending reservation changed');
    verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], 'customer')->status === 403, 'Customer performed admin action');
    verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], 'admin', $actors['customer'])->status === 403, 'Forged role performed management action');
    verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], 'staff', $actors['customer'])->status === 403, 'Forged staff session performed management action');
    verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'cancelled'], [], 'staff')->status === 403, 'Staff gained administrator cancellation permission');
    verifyManagementMysql(mysqlManagementApi('requests', 'DELETE', [], ['id' => $id], 'staff')->status === 403, 'Staff gained DELETE cancellation permission');
    $caps = mysqlManagementApi('requests', 'GET', [], ['capabilities' => 1])->payload['data'];
    verifyManagementMysql($caps['audit_available'] === $audit['audit_available'], 'Audit readiness inconsistent');
    verifyManagementMysql($caps['management_roles'] === ['admin', 'staff'], 'Capabilities omit staff');
    if (!$audit['audit_available']) {
        foreach (['approved', 'rejected', 'cancelled'] as $target) {
            $response = mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => $target]);
            verifyManagementMysql($response->status === 503 && $response->payload['code'] === 'BORROWING_AUDIT_SCHEMA_REQUIRED' && $stock() === 3, 'Missing audit storage did not safely gate ' . $target);
        }
        verifyManagementMysql(mysqlManagementApi('requests', 'DELETE', [], ['id' => $id])->status === 503, 'DELETE bypassed admin audit requirement');
        verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved'], [], 'staff')->status === 503 && $stock() === 3, 'Staff bypassed audit requirement');
        verifyManagementMysql(mysqlManagementApi('requests', 'GET', [], ['id' => $id], 'customer')->payload['data']['status'] === 'pending', 'Gated action changed customer status');
        verifyManagementMysql(mysqlManagementApi('requests', 'DELETE', [], ['id' => $id], 'customer')->status === 200 && $stock() === 5, 'Pending customer cancellation stopped working');
    } else {
        foreach (['admin', 'staff'] as $role) {
            if ($role === 'staff') $id = $create();
            verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'action' => 'pickup'], [], $role)->status === 409 && $stock() === 3, $role . ' bypassed approval');
            verifyManagementMysql(mysqlManagementApi('returns', 'POST', ['request_id' => $id], [], $role)->status === 409 && $stock() === 3, $role . ' returned pending equipment');
            verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'approved', 'changed_by_user_id' => $actors['customer']], [], $role)->status === 200 && $stock() === 3, $role . ' approval failed or deducted twice');
            verifyManagementMysql(mysqlManagementApi('returns', 'POST', ['request_id' => $id], [], $role)->status === 409, $role . ' approval bypassed Borrowed');
            verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'status' => 'rejected'], [], $role)->status === 409, $role . ' rejected approved equipment');
            verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $id, 'action' => 'pickup'], [], $role)->status === 200 && $stock() === 3, $role . ' pickup failed or deducted twice');
            verifyManagementMysql(mysqlManagementApi('returns', 'POST', ['request_id' => $id, 'processed_by_staff_id' => $actors['customer'], 'returned_quantity' => 999], [], $role)->status === 201 && $stock() === 5, $role . ' borrowed return failed');
            verifyManagementMysql(mysqlManagementApi('returns', 'POST', ['request_id' => $id], [], $role)->status === 409 && $stock() === 5, $role . ' restored returned stock twice');
            foreach (['customer', 'admin', 'staff'] as $reader) {
                $detail = mysqlManagementApi('requests', 'GET', [], ['id' => $id], $reader)->payload['data'];
                $history = $detail['status_history'];
                verifyManagementMysql($detail['status'] === 'returned' && count($history) === 3 && array_column($history, 'to_status') === ['approved', 'borrowed', 'returned'], $reader . ' audit history incomplete');
                verifyManagementMysql($detail['picked_up_by_staff_id'] === $actors[$role] && $detail['processed_by_staff_id'] === $actors[$role] && $detail['returned_quantity'] === 2, 'Pickup or return attribution/quantity lost');
                foreach ($history as $event) verifyManagementMysql($event['changed_by_user_id'] === $actors[$role] && strlen($event['changed_at']) >= 19, 'Audit attribution lost');
            }
        }
        $rejected = $create();
        verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $rejected, 'action' => 'reject'], [], 'staff')->status === 200 && $stock() === 5, 'Staff rejection failed or did not release reservation');
        verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $rejected, 'action' => 'approve'], [], 'staff')->status === 409 && $stock() === 5, 'Staff revived rejected request');
    }
    verifyManagementMysql(mysqlManagementApi('requests', 'DELETE', [], ['id' => $id], 'customer')->status === 409 && $stock() === 5, 'Terminal request restored stock again');
    $db->prepare('INSERT INTO borrowing_checkouts(user_id,idempotency_key,payload_hash,borrow_date,expected_return_date) VALUES(?,?,?,?,?)')->execute([$actors['customer'], bin2hex(random_bytes(16)), str_repeat('a', 64), $start->format('Y-m-d'), $end->format('Y-m-d')]);
    $checkout = (int)$db->lastInsertId();
    $db->beginTransaction();
    $group = createBorrowingRequests($db, $actors['customer'], [
        ['equipment_id' => $item, 'requested_quantity' => 1, 'borrow_date' => $start->format('Y-m-d'), 'expected_return_date' => $end->format('Y-m-d')],
        ['equipment_id' => $item, 'requested_quantity' => 2, 'borrow_date' => $start->modify('+1 day')->format('Y-m-d'), 'expected_return_date' => $end->format('Y-m-d')],
    ], null, null, $checkout);
    $db->commit();
    $grouped = mysqlManagementApi('requests', 'GET', [], ['view' => 'transactions', 'checkout_id' => $checkout, 'limit' => 1])->payload;
    verifyManagementMysql($grouped['total'] === 2 && $grouped['has_more'] && count($grouped['data'][0]['items']) === 1, 'MySQL management pagination did not count independent requests');
    $secondPage = mysqlManagementApi('requests', 'GET', [], ['view' => 'transactions', 'checkout_id' => $checkout, 'limit' => 1, 'page' => 2])->payload;
    verifyManagementMysql(!$secondPage['has_more'] && $secondPage['data'][0]['transaction_id'] !== $grouped['data'][0]['transaction_id'], 'Management pagination duplicated or lost a request');
    $allItems = array_merge($grouped['data'][0]['items'], $secondPage['data'][0]['items']);
    verifyManagementMysql(array_sum(array_column($allItems, 'requested_quantity')) === 3 && count(array_unique(array_column($allItems, 'borrow_date'))) === 2, 'Request quantities/dates changed');
    if ($audit['audit_available']) {
        verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $group[0]['request_id'], 'action' => 'approve'], [], 'staff')->status === 200 && $stock() === 2, 'Approval changed reserved quantities');
        foreach (['admin', 'staff'] as $reader) {
            foreach (['pending' => $group[1]['request_id'], 'approved' => $group[0]['request_id']] as $status => $expectedId) {
                $filtered = mysqlManagementApi('requests', 'GET', [], ['view' => 'transactions', 'checkout_id' => $checkout, 'status' => $status], $reader)->payload;
                verifyManagementMysql($filtered['total'] === 1 && $filtered['data'][0]['transaction_id'] === 'request:' . $expectedId && $filtered['data'][0]['status'] === $status && count($filtered['data'][0]['items']) === 1, 'MySQL status filter leaked checkout siblings');
            }
        }
        verifyManagementMysql(mysqlManagementApi('requests', 'PUT', ['request_id' => $group[0]['request_id'], 'action' => 'pickup'], [], 'staff')->status === 200, 'Pickup failed');
        verifyManagementMysql(mysqlManagementApi('returns', 'POST', ['request_id' => $group[0]['request_id']], [], 'staff')->status === 201, 'Return failed');
    }
    verifyManagementMysql(mysqlManagementApi('requests', 'GET', [], ['view' => 'transactions', 'id' => $group[0]['request_id']])->payload['data']['checkout_id'] === $checkout, 'Admin group detail used admin ownership');
    foreach ($group as $index => $request) {
        if ($audit['audit_available'] && $index === 0) continue;
        verifyManagementMysql(mysqlManagementApi('requests', 'DELETE', [], ['id' => $request['request_id']], 'customer')->status === 200, 'Customer item cancellation failed');
    }
    verifyManagementMysql($stock() === 5, 'Grouped cancellations did not restore once');
    $empty = mysqlManagementApi('requests', 'GET', [], ['checkout_id' => $checkout, 'status' => 'borrowed'], 'customer');
    verifyManagementMysql($empty->payload['data'] === [], 'Cancelled records relabeled borrowed');
    $db->beginTransaction();
    try {
        createBorrowingRequests($db, $actors['customer'], [['equipment_id' => $item, 'requested_quantity' => 6]], $start, $end);
        throw new RuntimeException('Overselling was allowed');
    } catch (BorrowingFailure $failure) {
        verifyManagementMysql($failure->httpStatus === 409 && $failure->errorCode === 'INSUFFICIENT_STOCK', 'Stock error contract changed');
    } finally { $db->rollBack(); }
    verifyManagementMysql($stock() === 5, 'Insufficient stock failure reserved units');
    echo $audit['audit_available']
        ? "PASS: real MySQL audited lifecycle, role checks, grouped history, quantities, ownership and terminal protection.\n"
        : "PASS: real MySQL audit schema gate, admin/staff permissions, customer cancellation, grouped history and unchanged reservation rules. Full lifecycle awaits audit storage.\n";
} finally {
    if ($db->inTransaction()) $db->rollBack();
    if ($item !== null) {
        if ($audit['audit_available']) $db->prepare('DELETE FROM borrowing_status_history WHERE request_id IN (SELECT request_id FROM borrowing_requests WHERE equipment_id=?)')->execute([$item]);
        $db->prepare('DELETE FROM returns WHERE request_id IN (SELECT request_id FROM borrowing_requests WHERE equipment_id=?)')->execute([$item]);
        $db->prepare('DELETE FROM equipment_condition_reports WHERE equipment_id=?')->execute([$item]);
        $db->prepare('DELETE FROM borrowing_requests WHERE equipment_id=?')->execute([$item]);
        if ($checkout !== null) $db->prepare('DELETE FROM borrowing_checkouts WHERE checkout_id=?')->execute([$checkout]);
        $db->prepare('DELETE FROM equipment WHERE equipment_id=? AND equipment_name=?')->execute([$item, 'Borrowing management MySQL fixture']);
    }
    foreach ($actors as $actor) $db->prepare('DELETE FROM users WHERE user_id=? AND name=?')->execute([$actor, 'Borrowing management MySQL fixture']);
}
