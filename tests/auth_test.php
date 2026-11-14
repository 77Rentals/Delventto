<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/auth.php';

const AUTH_SECRET = 'test-secret-test-secret-test-secret-12345';

function auth_headers(int $ts, string $sig): array
{
    return ['x-guide-timestamp' => (string) $ts, 'x-guide-signature' => $sig];
}

test('signed request verifies', function () {
    $now = 1800000000;
    $sig = sign_request(AUTH_SECRET, $now, 'PUT', '/api/guides/apto-1204/draft', '{"a":1}');
    ok(verify_request(AUTH_SECRET, auth_headers($now, $sig), 'PUT', '/api/guides/apto-1204/draft', '{"a":1}', $now));
});

test('signature matches the documented formula', function () {
    $ts = 1800000000;
    $expected = hash_hmac('sha256', $ts . "\nGET\n/api/guides/x\n" . hash('sha256', ''), AUTH_SECRET);
    eq(sign_request(AUTH_SECRET, $ts, 'GET', '/api/guides/x', ''), $expected);
});

test('timestamp 301 s old or 301 s ahead fails, 300 passes', function () {
    $ts = 1800000000;
    $sig = sign_request(AUTH_SECRET, $ts, 'GET', '/p', '');
    $h = auth_headers($ts, $sig);
    ok(!verify_request(AUTH_SECRET, $h, 'GET', '/p', '', $ts + 301), 'old');
    ok(!verify_request(AUTH_SECRET, $h, 'GET', '/p', '', $ts - 301), 'future');
    ok(verify_request(AUTH_SECRET, $h, 'GET', '/p', '', $ts + 300), '300 old ok');
    ok(verify_request(AUTH_SECRET, $h, 'GET', '/p', '', $ts - 300), '300 ahead ok');
});

test('changed body, path, method, secret all fail', function () {
    $ts = 1800000000;
    $sig = sign_request(AUTH_SECRET, $ts, 'PUT', '/api/guides/apto-1204/draft', 'abc');
    $h = auth_headers($ts, $sig);
    ok(!verify_request(AUTH_SECRET, $h, 'PUT', '/api/guides/apto-1204/draft', 'abd', $ts), 'body');
    ok(!verify_request(AUTH_SECRET, $h, 'PUT', '/api/guides/other/draft', 'abc', $ts), 'path');
    ok(!verify_request(AUTH_SECRET, $h, 'POST', '/api/guides/apto-1204/draft', 'abc', $ts), 'method');
    ok(!verify_request(AUTH_SECRET . 'x', $h, 'PUT', '/api/guides/apto-1204/draft', 'abc', $ts), 'secret');
});

test('missing or bad headers fail', function () {
    $ts = 1800000000;
    $sig = sign_request(AUTH_SECRET, $ts, 'GET', '/p', '');
    ok(!verify_request(AUTH_SECRET, [], 'GET', '/p', '', $ts), 'none');
    ok(!verify_request(AUTH_SECRET, ['x-guide-timestamp' => (string) $ts], 'GET', '/p', '', $ts), 'no sig');
    ok(!verify_request(AUTH_SECRET, ['x-guide-signature' => $sig], 'GET', '/p', '', $ts), 'no ts');
    ok(!verify_request(AUTH_SECRET, ['x-guide-timestamp' => 'abc', 'x-guide-signature' => $sig], 'GET', '/p', '', $ts), 'non numeric');
    ok(!verify_request(AUTH_SECRET, ['x-guide-timestamp' => '1e9', 'x-guide-signature' => $sig], 'GET', '/p', '', $ts), 'float form');
});

test('gate cookie name and token', function () {
    eq(gate_cookie_name('apto-1204'), 'g_apto-1204');
    $key = hash_hmac('sha256', 'gate-key', AUTH_SECRET);
    eq(gate_token(AUTH_SECRET, 'apto-1204'), hash_hmac('sha256', "gate\napto-1204", $key));
});

test('gate_passed only for the right guide', function () {
    $a = gate_token(AUTH_SECRET, 'guide-a');
    ok(gate_passed(AUTH_SECRET, 'guide-a', ['g_guide-a' => $a]));
    ok(!gate_passed(AUTH_SECRET, 'guide-b', ['g_guide-b' => $a]), 'token of A on B');
    ok(!gate_passed(AUTH_SECRET, 'guide-a', []), 'no cookie');
    ok(!gate_passed(AUTH_SECRET, 'guide-a', ['g_guide-a' => 123]), 'non string');
});

test('gate token is not a valid API signature', function () {
    $ts = 1800000000;
    $t = gate_token(AUTH_SECRET, 'x');
    ok(!verify_request(AUTH_SECRET, auth_headers($ts, $t), 'GET', '/p', '', $ts));
});
