<?php
declare(strict_types=1);

/** Maximum clock difference between bot and server, in seconds. */
const GUIDE_MAX_SKEW = 300;

/** hex(HMAC-SHA256(secret, ts \n METHOD \n path \n hex(sha256(body)))) */
function sign_request(string $secret, int $ts, string $method, string $path, string $body): string
{
    $message = $ts . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $body);
    return hash_hmac('sha256', $message, $secret);
}

/** $headers has lowercased keys. */
function verify_request(string $secret, array $headers, string $method, string $path, string $body, int $now): bool
{
    $tsRaw = $headers['x-guide-timestamp'] ?? null;
    $sig = $headers['x-guide-signature'] ?? null;
    if (!is_string($tsRaw) || !is_string($sig) || $sig === '') {
        return false;
    }
    if (!preg_match('/\A[0-9]{1,12}\z/', $tsRaw)) {
        return false;
    }
    $ts = (int) $tsRaw;
    if (abs($now - $ts) > GUIDE_MAX_SKEW) {
        return false;
    }
    return hash_equals(sign_request($secret, $ts, $method, $path, $body), $sig);
}

function gate_cookie_name(string $slug): string
{
    return 'g_' . $slug;
}

/** Key is derived so a gate token can never double as an API signature. */
function gate_token(string $secret, string $slug): string
{
    $key = hash_hmac('sha256', 'gate-key', $secret);
    return hash_hmac('sha256', "gate\n" . $slug, $key);
}

function gate_passed(string $secret, string $slug, array $cookies): bool
{
    $given = $cookies[gate_cookie_name($slug)] ?? null;
    if (!is_string($given) || $given === '') {
        return false;
    }
    return hash_equals(gate_token($secret, $slug), $given);
}
