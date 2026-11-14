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

test('guide_secret trims a trailing newline from env and checks the length after trimming', function () {
    putenv('GUIDE_SECRET=' . str_repeat('s', 32) . "\n");
    eq(guide_secret(), str_repeat('s', 32), 'env secret trimmed');
    putenv('GUIDE_SECRET=' . str_repeat('s', 31) . "\n");
    $threw = false;
    try { guide_secret(); } catch (RuntimeException $e) { $threw = true; }
    ok($threw, '31 characters plus a newline is still too short');
    putenv('GUIDE_SECRET');
});

test('guide_secret trims the secret read from the config file', function () {
    // A child PHP with its own WEBROOT, so the config file lives in a temp folder.
    $base = sys_get_temp_dir() . '/guide-config-test-' . bin2hex(random_bytes(6));
    mkdir($base . '/web', 0700, true);
    mkdir($base . '/delventto-private', 0700, true);
    $secret = str_repeat('k', 40);
    file_put_contents($base . '/delventto-private/config.php', "<?php return ['secret' => '" . $secret . "\n'];\n");
    $code = "define('WEBROOT', " . var_export($base . '/web', true) . "); putenv('GUIDE_SECRET');"
        . ' require ' . var_export(dirname(__DIR__) . '/_guide/lib/config.php', true) . '; echo json_encode(guide_secret());';
    try {
        // Array form: no shell, so the code reaches PHP exactly as written on every OS.
        $proc = proc_open([PHP_BINARY, '-r', $code], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        $out .= $err;
        eq(json_decode((string) $out, true), $secret, 'file secret trimmed, got ' . var_export($out, true));
    } finally {
        @unlink($base . '/delventto-private/config.php');
        @rmdir($base . '/delventto-private');
        @rmdir($base . '/web');
        @rmdir($base);
    }
});
