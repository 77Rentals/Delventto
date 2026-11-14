<?php
declare(strict_types=1);

require_once __DIR__ . '/api_test.php';
require_once __DIR__ . '/../_guide/lib/building.php';
require_once __DIR__ . '/../_guide/lib/validate.php';

// at_with_server serves a stub building file: {"id":"delventto","places":{}}.

test('building route: signed GET returns the building JSON', function () {
    at_with_server(function ($ctx) {
        $r = at_signed($ctx, 'GET', '/api/buildings/delventto');
        eq($r['status'], 200);
        eq($r['json'], ['ok' => true, 'building' => ['id' => 'delventto', 'places' => []]]);
        eq($r['headers']['cache-control'] ?? '', 'no-store');
    });
});

test('building route: serves exactly what guide_building returns', function () {
    $dir = sys_get_temp_dir() . '/guide-bld-test-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    file_put_contents($dir . '/delventto.json', '{"id":"delventto","places":{}}');
    putenv('GUIDE_BUILDINGS_DIR=' . $dir);
    try {
        $expected = guide_building('delventto');
    } finally {
        putenv('GUIDE_BUILDINGS_DIR');
        at_rrmdir($dir);
    }
    at_with_server(function ($ctx) use ($expected) {
        eq(at_signed($ctx, 'GET', '/api/buildings/delventto')['json']['building'], $expected);
    });
});

test('building route: unsigned GET is 401', function () {
    at_with_server(function ($ctx) {
        $r = at_http($ctx, 'GET', '/api/buildings/delventto');
        eq($r['status'], 401);
        ok(!isset($r['json']['building']));
    });
});

test('building route: unknown or malformed id is 404', function () {
    at_with_server(function ($ctx) {
        foreach (['nada', 'delventto/extra', '..%2f..%2fapi', 'UPPER', ''] as $id) {
            $r = at_signed($ctx, 'GET', '/api/buildings/' . $id);
            eq($r['status'], 404, "id '$id'");
            eq($r['json']['ok'] ?? null, false);
        }
    });
});

test('building route: POST is 405 with Allow GET', function () {
    at_with_server(function ($ctx) {
        $r = at_signed($ctx, 'POST', '/api/buildings/delventto', '{}');
        eq($r['status'], 405);
        eq($r['headers']['allow'] ?? '', 'GET');
    });
});

test('building file: standardArrival passes validate_guide as lock.steps', function () {
    $b = json_decode((string) file_get_contents(__DIR__ . '/../_guide/buildings/delventto.json'), true);
    ok(is_array($b['standardArrival'] ?? null) && count($b['standardArrival']) === 3, 'standardArrival missing');
    $g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/minimal.json'), true);
    $g['lock']['steps'] = $b['standardArrival'];
    $problems = validate_guide($g, $b, 'apto-1204');
    eq($problems, [], json_encode($problems, JSON_UNESCAPED_UNICODE));
    $text = json_encode($b['standardArrival'], JSON_UNESCAPED_UNICODE);
    ok(strpos($text, '613') === false && stripos($text, 'Natalia') === false && stripos($text, 'Ninson') === false, 'unit specific text');
});
