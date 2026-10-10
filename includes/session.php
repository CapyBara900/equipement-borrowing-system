<?php
// Shared session configuration for API endpoints and protected PHP pages.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => true,
    ]);
    session_start();
}
