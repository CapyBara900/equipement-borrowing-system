<?php
require_once __DIR__ . '/../../includes/bootstrap.php';

$user = currentUser();
if (!$user) {
    sendJson(401, ['success' => false, 'message' => 'Not logged in.']);
}

sendJson(200, ['success' => true, 'data' => $user]);
