<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/auth.php';
require_once __DIR__ . '/client.php';

test('client signature is accepted by the server verifier', function () {
    $secret = 'fixed-secret-fixed-secret-fixed-secret-1234';
    $ts = 1800000000;
    $body = '{"a":1}';
    $h = client_signed_headers($secret, $ts, 'put', '/api/guides/prueba-1/draft', $body);
    $lower = array_change_key_case($h, CASE_LOWER);
    ok(verify_request($secret, $lower, 'PUT', '/api/guides/prueba-1/draft', $body, $ts));
    ok(!verify_request('other-secret', $lower, 'PUT', '/api/guides/prueba-1/draft', $body, $ts));
});

test('client signs the path without the query string', function () {
    $secret = 'fixed-secret-fixed-secret-fixed-secret-1234';
    $ts = 1800000000;
    $h = client_signed_headers($secret, $ts, 'POST', '/api/guides/x/photos?name=a.jpg', 'bytes');
    $lower = array_change_key_case($h, CASE_LOWER);
    ok(verify_request($secret, $lower, 'POST', '/api/guides/x/photos', 'bytes', $ts));
});
