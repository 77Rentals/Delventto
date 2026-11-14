<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/slug.php';

test('slug_valid accepts good slugs', function () {
    ok(slug_valid('apto-1204'));
    ok(slug_valid('ab'));
    ok(slug_valid(str_repeat('a', 40)));
});

test('slug_valid rejects bad slugs', function () {
    foreach (['Apto', 'a', 'a--b', '../x', 'a.b', 'a/b', str_repeat('a', 41), '', '-ab', 'ab-'] as $bad) {
        ok(!slug_valid($bad), "should reject '$bad'");
    }
});

test('slug_reserved flags existing top-level names and fixed words', function () {
    ok(slug_reserved('apartasuite-613t2'));
    ok(slug_reserved('api'));
    ok(slug_reserved('_guide'));
    ok(slug_reserved('images'));
    ok(slug_reserved('tests'));
    ok(slug_reserved('lina'));
});

test('slug_reserved allows free slugs', function () {
    ok(!slug_reserved('apto-1204'));
});

test('guide_data_dir honours env and defaults beside the webroot', function () {
    putenv('GUIDE_DATA_DIR=/tmp/x-guides');
    eq(guide_data_dir(), '/tmp/x-guides');
    putenv('GUIDE_DATA_DIR');
    eq(guide_data_dir(), dirname(WEBROOT) . '/delventto-guides');
});

test('guide_secret honours env and enforces length', function () {
    putenv('GUIDE_SECRET=' . str_repeat('s', 32));
    eq(guide_secret(), str_repeat('s', 32));
    putenv('GUIDE_SECRET=short');
    $threw = false;
    try { guide_secret(); } catch (RuntimeException $e) { $threw = true; }
    ok($threw, 'short secret must throw');
    putenv('GUIDE_SECRET');
});
