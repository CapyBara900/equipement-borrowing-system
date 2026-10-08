<?php
// SQLite is an isolated test fixture only; application SQL targets MySQL/InnoDB.
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/auth_middleware.php';
require __DIR__ . '/../includes/borrowing_cart.php';
function check(bool $ok, string $message): void { if (!$ok) throw new RuntimeException($message); }
function fails(callable $fn, int $status): void { try { $fn(); } catch (BorrowingFailure $e) { check($e->httpStatus === $status, $e->getMessage()); return; } throw new RuntimeException('Expected failure'); }
class CartTestPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE', '', $query), $options); }
}
$db = new CartTestPDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE users (user_id INTEGER PRIMARY KEY); CREATE TABLE categories (category_id INTEGER PRIMARY KEY, category_name TEXT); CREATE TABLE equipment (equipment_id INTEGER PRIMARY KEY, equipment_name TEXT, description TEXT, serial_number TEXT, category_id INTEGER, available_quantity INTEGER, borrowing_time_limit_days INTEGER); CREATE TABLE borrowing_requests (request_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, equipment_id INTEGER, borrow_date TEXT, expected_return_date TEXT, requested_quantity INTEGER, status TEXT, checkout_id INTEGER); CREATE TABLE borrowing_cart_items (cart_item_id INTEGER PRIMARY KEY AUTOINCREMENT, borrow_date TEXT, expected_return_date TEXT, user_id INTEGER, equipment_id INTEGER, quantity INTEGER CHECK(quantity > 0), created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id, equipment_id, borrow_date, expected_return_date)); CREATE TABLE borrowing_checkouts (checkout_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, idempotency_key TEXT, payload_hash TEXT, response_json TEXT, borrow_date TEXT, expected_return_date TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, UNIQUE(user_id,idempotency_key)); INSERT INTO users VALUES(1),(2); INSERT INTO equipment VALUES(10,"Camera",NULL,NULL,NULL,5,3),(20,"Tripod",NULL,NULL,NULL,2,1);');
foreach ([true, false, 0, -1, 1.5, '1.5', [], '2147483648'] as $value) fails(fn() => borrowingPositiveInt($value, 'quantity'), 400);
$today = new DateTimeImmutable(pickupDateWindow()['min_date'], borrowingTimezone());
$date = $today->format('Y-m-d');
$return = $today->modify('+1 day')->format('Y-m-d');
foreach ([-1,8] as $offset) fails(fn() => validateBorrowingDates($today->modify("$offset days")->format('Y-m-d'), $return, true), 400);
fails(fn() => validateBorrowingDates($date, $date, true), 400);
fails(fn() => borrowingDate('2026-02-30'), 400);
fails(fn() => validateBorrowingDates($date, $today->modify('-1 day')->format('Y-m-d'), true), 400);
$details=['borrow_date'=>$date,'expected_return_date'=>$return];
mutateBorrowingCart($db, 1, 'POST', ['equipment_id'=>10,'quantity'=>2]+$details);
mutateBorrowingCart($db, 1, 'POST', ['equipment_id'=>10,'quantity'=>1]+$details);
check(readBorrowingCart($db,1)[0]['quantity'] === 3, 'Duplicates must increment quantity');
check((int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id=10')->fetchColumn() === 5, 'Adding to cart reserved stock');
check(readBorrowingCart($db,2) === [], 'Other customer could read cart');
fails(fn()=>mutateBorrowingCart($db,2,'DELETE',['cart_item_id'=>1]),404);
check(count(readBorrowingCart($db,1)) === 1, 'Other customer could remove cart');
fails(fn()=>mutateBorrowingCart($db,1,'PUT',['cart_item_id'=>1,'quantity'=>6]+$details),409);
check(readBorrowingCart($db,1)[0]['quantity'] === 3, 'Failed update changed cart');
mutateBorrowingCart($db,1,'POST',['equipment_id'=>20,'quantity'=>2]+$details);
$body=['idempotency_key'=>'test_checkout_key_01','borrow_date'=>$date,'expected_return_date'=>$return,'items'=>[['cart_item_id'=>1,'equipment_id'=>10,'requested_quantity'=>2]+$details]];
$bad=$body; $bad['items'][]=['cart_item_id'=>2,'equipment_id'=>20,'requested_quantity'=>2]+$details; $bad['items'][1]['expected_return_date']=$today->modify('+2 days')->format('Y-m-d');
$before=readBorrowingCart($db,1);
fails(fn()=>checkoutBorrowingCart($db,1,$bad),409);
check(readBorrowingCart($db,1)===$before && (int)$db->query('SELECT COUNT(*) FROM borrowing_requests')->fetchColumn()===0, 'Failed batch partially submitted');
check((int)$db->query('SELECT COUNT(*) FROM borrowing_checkouts')->fetchColumn()===0, 'Failed submission retained key');
$bad=$body; $bad['items'][]=['cart_item_id'=>1,'equipment_id'=>10,'requested_quantity'=>1]+$details; fails(fn()=>checkoutBorrowingCart($db,1,$bad),400);
$bad=$body; $bad['items'][0]['requested_quantity']=4; fails(fn()=>checkoutBorrowingCart($db,1,$bad),409);
// Inject an error after an initial item is inserted: the entire transaction must roll back.
$db->exec("CREATE TRIGGER fail_second BEFORE INSERT ON borrowing_requests WHEN NEW.equipment_id=20 BEGIN SELECT RAISE(ABORT,'injected failure'); END");
$bad=$body; $bad['items'][]=['cart_item_id'=>2,'equipment_id'=>20,'requested_quantity'=>1]+$details;
try { checkoutBorrowingCart($db,1,$bad); throw new RuntimeException('Expected DB failure'); } catch (PDOException $e) {}
check(readBorrowingCart($db,1)===$before && (int)$db->query('SELECT COUNT(*) FROM borrowing_requests')->fetchColumn()===0, 'DB failure did not roll back batch');
check((int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id=10')->fetchColumn()===5, 'DB failure deducted stock');
$db->exec('DROP TRIGGER fail_second');
$result=checkoutBorrowingCart($db,1,$body);
check($result['success'] && $result['confirmed_equipment_ids']===[10], 'Missing acknowledgment');
check($result['data']['requests'][0]['status']==='pending', 'Incorrect initial status');
check(readBorrowingCart($db,1)[0]['quantity']===1 && readBorrowingCart($db,1)[1]['quantity']===2, 'Unselected items or additional quantities removed');
check((int)$db->query('SELECT available_quantity FROM equipment WHERE equipment_id=10')->fetchColumn()===3, 'Pending stock not reserved');
check(checkoutBorrowingCart($db,1,$body)===$result, 'Idempotent replay changed result');
check((int)$db->query('SELECT COUNT(*) FROM borrowing_requests')->fetchColumn()===1, 'Duplicate request created');
$bad=$body; $bad['items'][0]['requested_quantity']=1; fails(fn()=>checkoutBorrowingCart($db,1,$bad),409);
// Stock changed after adding to the cart.
$db->exec('UPDATE equipment SET available_quantity=0 WHERE equipment_id=20');
$bad=$body; $bad['idempotency_key']='test_checkout_key_02'; $bad['items']=[['cart_item_id'=>2,'equipment_id'=>20,'requested_quantity'=>1]+$details];
fails(fn()=>checkoutBorrowingCart($db,1,$bad),409);
check(readBorrowingCart($db,1)[1]['quantity']===2, 'Unavailable item removed after failure');
mutateBorrowingCart($db,1,'DELETE',['cart_item_id'=>2]);
check(count(readBorrowingCart($db,1))===1, 'Remove failed');
// Live schema is read-only in this suite. Gate verification uses real metadata.
$live=(new Database())->getConnection();
$caps=cartCapabilities($live);
if (!$caps['checkout_available']) fails(fn()=>requireCartSchema($live),503);
class CartApiResponse extends Exception { public function __construct(public int $status, public array $payload) {} }
function sendJson(int $status,array $payload):void { throw new CartApiResponse($status,$payload); }
function getJsonBody():array { return []; }
foreach (['index.php','checkout.php'] as $file) {
    $source=preg_replace('/^<\?php/', '', file_get_contents(__DIR__.'/../api/cart/'.$file));
    $source=preg_replace('/require_once[^;]+;/', '', $source);
    foreach ([null,'staff','admin','customer'] as $role) {
        $_SESSION=$role===null?[]:['user_id'=>1,'name'=>'Test','email'=>'test@example.invalid','role'=>$role];
        $_SERVER['REQUEST_METHOD']='POST'; $_GET=[]; $db=$live;
        try { eval($source); } catch (CartApiResponse $e) {
            $expected=$role===null?401:($role==='customer'?($caps['checkout_available']?400:503):403);
            check($e->status===$expected, 'API auth/schema gate failed: '.$e->status);
        }
    }
}
echo "PASS: cart ownership, quantity/date validation, database failures, atomic rollback, pending reservation, selected-only removal, idempotent replay/conflict, schema gate, and API authentication. SQLite tests do not verify MySQL locking.\n";
