<?php

function sendJson(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit();
}

function getJsonBody(): array
{
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}

/**
 * Strip tags and trim free-text input before it ever reaches a query or a
 * response. Prepared statements already stop SQL injection; this covers the
 * separate concern of stored XSS (e.g. someone submitting a category
 * description containing a <script> tag).
 */
function cleanText(?string $value): string
{
    return trim(strip_tags($value ?? ''));
}
