<?php

/**
 * Insert a notification row for a user. Called whenever something happens
 * that the user should be told about (request approved/rejected, return
 * processed, etc.) — this is what the "notifications" table and the
 * notifications/status-updates feature from the proposal are for.
 */
function notifyUser(PDO $db, int $userId, string $message): void
{
    $stmt = $db->prepare('INSERT INTO notifications (user_id, message) VALUES (:user_id, :message)');
    $stmt->execute(['user_id' => $userId, 'message' => $message]);
}
