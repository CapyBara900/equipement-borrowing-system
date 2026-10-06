<?php

/**
 * Return a normalized email address, or a user-facing validation error.
 * Leading/trailing whitespace is intentionally removed before validation.
 */
function normalizeAndValidateEmail(?string $value): array
{
    $email = strtolower(trim(cleanText($value ?? '')));

    if ($email === '') {
        return ['', 'Email address is required.'];
    }
    if (strlen($email) > 254) {
        return ['', 'Email address must be 254 characters or fewer.'];
    }
    if (preg_match('/\s/', $email)) {
        return ['', 'Email address must not contain spaces.'];
    }
    if (substr_count($email, '@') !== 1) {
        return ['', 'Email address must contain exactly one @ symbol.'];
    }

    [$local, $domain] = explode('@', $email, 2);
    if ($local === '') {
        return ['', 'Email address must include a username before @.'];
    }
    if ($domain === '') {
        return ['', 'Email address must include a domain after @.'];
    }
    if (str_contains($local, '..') || str_starts_with($local, '.') || str_ends_with($local, '.')) {
        return ['', 'The username part must not start or end with a period or contain consecutive periods.'];
    }
    if (!preg_match('/^[A-Za-z0-9._+-]+$/', $local)) {
        return ['', 'The username part may only contain letters, numbers, periods, underscores, hyphens, and plus signs.'];
    }
    if (str_contains($domain, '..') || str_starts_with($domain, '.') || str_ends_with($domain, '.')
        || str_starts_with($domain, '-') || str_ends_with($domain, '-')) {
        return ['', 'The domain must not start or end with a hyphen or period, or contain consecutive periods.'];
    }

    $labels = explode('.', $domain);
    if (count($labels) < 2 || !preg_match('/^[A-Za-z]{2,63}$/', (string)end($labels))) {
        return ['', 'Email address must include a valid domain extension, like .com or .org.'];
    }
    foreach ($labels as $label) {
        if ($label === '' || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?$/', $label)) {
            return ['', 'Enter a valid email address, like name@domain.com.'];
        }
    }

    return [$email, null];
}
