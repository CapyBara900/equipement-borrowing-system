<?php
// Environment is loaded by config/database.php before this helper is used.
function borrowingTimezone(): DateTimeZone
{
    return new DateTimeZone(getenv('APP_TIMEZONE') ?: 'Asia/Manila');
}

function pickupDateWindow(?DateTimeImmutable $now = null): array
{
    $timezone = borrowingTimezone();
    $today = ($now ?? new DateTimeImmutable('now', $timezone))->setTimezone($timezone)->setTime(0, 0);
    return [
        'timezone' => $timezone->getName(),
        'min_date' => $today->format('Y-m-d'),
        'max_date' => $today->modify('+7 days')->format('Y-m-d'),
    ];
}
