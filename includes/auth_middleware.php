<?php

function currentUser(): ?array
{
    if (!isset($_SESSION['user_id'])) {
        return null;
    }
    return [
        'user_id' => $_SESSION['user_id'],
        'name'    => $_SESSION['name'],
        'email'   => $_SESSION['email'],
        'role'    => $_SESSION['role'],   // role_name string, e.g. 'admin'
    ];
}

/** Blocks the request unless someone is logged in. */
function requireLogin(): array
{
    $user = currentUser();
    if (!$user) {
        sendJson(401, ['success' => false, 'message' => 'You must be logged in.']);
    }
    return $user;
}

/** Blocks the request unless the logged-in user has one of the given roles. */
function requireRole(array $allowedRoles): array
{
    $user = requireLogin();
    if (!in_array($user['role'], $allowedRoles, true)) {
        sendJson(403, ['success' => false, 'message' => 'You do not have permission to do that.']);
    }
    return $user;
}
