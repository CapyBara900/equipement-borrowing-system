<?php
/** Validate the existing name column as a full name or username. */
function validateProfileName(string $name): ?string
{
    if (!preg_match("/^[\p{L}\p{M}\p{N} ._'\-]{3,100}$/u", $name)
        || !preg_match('/[\p{L}\p{N}]/u', $name)
        || str_contains($name, '  ')) {
        return "Full name / username must be 3–100 characters, include a letter or number, and use only letters, numbers, single spaces, periods, underscores, apostrophes, or hyphens.";
    }
    return null;
}

/** Database constraints also catch races after this user-friendly precheck. */
function profileConflict(PDO $db, string $name, string $email, int $userId = 0): ?array
{
    foreach (['name' => $name, 'email' => $email] as $field => $value) {
        // The column name comes only from this fixed list, never from request input.
        $stmt = $db->prepare("SELECT user_id FROM users WHERE $field COLLATE utf8mb4_unicode_ci = :value AND user_id <> :user_id LIMIT 1");
        $stmt->execute(['value' => $value, 'user_id' => $userId]);
        if ($stmt->fetch()) {
            return ['success' => false, 'code' => strtoupper($field) . '_TAKEN',
                'message' => $field === 'name' ? 'This full name / username is already used by another account.' : 'This email address is already associated with an account.'];
        }
    }
    return null;
}
