<?php
require_once __DIR__ . '/borrowing_submission.php';

/** Read-only schema readiness check. */
function cartCapabilities(PDO $db): array
{
    $required = [
        'users' => ['user_id'],
        'equipment' => ['equipment_id', 'available_quantity', 'borrowing_time_limit_days'],
        'borrowing_requests' => ['request_id', 'user_id', 'equipment_id', 'requested_quantity', 'borrow_date', 'expected_return_date', 'status', 'checkout_id'],
        'borrowing_cart_items' => ['cart_item_id', 'user_id', 'equipment_id', 'quantity', 'borrow_date', 'expected_return_date', 'created_at', 'updated_at'],
        'borrowing_checkouts' => ['checkout_id', 'user_id', 'idempotency_key', 'payload_hash', 'response_json', 'borrow_date', 'expected_return_date', 'created_at'],
    ];
    $tables = $db->query('SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchAll(PDO::FETCH_KEY_PAIR);
    $columns = $db->query('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()')->fetchAll();
    $available = [];
    foreach ($columns as $column) $available[$column['TABLE_NAME']][] = $column['COLUMN_NAME'];
    $missing = [];
    foreach ($required as $table => $names) {
        if (($tables[$table] ?? '') !== 'InnoDB' || array_diff($names, $available[$table] ?? [])) $missing[] = $table;
    }
    // Both uniqueness constraints are essential, not optional optimizations.
    $indexes = $db->query('SELECT TABLE_NAME, INDEX_NAME, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX')->fetchAll();
    $keys = [];
    foreach ($indexes as $index) $keys[$index['TABLE_NAME']][$index['INDEX_NAME']][] = $index['COLUMN_NAME'];
    foreach (['borrowing_cart_items' => ['user_id', 'equipment_id', 'borrow_date', 'expected_return_date'], 'borrowing_checkouts' => ['user_id', 'idempotency_key'], 'borrowing_requests' => ['checkout_id', 'equipment_id', 'borrow_date', 'expected_return_date']] as $table => $key) {
        if (!in_array($key, array_values($keys[$table] ?? []), true)) $missing[] = $table . ' unique key';
    }
    if (($keys['borrowing_cart_items']['PRIMARY'] ?? []) !== ['cart_item_id']) $missing[] = 'borrowing_cart_items entry primary key';
    if (in_array(['checkout_id','equipment_id'],array_values($keys['borrowing_requests'] ?? []),true)) $missing[] = 'borrowing_requests obsolete equipment-only unique key';
    $checkoutCheck=$db->query("SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE() AND CONSTRAINT_NAME='chk_checkout_dates'")->fetchColumn();
    if (!str_contains((string)$checkoutCheck,'>=')) $missing[] = 'borrowing_checkouts same-day date constraint';
    $status = $db->query("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='borrowing_requests' AND COLUMN_NAME='status'")->fetchColumn();
    if (!str_contains((string)$status, "'cancelled'")) $missing[] = 'borrowing_requests cancelled status';
    $foreignKeys = $db->query("SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL")->fetchAll();
    foreach ([['borrowing_cart_items','user_id','users','user_id'], ['borrowing_cart_items','equipment_id','equipment','equipment_id'], ['borrowing_checkouts','user_id','users','user_id'], ['borrowing_requests','checkout_id','borrowing_checkouts','checkout_id']] as $expected) {
        $found = false;
        foreach ($foreignKeys as $foreignKey) if (array_values($foreignKey) === $expected) { $found = true; break; }
        if (!$found) $missing[] = $expected[0] . '.' . $expected[1] . ' foreign key';
    }
    $ready = !$missing;
    return ['cart_available' => $ready, 'checkout_available' => $ready, 'missing_requirements' => array_values(array_unique($missing)), 'message' => $ready ? 'Cart checkout is available.' : 'Cart dates require the dated cart database migration. Your saved cart is preserved.'];
}

function requireCartSchema(PDO $db): void
{
    $capabilities = cartCapabilities($db);
    if (!$capabilities['checkout_available']) throw new BorrowingFailure(503, $capabilities['message'], 'CART_SCHEMA_REQUIRED');
}

function lockCartOwner(PDO $db, int $userId): void
{
    $lock = $db->prepare('SELECT user_id FROM users WHERE user_id = ? FOR UPDATE');
    $lock->execute([$userId]);
    if (!$lock->fetchColumn()) throw new BorrowingFailure(401, 'Your account is no longer available.');
}

function readBorrowingCart(PDO $db, int $userId): array
{
    $stmt=$db->prepare("SELECT ci.cart_item_id,ci.equipment_id,ci.quantity,ci.borrow_date,ci.expected_return_date,ci.created_at,ci.updated_at,e.equipment_name,e.description,e.serial_number,e.category_id,c.category_name,e.available_quantity,e.borrowing_time_limit_days,CASE WHEN e.available_quantity > 0 THEN 'available' ELSE 'unavailable' END AS status FROM borrowing_cart_items ci LEFT JOIN equipment e ON e.equipment_id=ci.equipment_id LEFT JOIN categories c ON c.category_id=e.category_id WHERE ci.user_id=? ORDER BY ci.cart_item_id");
    $stmt->execute([$userId]);
    return array_map(function($row){foreach(['cart_item_id','equipment_id','quantity'] as $field)$row[$field]=(int)$row[$field];return $row;},$stmt->fetchAll());
}

function normalizeCartDetails(array $body): array
{
    $quantity=borrowingPositiveInt($body['quantity'] ?? null,'quantity');
    [$pickup,$returned]=validateBorrowingDates($body['borrow_date'] ?? null,$body['expected_return_date'] ?? null);
    return ['quantity'=>$quantity,'borrow_date'=>$pickup->format('Y-m-d'),'expected_return_date'=>$returned->format('Y-m-d')];
}

function writeDatedCartItem(PDO $db,int $userId,string $method,array $item):void
{
    $entry=null;
    if (in_array($method,['PUT','DELETE'],true)) {
        $stmt=$db->prepare('SELECT * FROM borrowing_cart_items WHERE user_id=? AND cart_item_id=? FOR UPDATE');$stmt->execute([$userId,$item['cart_item_id']]);$entry=$stmt->fetch();
        if (!$entry) throw new BorrowingFailure(404,'Cart entry not found.');
        if ($method==='DELETE') { $db->prepare('DELETE FROM borrowing_cart_items WHERE user_id=? AND cart_item_id=?')->execute([$userId,$item['cart_item_id']]);return; }
        $item['equipment_id']=(int)$entry['equipment_id'];
    }
    $id=$item['equipment_id'];
    $equipment=$db->prepare('SELECT equipment_name,available_quantity,borrowing_time_limit_days FROM equipment WHERE equipment_id=? FOR UPDATE');$equipment->execute([$id]);$stock=$equipment->fetch();
    if (!$stock) throw new BorrowingFailure(404,'Equipment not found.');
    validateEquipmentBorrowingLimit($stock,borrowingDate($item['borrow_date']),borrowingDate($item['expected_return_date']));
    $match=$db->prepare('SELECT cart_item_id,quantity FROM borrowing_cart_items WHERE user_id=? AND equipment_id=? AND borrow_date=? AND expected_return_date=? FOR UPDATE');$match->execute([$userId,$id,$item['borrow_date'],$item['expected_return_date']]);$existing=$match->fetch();
    if ($method==='PUT' && $existing && (int)$existing['cart_item_id']!==(int)$entry['cart_item_id']) throw new BorrowingFailure(409,'Another entry for this equipment already uses those dates. Choose different dates or edit that entry.','CART_DATE_CONFLICT');
    $quantity=$item['quantity'];
    if ($method==='POST' && $existing) $quantity+=(int)$existing['quantity'];
    if ($method==='IMPORT' && $existing) $quantity=max($quantity,(int)$existing['quantity']);
    if ($quantity > (int)$stock['available_quantity']) throw new BorrowingFailure(409,'Requested quantity exceeds available stock.','INSUFFICIENT_STOCK');
    $entryId=$method==='PUT'?$entry['cart_item_id']:($existing['cart_item_id'] ?? null);
    if ($entryId) $db->prepare('UPDATE borrowing_cart_items SET quantity=?,borrow_date=?,expected_return_date=? WHERE user_id=? AND cart_item_id=?')->execute([$quantity,$item['borrow_date'],$item['expected_return_date'],$userId,$entryId]);
    else $db->prepare('INSERT INTO borrowing_cart_items(user_id,equipment_id,quantity,borrow_date,expected_return_date) VALUES(?,?,?,?,?)')->execute([$userId,$id,$quantity,$item['borrow_date'],$item['expected_return_date']]);
}

function writeBorrowingCartItems(PDO $db,int $userId,string $method,array $items):array
{
    usort($items,fn($a,$b)=>($a['equipment_id'] ?? $a['cart_item_id']) <=> ($b['equipment_id'] ?? $b['cart_item_id']));
    $db->beginTransaction();
    try {
        lockCartOwner($db,$userId);
        foreach($items as $item) writeDatedCartItem($db,$userId,$method,$item);
        $rows=readBorrowingCart($db,$userId);$db->commit();return $rows;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
}

function mutateBorrowingCart(PDO $db,int $userId,string $method,array $body):array
{
    $item=$method==='DELETE'?[]:normalizeCartDetails($body);
    if ($method==='POST') $item['equipment_id']=borrowingPositiveInt($body['equipment_id'] ?? null,'equipment_id');
    else $item['cart_item_id']=borrowingPositiveInt($body['cart_item_id'] ?? null,'cart_item_id');
    return writeBorrowingCartItems($db,$userId,$method,[$item]);
}

function importBorrowingCart(PDO $db,int $userId,array $body):array
{
    $input=$body['items'] ?? null;
    if(!is_array($input)||!array_is_list($input)||!$input||count($input)>100)throw new BorrowingFailure(400,'Import between 1 and 100 dated cart entries.');
    $items=[];$seen=[];
    foreach($input as $row) {
        if(!is_array($row))throw new BorrowingFailure(400,'Invalid cart entry.');
        $item=normalizeCartDetails($row);$item['equipment_id']=borrowingPositiveInt($row['equipment_id'] ?? null,'equipment_id');
        $identity=$item['equipment_id'].'/'.$item['borrow_date'].'/'.$item['expected_return_date'];
        if(isset($seen[$identity]))throw new BorrowingFailure(400,'Import each equipment/date combination only once.');$seen[$identity]=true;$items[]=$item;
    }
    return writeBorrowingCartItems($db,$userId,'IMPORT',$items);
}

function normalizeCartCheckout(array $body):array
{
    $key=$body['idempotency_key'] ?? null;
    if(!is_string($key)||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$key))throw new BorrowingFailure(400,'A 16–128 character idempotency_key is required.');
    $input=$body['items'] ?? null;
    if(!is_array($input)||!array_is_list($input)||!$input||count($input)>100)throw new BorrowingFailure(400,'Select between 1 and 100 cart entries.');
    // Preserve hashes of pre-upgrade uncertain submissions, for replay only.
    $legacy=isset($body['borrow_date'],$body['expected_return_date']) && !isset($input[0]['cart_item_id']);
    $items=[];$seen=[];
    foreach($input as $row) {
        if(!is_array($row))throw new BorrowingFailure(400,'Invalid selected cart entry.');
        $item=['equipment_id'=>borrowingPositiveInt($row['equipment_id'] ?? null,'equipment_id'),'requested_quantity'=>borrowingPositiveInt($row['requested_quantity'] ?? null,'requested_quantity')];
        if(!$legacy) {
            $item=['cart_item_id'=>borrowingPositiveInt($row['cart_item_id'] ?? null,'cart_item_id')]+$item;
            $item['borrow_date']=borrowingDate($row['borrow_date'] ?? null)->format('Y-m-d');
            $item['expected_return_date']=borrowingDate($row['expected_return_date'] ?? null)->format('Y-m-d');
        }
        $identity=$legacy?$item['equipment_id']:$item['cart_item_id'];
        if(isset($seen[$identity]))throw new BorrowingFailure(400,'Select each cart entry only once.');$seen[$identity]=true;$items[]=$item;
    }
    usort($items,fn($a,$b)=>($legacy?$a['equipment_id']:$a['cart_item_id']) <=> ($legacy?$b['equipment_id']:$b['cart_item_id']));
    $payload=['items'=>$items];
    if($legacy) { borrowingDate($body['borrow_date']);borrowingDate($body['expected_return_date']);$payload['borrow_date']=$body['borrow_date'];$payload['expected_return_date']=$body['expected_return_date']; }
    return [$key,$payload,hash('sha256',json_encode($payload,JSON_THROW_ON_ERROR))];
}

function checkoutBorrowingCart(PDO $db,int $userId,array $body):array
{
    [$key,$payload,$hash]=normalizeCartCheckout($body);
    $db->beginTransaction();
    try {
        lockCartOwner($db,$userId);
        $ledger=$db->prepare('SELECT payload_hash,response_json FROM borrowing_checkouts WHERE user_id=? AND idempotency_key=? FOR UPDATE');$ledger->execute([$userId,$key]);$previous=$ledger->fetch();
        if($previous) {
            if(!hash_equals($previous['payload_hash'],$hash))throw new BorrowingFailure(409,'This submission key was already used for different entries or dates.','IDEMPOTENCY_CONFLICT');
            if(!$previous['response_json'])throw new BorrowingFailure(409,'Submission is unconfirmed. Retry the same key.','SUBMISSION_UNCONFIRMED');
            $result=json_decode($previous['response_json'],true,512,JSON_THROW_ON_ERROR);$db->commit();return $result;
        }
        if(isset($payload['borrow_date']))throw new BorrowingFailure(400,'Reload your cart and save dates on each entry before submitting.');
        $cart=$db->prepare('SELECT equipment_id,quantity,borrow_date,expected_return_date FROM borrowing_cart_items WHERE user_id=? AND cart_item_id=? FOR UPDATE');
        foreach($payload['items'] as $item) {
            $cart->execute([$userId,$item['cart_item_id']]);$saved=$cart->fetch();
            if(!$saved || (int)$saved['equipment_id']!==$item['equipment_id'] || (int)$saved['quantity']<$item['requested_quantity'] || $saved['borrow_date']!==$item['borrow_date'] || $saved['expected_return_date']!==$item['expected_return_date'])throw new BorrowingFailure(409,'Selected cart quantities or dates changed. Refresh your cart.','CART_CHANGED');
            validateBorrowingDates($item['borrow_date'],$item['expected_return_date']);
        }
        // Header dates are the group envelope; item rows carry the exact independent dates.
        $min=min(array_column($payload['items'],'borrow_date'));$max=max(array_column($payload['items'],'expected_return_date'));
        $db->prepare('INSERT INTO borrowing_checkouts(user_id,idempotency_key,payload_hash,response_json,borrow_date,expected_return_date) VALUES(?,?,?,NULL,?,?)')->execute([$userId,$key,$hash,$min,$max]);$checkoutId=(int)$db->lastInsertId();
        $requests=createBorrowingRequests($db,$userId,$payload['items'],null,null,$checkoutId);
        $remove=$db->prepare('DELETE FROM borrowing_cart_items WHERE user_id=? AND cart_item_id=? AND quantity=?');
        $reduce=$db->prepare('UPDATE borrowing_cart_items SET quantity=quantity-? WHERE user_id=? AND cart_item_id=? AND quantity>?');
        foreach($payload['items'] as $item) { $id=$item['cart_item_id'];$quantity=$item['requested_quantity'];$remove->execute([$userId,$id,$quantity]);if(!$remove->rowCount())$reduce->execute([$quantity,$userId,$id,$quantity]); }
        $result=['success'=>true,'message'=>'Borrowing requests submitted for staff approval.','confirmed_cart_item_ids'=>array_column($requests,'cart_item_id'),'confirmed_equipment_ids'=>array_column($requests,'equipment_id'),'data'=>['checkout_id'=>$checkoutId,'requests'=>$requests]];
        $db->prepare('UPDATE borrowing_checkouts SET response_json=? WHERE user_id=? AND idempotency_key=?')->execute([json_encode($result,JSON_THROW_ON_ERROR),$userId,$key]);$db->commit();return $result;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();throw $e; }
}
