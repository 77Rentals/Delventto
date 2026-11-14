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

test('client upload mode builds a valid signed JSON base64 request', function () {
    $secret = 'fixed-secret-fixed-secret-fixed-secret-1234';
    $ts = 1800000000;
    $jpeg = "\xFF\xD8\xFF\xE0" . 'abc';
    $png = "\x89PNG\r\n\x1A\n" . 'abc';
    $webp = 'RIFF' . "\x04\x00\x00\x00" . 'WEBPVP8 ';
    eq(client_image_type($jpeg), 'image/jpeg');
    eq(client_image_type($png), 'image/png');
    eq(client_image_type($webp), 'image/webp');
    eq(client_image_type('hello'), null);

    $req = client_upload_request($secret, $ts, 'apto-1', 'a1', $jpeg);
    eq($req['path'], '/api/guides/apto-1/photos');
    eq($req['ctype'], 'application/json');
    eq(json_decode($req['body'], true), ['name' => 'a1', 'type' => 'image/jpeg', 'data' => base64_encode($jpeg)]);
    $lower = array_change_key_case($req['headers'], CASE_LOWER);
    ok(verify_request($secret, $lower, 'POST', '/api/guides/apto-1/photos', $req['body'], $ts), 'signature verifies');
});
