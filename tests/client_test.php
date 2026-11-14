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
    eq(json_decode($req['body'], true)['data'], bin2hex($jpeg));
    $lower = array_change_key_case($req['headers'], CASE_LOWER);
    ok(verify_request($secret, $lower, 'POST', '/api/guides/apto-1/photos', $req['body'], $ts), 'signature verifies');
});

test('client upload signs a hex body by default and base64 on request', function () {
    $secret = 'fixed-secret-fixed-secret-fixed-secret-1234';
    $ts = 1800000000;
    $jpeg = "\xFF\xD8\xFF\xE0" . 'abc';
    $hex = client_upload_request($secret, $ts, 'apto-1', 'a1', $jpeg);
    eq(json_decode($hex['body'], true), ['name' => 'a1', 'type' => 'image/jpeg', 'encoding' => 'hex', 'data' => bin2hex($jpeg)]);
    $lower = array_change_key_case($hex['headers'], CASE_LOWER);
    ok(verify_request($secret, $lower, 'POST', '/api/guides/apto-1/photos', $hex['body'], $ts), 'hex signature verifies');
    $b64 = client_upload_request($secret, $ts, 'apto-1', 'a1', $jpeg, 'base64');
    eq(json_decode($b64['body'], true)['encoding'], 'base64');
    eq(json_decode($b64['body'], true)['data'], base64_encode($jpeg));
});

test('client splits an upload into signed 1 MB parts', function () {
    $secret = 'fixed-secret-fixed-secret-fixed-secret-1234';
    $ts = 1800000000;
    $bytes = "\xFF\xD8\xFF\xE0" . str_repeat('z', 2500000);
    $reqs = client_upload_requests($secret, $ts, 'apto-1', 'a1', $bytes, 'hex', 'abcd1234ef');
    eq(count($reqs), 3);
    $joined = '';
    foreach ($reqs as $i => $req) {
        $b = json_decode($req['body'], true);
        eq([$b['upload'], $b['part'], $b['parts'], $b['encoding']], ['abcd1234ef', $i + 1, 3, 'hex']);
        ok(strlen(hex2bin($b['data'])) <= 1048576, 'part within 1 MB');
        $joined .= hex2bin($b['data']);
        $lower = array_change_key_case($req['headers'], CASE_LOWER);
        ok(verify_request($secret, $lower, 'POST', '/api/guides/apto-1/photos', $req['body'], $ts), "part $i signature");
    }
    eq($joined, $bytes);
    eq(count(client_upload_requests($secret, $ts, 'apto-1', 'a1', "\xFF\xD8\xFF\xE0abc", 'hex', 'abcd1234ef')), 1);
    eq(client_upload_requests($secret, $ts, 'apto-1', 'a1', 'not an image', 'hex', 'abcd1234ef'), []);
    ok(preg_match('/^[a-z0-9]{8,40}$/D', client_upload_id()) === 1, 'random id shape');
});
