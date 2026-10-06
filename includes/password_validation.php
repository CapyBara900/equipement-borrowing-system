<?php

/**
 * Validate a new account password and return a user-facing error, if any.
 */
function validateNewPassword(?string $password, string $name = '', string $email = ''): ?string
{
    $password = $password ?? '';

    if ($password === '') {
        return 'Password is required.';
    }
    if (strlen($password) < 12) {
        return 'Password must be at least 12 characters.';
    }
    if (preg_match('/\s/', $password)) {
        return 'Password must not contain spaces.';
    }
    if (!preg_match('/[A-Z]/', $password)) {
        return 'Password must contain at least one uppercase letter.';
    }
    if (!preg_match('/[a-z]/', $password)) {
        return 'Password must contain at least one lowercase letter.';
    }
    if (!preg_match('/[0-9]/', $password)) {
        return 'Password must contain at least one number.';
    }
    if (!preg_match('/[!@#$%^&*]/', $password)) {
        return 'Password must contain at least one special character (!, @, #, $, %, ^, &, or *).';
    }

    $passwordLower = strtolower($password);
    $emailLower = strtolower(trim($email));
    $localPart = strstr($emailLower, '@', true) ?: '';
    $nameLower = strtolower($name);
    $nameParts = preg_split('/[^[:alnum:]]+/u', $nameLower, -1, PREG_SPLIT_NO_EMPTY);
    $nameWithoutSeparators = preg_replace('/[^[:alnum:]]+/u', '', $nameLower);
    $personalValues = array_filter(array_merge([$emailLower, $localPart, $nameWithoutSeparators], $nameParts), static function ($value) {
        return strlen($value) >= 3;
    });

    foreach (array_unique($personalValues) as $personalValue) {
        if (str_contains($passwordLower, $personalValue)) {
            return 'Password must not contain your name, username, or email address.';
        }
    }

    return null;
}
