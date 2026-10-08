<?php
require_once __DIR__ . '/borrowing_dates.php';

class BorrowingFailure extends RuntimeException
{
    public function __construct(public int $httpStatus, string $message, public string $errorCode = 'VALIDATION_ERROR') { parent::__construct($message); }
}

function borrowingPositiveInt($value, string $field): int
{
    if ((!is_int($value) && (!is_string($value) || !preg_match('/^[1-9][0-9]*$/D', $value))) || filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < 1 || (int)$value > 2147483647) {
        throw new BorrowingFailure(400, "$field must be a positive whole number.");
    }
    return (int)$value;
}

function borrowingDate($value): DateTimeImmutable
{
    if (!is_string($value) || !preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $value)) throw new BorrowingFailure(400, 'Please enter valid dates.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value, borrowingTimezone());
    if (!$date || $date->format('Y-m-d') !== $value) throw new BorrowingFailure(400, 'Please enter valid dates.');
    return $date;
}

function validateBorrowingDates($pickup, $returned, bool $strictReturn = false): array
{
    $start = borrowingDate($pickup);
    $end = borrowingDate($returned);
    $window = pickupDateWindow();
    if ($pickup < $window['min_date'] || $pickup > $window['max_date']) {
        throw new BorrowingFailure(400, 'Pick up date must be between ' . $window['min_date'] . ' and ' . $window['max_date'] . ' (today through 7 calendar days in advance).');
    }
    if ($end < $start || ($strictReturn && $end == $start)) {
        throw new BorrowingFailure(400, $strictReturn ? 'Return date must be after the pick up date.' : 'Return date cannot be before the pick up date.');
    }
    return [$start, $end];
}

function validateEquipmentBorrowingLimit(array $equipment, DateTimeImmutable $pickup, DateTimeImmutable $returned): void
{
    $limit = (int)$equipment['borrowing_time_limit_days'];
    $maximum = $pickup->modify("+$limit days");
    if ($limit < 1 || $returned > $maximum) throw new BorrowingFailure(400, "Borrowing time limit is $limit days. Return by " . $maximum->format('Y-m-d') . ' for ' . $equipment['equipment_name'] . '.');
}

/** Shared reservation logic for direct requests and independently dated cart entries. */
function createBorrowingRequests(PDO $db, int $userId, array $items, ?DateTimeImmutable $pickup = null, ?DateTimeImmutable $returned = null, ?int $checkoutId = null): array
{
    if (!$db->inTransaction()) throw new LogicException('Borrowing requires a transaction.');
    usort($items, fn($a,$b) => ($a['equipment_id'] <=> $b['equipment_id']) ?: (($a['cart_item_id'] ?? 0) <=> ($b['cart_item_id'] ?? 0)));
    $check = $db->prepare('SELECT equipment_id, equipment_name, available_quantity, borrowing_time_limit_days FROM equipment WHERE equipment_id = ? FOR UPDATE');
    $locked = []; $totals = []; $dates = [];
    foreach ($items as $index => $item) {
        $id = borrowingPositiveInt($item['equipment_id'], 'equipment_id');
        $quantity = borrowingPositiveInt($item['requested_quantity'], 'requested_quantity');
        $totals[$id] = ($totals[$id] ?? 0) + $quantity;
        [$start,$end] = validateBorrowingDates($item['borrow_date'] ?? $pickup?->format('Y-m-d'), $item['expected_return_date'] ?? $returned?->format('Y-m-d'));
        $dates[$index] = [$start,$end];
        if (!isset($locked[$id])) {
            $check->execute([$id]); $equipment = $check->fetch();
            if (!$equipment) throw new BorrowingFailure(404, 'Equipment not found.', 'EQUIPMENT_NOT_FOUND');
            $locked[$id] = $equipment;
        }
        validateEquipmentBorrowingLimit($locked[$id], $start, $end);
    }
    // All selected dates reserve immediately: even non-overlapping entries share current stock.
    foreach ($totals as $id => $quantity) if ($quantity > (int)$locked[$id]['available_quantity']) throw new BorrowingFailure(409, 'The combined selected quantity exceeds available stock for ' . $locked[$id]['equipment_name'] . '.', 'INSUFFICIENT_STOCK');
    $insert = $checkoutId === null
        ? $db->prepare("INSERT INTO borrowing_requests (user_id,equipment_id,borrow_date,expected_return_date,requested_quantity,status) VALUES (?,?,?,?,?,'pending')")
        : $db->prepare("INSERT INTO borrowing_requests (user_id,equipment_id,borrow_date,expected_return_date,requested_quantity,status,checkout_id) VALUES (?,?,?,?,?,'pending',?)");
    $reserve = $db->prepare('UPDATE equipment SET available_quantity = available_quantity - ? WHERE equipment_id = ? AND available_quantity >= ?');
    $requests = [];
    foreach ($items as $index => $item) {
        $id = (int)$item['equipment_id']; $quantity = (int)$item['requested_quantity']; [$start,$end] = $dates[$index];
        $reserve->execute([$quantity,$id,$quantity]);
        if ($reserve->rowCount() !== 1) throw new BorrowingFailure(409, 'Those units are no longer available.', 'INSUFFICIENT_STOCK');
        $params = [$userId,$id,$start->format('Y-m-d'),$end->format('Y-m-d'),$quantity]; if ($checkoutId !== null) $params[] = $checkoutId;
        $insert->execute($params);
        $request = ['request_id'=>(int)$db->lastInsertId(),'equipment_id'=>$id,'equipment_name'=>$locked[$id]['equipment_name'],'requested_quantity'=>$quantity,'borrow_date'=>$start->format('Y-m-d'),'expected_return_date'=>$end->format('Y-m-d'),'status'=>'pending','checkout_id'=>$checkoutId];
        if (isset($item['cart_item_id'])) $request['cart_item_id'] = (int)$item['cart_item_id'];
        $requests[] = $request;
    }
    return $requests;
}
