<?php
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/auth_middleware.php';

/** Authorize before any protected page markup is sent, including without JavaScript. */
function requirePageRole(array $allowedRoles): void
{
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Content-Type: text/html; charset=UTF-8');
    $user = currentUser();
    if (!$user) {
        header('Location: index.html', true, 302);
        exit();
    }
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        echo '<!DOCTYPE html><html lang="en"><meta charset="UTF-8"><title>Access denied</title>';
        echo '<h1>This page is not available for your account</h1>';
        echo '<p><a href="dashboard.html">Return to Overview</a></p></html>';
        exit();
    }
}
