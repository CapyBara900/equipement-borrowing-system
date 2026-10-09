<?php
// Real MySQL read/API verification. All fixture rows are rolled back; no schema changes.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/notifications.php';
require __DIR__ . '/../includes/borrowing_history.php';
class HistoryFailureStatement extends PDOStatement {
    protected function __construct() {}
    public function execute(?array $params = null): bool {
        if (str_contains($this->queryString, 'FROM borrowing_requests')) throw new PDOException('Injected database failure with private SQL');
        return parent::execute($params);
    }
}
class HistoryResponse extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status, array $payload): void { throw new HistoryResponse($status, $payload); }
function getJsonBody(): array { return $GLOBALS['historyBody']; }
function verifyHistory(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function historyApi(string $method = 'GET', array $query = [], ?string $role = 'customer', array $body = [], ?int $owner = null): HistoryResponse {
    global $db, $userId, $adminId, $staffId;
    $_SERVER['REQUEST_METHOD'] = $method; $_GET = $query; $GLOBALS['historyBody'] = $body;
    $_SESSION = $role === null ? [] : ['user_id' => $owner ?? ($role === 'admin' ? $adminId : ($role === 'staff' ? $staffId : $userId)), 'name' => 'History fixture', 'email' => 'fixture@example.invalid', 'role' => $role];
    $source = preg_replace('/^<\?php/', '', file_get_contents(__DIR__ . '/../api/requests/index.php'));
    $source = preg_replace('/require_once[^;]+;/', '', $source);
    try { eval($source); } catch (HistoryResponse $response) { return $response; }
    throw new RuntimeException('Missing JSON response.');
}
$db = (new Database())->getConnection();
$db->beginTransaction();
try {
    $users = [];
    foreach (['customer', 'customer', 'admin', 'staff'] as $roleName) {
        $lookup = $db->prepare('SELECT role_id FROM roles WHERE role_name=?'); $lookup->execute([$roleName]); $role = $lookup->fetchColumn();
        $db->prepare('INSERT INTO users(role_id,name,email,password_hash) VALUES(?,?,?,?)')->execute([$role, 'History fixture', uniqid('history-') . '@example.invalid', password_hash('FixturePassword123!', PASSWORD_DEFAULT)]);
        $users[] = (int)$db->lastInsertId();
    }
    [$userId, $other, $adminId, $staffId] = $users;
    $db->prepare('INSERT INTO equipment(equipment_name,total_quantity,available_quantity,borrowing_time_limit_days) VALUES(?,6,3,7)')->execute(['History fixture']);
    $equipmentId = (int)$db->lastInsertId();
    $today = new DateTimeImmutable(pickupDateWindow()['min_date'], borrowingTimezone());
    $date = $today->format('Y-m-d'); $return = $today->modify('+2 days')->format('Y-m-d');
    $db->prepare('INSERT INTO borrowing_checkouts(user_id,idempotency_key,payload_hash,borrow_date,expected_return_date) VALUES(?,?,?,?,?)')->execute([$userId, bin2hex(random_bytes(16)), str_repeat('a',64), $date, $return]);
    $checkout = (int)$db->lastInsertId();
    $ids = [];
    foreach (['pending', 'returned', 'approved', 'rejected', 'cancelled'] as $index => $status) {
        $pickup = $today->modify("+$index days")->format('Y-m-d');
        $db->prepare('INSERT INTO borrowing_requests(user_id,equipment_id,requested_quantity,borrow_date,expected_return_date,status,checkout_id,request_date) VALUES(?,?,?,?,?,?,?,?)')->execute([$userId,$equipmentId,$status === 'pending' ? 2 : 1,$pickup,$pickup,$status,$index < 2 ? $checkout : null,$date . ' 10:00:00']);
        $ids[$status] = (int)$db->lastInsertId();
    }
    $db->prepare("INSERT INTO borrowing_requests(user_id,equipment_id,requested_quantity,borrow_date,expected_return_date,status) VALUES(?,?,1,?,?,'pending')")->execute([$other,$equipmentId,$date,$date]);
    $foreignId = (int)$db->lastInsertId();
    $before = $db->query('SELECT total_quantity,available_quantity FROM equipment WHERE equipment_id=' . $equipmentId)->fetch();
    verifyHistory(historyApi('GET',[],null)->status === 401, 'Unauthenticated history was allowed');
    verifyHistory(historyApi('GET',[], 'unknown')->status === 403, 'Unknown role could read desk history');
    $all = historyApi('GET', ['user_id'=>$other]);
    verifyHistory($all->status === 200 && $all->payload['total'] === 5, 'Client-supplied owner affected authorization');
    verifyHistory(count($all->payload['data']) === 5, 'All history omitted terminal records');
    $expectedIds = array_values($ids); rsort($expectedIds);
    verifyHistory(array_column($all->payload['data'],'request_id') === $expectedIds, 'Equal timestamps were not ordered by ID descending');
    foreach ($all->payload['data'] as $row) {
        verifyHistory($row['user_id'] === $userId && is_int($row['requested_quantity']), 'Ownership or numeric JSON contract failed');
        foreach (['transaction_id','transaction_reference','equipment_id','equipment_name','borrow_date','expected_return_date','status','request_date','checkout_id','picked_up_at'] as $field) verifyHistory(array_key_exists($field,$row),'Missing field ' . $field);
        verifyHistory($row['picked_up_at'] === null, 'Pickup evidence was invented');
    }
    foreach (['pending','approved','borrowed','returned','rejected','cancelled'] as $status) {
        $filtered = historyApi('GET',['status'=>$status]);
        verifyHistory($filtered->status === 200,'Valid status was rejected');
        foreach ($filtered->payload['data'] as $row) verifyHistory($row['status'] === $status, 'Filter invented a status');
        if ($status === 'borrowed') verifyHistory($filtered->payload['data'] === [], 'Approved was treated as borrowed');
    }
    $first = historyApi('GET',['limit'=>2]); $second = historyApi('GET',['limit'=>2,'page'=>2]);
    verifyHistory($first->payload['has_more'] && !array_intersect(array_column($first->payload['data'],'request_id'),array_column($second->payload['data'],'request_id')), 'Pagination overlapped');
    verifyHistory(historyApi('GET',['id'=>$foreignId])->status === 404 && historyApi('GET',['id'=>2147483647])->status === 404, 'Foreign or missing detail was exposed');
    verifyHistory(historyApi('GET',['checkout_id'=>$checkout], 'customer', [], $other)->payload['data'] === [], 'Foreign checkout was exposed');
    $detail = historyApi('GET',['id'=>$ids['pending']]);
    verifyHistory($detail->payload['data']['transaction_id'] === 'checkout:' . $checkout, 'Checkout relationship was lost');
    $groups = historyApi('GET',['view'=>'transactions']);
    verifyHistory($groups->status === 200 && $groups->payload['total'] === 5 && count($groups->payload['data']) === 5, 'Customer requests were combined or omitted');
    $filtered = historyApi('GET',['view'=>'transactions','status'=>'pending','limit'=>1]);
    verifyHistory($filtered->payload['total'] === 1 && count($filtered->payload['data']) === 1 && count($filtered->payload['data'][0]['items']) === 1, 'Customer status filter included unrelated checkout siblings');
    verifyHistory($filtered->payload['data'][0]['status'] === 'pending' && $filtered->payload['data'][0]['statuses'] === ['pending'], 'Customer transaction did not have one current status');
    $groupDetail = historyApi('GET',['view'=>'transactions','id'=>$ids['pending']]);
    verifyHistory(count($groupDetail->payload['data']['items']) === 1 && $groupDetail->payload['data']['transaction_id'] === 'request:' . $ids['pending'] && $groupDetail->payload['data']['checkout_id'] === $checkout, 'Customer request detail lost its identity or checkout reference');
    verifyHistory(historyApi('GET',['view'=>'transactions','id'=>$foreignId])->status === 404, 'Foreign transaction detail was exposed');
    verifyHistory(historyApi('GET',['view'=>'transactions','checkout_id'=>$checkout], 'customer', [], $other)->payload['data'] === [], 'Foreign transaction checkout was exposed');
    $partitionTotal = 0;
    foreach (BORROWING_HISTORY_STATUSES as $status) {
        $tab = historyApi('GET',['view'=>'transactions','status'=>$status,'user_id'=>$other])->payload;
        verifyHistory($tab['total'] === count($tab['data']), 'Customer status count is incorrect');
        foreach ($tab['data'] as $transaction) verifyHistory($transaction['status'] === $status && count($transaction['items']) === 1 && $transaction['items'][0]['user_id'] === $userId, 'Customer status tab mixed statuses or exposed another owner');
        $partitionTotal += $tab['total'];
    }
    verifyHistory($partitionTotal === $groups->payload['total'], 'Customer status tabs do not partition All');
    $transactionFirst = historyApi('GET',['view'=>'transactions','limit'=>1,'checkout_id'=>$checkout])->payload;
    $transactionSecond = historyApi('GET',['view'=>'transactions','limit'=>1,'page'=>2,'checkout_id'=>$checkout])->payload;
    verifyHistory($transactionFirst['total'] === 2 && $transactionFirst['has_more'] && !$transactionSecond['has_more'] && $transactionFirst['data'][0]['transaction_id'] !== $transactionSecond['data'][0]['transaction_id'], 'Customer transaction pagination duplicated or lost checkout siblings');
    foreach (['admin', 'staff'] as $deskRole) {
        $desk = historyApi('GET',['view'=>'transactions','checkout_id'=>$checkout], $deskRole)->payload;
        verifyHistory($desk['total'] === 2 && count($desk['data']) === 2, 'Management transactions did not separate checkout requests');
        $deskFiltered = historyApi('GET',['view'=>'transactions','checkout_id'=>$checkout,'status'=>'pending'], $deskRole)->payload;
        verifyHistory($deskFiltered['total'] === 1 && $deskFiltered['data'][0]['status'] === 'pending' && count($deskFiltered['data'][0]['items']) === 1, 'Management status filter included returned checkout sibling');
    }
    verifyHistory(historyApi('GET',['view'=>'transactions','checkout_id'=>$checkout], 'staff')->status === 200, 'Existing staff read access was lost');
    verifyHistory(historyApi('GET',['status'=>'all'])->payload['total'] === 5, 'All filter failed');
    verifyHistory(historyApi('GET',['date_from'=>$date,'date_to'=>$date])->payload['total'] === 5, 'End of day date filter excluded records');
    foreach ([['status'=>'invented'],['status'=>[]],['id'=>0],['id'=>[]],['id'=>'1.5'],['checkout_id'=>-1],['page'=>'0'],['page'=>true],['page'=>'1e2'],['limit'=>51],['limit'=>[]],['date_from'=>'2026-02-30'],['date_to'=>[]],['date_from'=>'2026-10-10','date_to'=>'2026-10-09'],['id'=>1,'checkout_id'=>1],['view'=>'invalid'],['capabilities'=>'0']] as $invalid) verifyHistory(historyApi('GET',$invalid)->status === 400, 'Invalid query accepted: ' . json_encode($invalid));
    verifyHistory(historyApi('PUT',[], 'customer',['request_id'=>$ids['approved'],'action'=>'pickup'])->status === 403, 'Customer could mark pickup');
    verifyHistory(historyApi('PUT',[], 'admin',['request_id'=>[],'status'=>'approved'])->status === 400, 'Array mutation ID was accepted');
    verifyHistory(historyApi('DELETE',['id'=>[]])->status === 400, 'Array cancellation ID was accepted');
    $caps = historyApi('GET',['capabilities'=>1]);
    verifyHistory(historyApi('GET',['capabilities'=>1], 'staff')->payload['data']['management_roles'] === ['admin', 'staff'], 'Staff management capability missing');
    verifyHistory($before === $db->query('SELECT total_quantity,available_quantity FROM equipment WHERE equipment_id=' . $equipmentId)->fetch(), 'History or rejected actions changed inventory');
    $statementClass = $db->getAttribute(PDO::ATTR_STATEMENT_CLASS);
    $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, [HistoryFailureStatement::class]);
    try {
        $failure = historyApi();
        verifyHistory($failure->status === 500 && !str_contains(json_encode($failure->payload),'private SQL'), 'Database failure did not return a safe JSON error');
    } finally { $db->setAttribute(PDO::ATTR_STATEMENT_CLASS, $statementClass); }
    echo "PASS: real MySQL customer ownership, one current status per transaction, exact status partitions/counts, independent checkout requests, pagination, details, dates, authentication, and read-only stock.\n";
} finally { if ($db->inTransaction()) $db->rollBack(); if (isset($fixtureDb) && $fixtureDb->inTransaction()) $fixtureDb->rollBack(); }
