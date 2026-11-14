<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/auth.php';

// Integration tests: each test starts `php -S` on the dev router with its own
// temp data dir, talks to it over a raw socket (so paths go out exactly as
// written) and stops it in a finally block.

const API_T_SECRET = 'api-test-secret-api-test-secret-0123456789';

function at_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? at_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

function at_port_open(int $port): bool
{
    $s = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($s === false) {
        return false;
    }
    fclose($s);
    return true;
}

/**
 * Starts the dev server, runs $fn(ctx), always stops it and deletes the temp dirs.
 * $displayErrors is the server's display_errors setting ('0' unless a test needs PHP's default noisy mode).
 */
function at_with_server(callable $fn, string $displayErrors = '0'): void
{
    $base = sys_get_temp_dir() . '/guide-api-test-' . bin2hex(random_bytes(6));
    $data = $base . '/data';
    $buildings = $base . '/buildings';
    mkdir($data, 0700, true);
    mkdir($buildings, 0700, true);
    file_put_contents($buildings . '/delventto.json', '{"id":"delventto","places":{}}');

    $port = null;
    for ($p = 8765; $p <= 8799; $p++) {
        if (!at_port_open($p)) {
            $port = $p;
            break;
        }
    }
    if ($port === null) {
        throw new RuntimeException('no free port in 8765..8799');
    }

    $env = getenv();
    $env['GUIDE_SECRET'] = API_T_SECRET;
    $env['GUIDE_DATA_DIR'] = $data;
    $env['GUIDE_BUILDINGS_DIR'] = $buildings;
    $webroot = dirname(__DIR__);
    $cmd = [PHP_BINARY, '-d', 'display_errors=' . $displayErrors, '-d', 'log_errors=1', '-S', '127.0.0.1:' . $port, '-t', $webroot, $webroot . '/_guide/dev-router.php'];
    $log = $base . '/server.log';
    $proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $webroot, $env);
    if (!is_resource($proc)) {
        at_rrmdir($base);
        throw new RuntimeException('could not start php -S');
    }
    try {
        $deadline = microtime(true) + 5;
        while (!at_port_open($port)) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('php -S did not start: ' . @file_get_contents($log));
            }
            usleep(50000);
        }
        $fn(['port' => $port, 'data' => $data, 'log' => $log]);
    } finally {
        foreach ($pipes as $pp) {
            @fclose($pp);
        }
        proc_terminate($proc);
        proc_close($proc);
        at_rrmdir($base);
    }
}

/** Raw HTTP/1.0 request. Returns ['status'=>int, 'headers'=>[lowercase=>value], 'json'=>?array, 'raw'=>string]. */
function at_http(array $ctx, string $method, string $target, string $body = '', array $headers = []): array
{
    $s = fsockopen('127.0.0.1', $ctx['port'], $errno, $errstr, 5);
    if ($s === false) {
        throw new RuntimeException("connect failed: $errstr");
    }
    stream_set_timeout($s, 20);
    $req = "$method $target HTTP/1.0\r\nHost: 127.0.0.1:{$ctx['port']}\r\nConnection: close\r\n";
    foreach ($headers as $k => $v) {
        $req .= "$k: $v\r\n";
    }
    $req .= 'Content-Length: ' . strlen($body) . "\r\n\r\n";
    fwrite($s, $req);
    for ($off = 0; $off < strlen($body); $off += 65536) {
        fwrite($s, substr($body, $off, 65536));
    }
    $resp = stream_get_contents($s);
    fclose($s);
    [$head, $payload] = array_pad(explode("\r\n\r\n", (string) $resp, 2), 2, '');
    $lines = explode("\r\n", $head);
    preg_match('#^HTTP/\S+ (\d{3})#', $lines[0] ?? '', $m);
    $hs = [];
    foreach (array_slice($lines, 1) as $l) {
        if (strpos($l, ':') !== false) {
            [$k, $v] = explode(':', $l, 2);
            $hs[strtolower(trim($k))] = trim($v);
        }
    }
    $json = json_decode($payload, true);
    return ['status' => (int) ($m[1] ?? 0), 'headers' => $hs, 'json' => is_array($json) ? $json : null, 'raw' => $payload];
}

/**
 * Signed request. $opt: ts (int), signPath (sign a different path), headers (extra),
 * tamper (body actually sent instead of the signed one).
 */
function at_signed(array $ctx, string $method, string $target, string $body = '', array $opt = []): array
{
    $path = explode('?', $target, 2)[0];
    $ts = $opt['ts'] ?? time();
    $sig = sign_request(API_T_SECRET, $ts, $method, $opt['signPath'] ?? $path, $body);
    $h = ($opt['headers'] ?? []) + ['X-Guide-Timestamp' => (string) $ts, 'X-Guide-Signature' => $sig];
    return at_http($ctx, $method, $target, $opt['tamper'] ?? $body, $h);
}

function at_guide(): array
{
    return json_decode((string) file_get_contents(__DIR__ . '/fixtures/minimal.json'), true);
}

function at_json(array $g): string
{
    return json_encode($g, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** A tiny well formed JPEG that photo_accept takes. */
function at_jpeg(): string
{
    $app0 = "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    $dqt = "\xFF\xDB\x00\x05\x00\x01\x02";
    $sos = "\xFF\xDA\x00\x04\x00\x00";
    return "\xFF\xD8" . $app0 . $dqt . $sos . "\x12\x34\x56" . "\xFF\xD9";
}

function at_upload(array $ctx, string $slug, string $name, ?string $bytes = null, string $type = 'image/jpeg'): array
{
    return at_signed($ctx, 'POST', "/api/guides/$slug/photos?name=" . rawurlencode($name), $bytes ?? at_jpeg(), [
        'headers' => ['Content-Type' => $type],
    ]);
}

function at_dir_entries(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..', 'api.log', 'api.log.1']));
}

test('api: full happy path', function () {
    at_with_server(function ($ctx) {
        $g = at_guide();
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g));
        eq($r['status'], 200, 'put ' . $r['raw']);
        eq($r['json']['ok'], true);
        eq($r['json']['problems'], [], 'minimal guide has no problems');
        eq($r['headers']['content-type'] ?? '', 'application/json; charset=utf-8');
        eq($r['headers']['cache-control'] ?? '', 'no-store');
        eq($r['headers']['x-robots-tag'] ?? '', 'noindex');

        foreach (['a1.jpg', 'a2.jpg', 'a3.jpg'] as $name) {
            $u = at_upload($ctx, 'apto-1204', $name);
            eq($u['status'], 200, 'upload ' . $u['raw']);
            eq($u['json'], ['ok' => true, 'file' => $name]);
        }

        $p = at_signed($ctx, 'POST', '/api/guides/apto-1204/publish');
        eq($p['status'], 200, 'publish ' . $p['raw']);
        eq($p['json']['url'], 'https://delventto.cloud/apto-1204/');
        $v1 = $p['json']['version'];
        ok(is_string($v1) && $v1 !== '', 'version');

        $get = at_signed($ctx, 'GET', '/api/guides/apto-1204');
        eq($get['status'], 200);
        eq($get['json']['exists'], true);
        eq($get['json']['published'], $g);
        eq($get['json']['draft'], $g);
        eq(array_column($get['json']['versions'], 'id'), [$v1]);
        ok(isset($get['json']['versions'][0]['publishedAt']), 'publishedAt');
        eq($get['json']['url'], 'https://delventto.cloud/apto-1204/');

        $g2 = $g;
        $g2['tagline']['es'] = 'Versión dos del apartamento.';
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g2))['status'], 200);
        $p2 = at_signed($ctx, 'POST', '/api/guides/apto-1204/publish');
        eq($p2['status'], 200, 'publish 2');
        $v2 = $p2['json']['version'];
        ok($v2 !== $v1, 'new version');

        $rb = at_signed($ctx, 'POST', '/api/guides/apto-1204/rollback');
        eq($rb['status'], 200, 'rollback ' . $rb['raw']);
        eq($rb['json']['version'], $v1);
        eq($rb['json']['url'], 'https://delventto.cloud/apto-1204/');
        eq(at_signed($ctx, 'GET', '/api/guides/apto-1204')['json']['published']['tagline']['es'], $g['tagline']['es']);

        $rb2 = at_signed($ctx, 'POST', '/api/guides/apto-1204/rollback', '{"version":"' . $v2 . '"}');
        eq($rb2['status'], 200, 'rollback to named');
        eq($rb2['json']['version'], $v2);

        $un = at_signed($ctx, 'POST', '/api/guides/apto-1204/unpublish');
        eq($un['status'], 200);
        eq($un['json'], ['ok' => true]);
        $after = at_signed($ctx, 'GET', '/api/guides/apto-1204')['json'];
        eq($after['published'], null);
        eq(count($after['versions']), 2, 'versions kept');
        eq(at_signed($ctx, 'POST', '/api/guides/apto-1204/unpublish')['status'], 200, 'unpublish repeats');

        $log = (string) file_get_contents($ctx['data'] . '/api.log');
        ok(preg_match('#^\S+ PUT /api/guides/apto-1204/draft 200$#m', $log) === 1, 'log line: ' . $log);
        ok(preg_match('#^\S+ POST /api/guides/apto-1204/photos 200$#m', $log) === 1, 'photo log line without query');
        ok(strpos($log, 'Ana y Luis') === false && strpos($log, 'a1.jpg') === false, 'no body or query in log');
        ok(stripos($log, 'x-guide') === false, 'no headers in log');
    });
});

test('api: unknown guide reads as not existing', function () {
    at_with_server(function ($ctx) {
        $r = at_signed($ctx, 'GET', '/api/guides/apto-7777');
        eq($r['status'], 200);
        eq($r['json']['exists'], false);
        eq($r['json']['draft'], null);
        eq($r['json']['versions'], []);
        eq(at_dir_entries($ctx['data']), [], 'nothing created by a read');
    });
});

test('api: unsigned PUT is 401 and creates no files', function () {
    at_with_server(function ($ctx) {
        $r = at_http($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json(at_guide()));
        eq($r['status'], 401);
        eq($r['json']['ok'], false);
        eq(at_dir_entries($ctx['data']), [], 'no guide data written');
    });
});

test('api: stale timestamp is 401', function () {
    at_with_server(function ($ctx) {
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json(at_guide()), ['ts' => time() - 301]);
        eq($r['status'], 401);
        eq(at_dir_entries($ctx['data']), []);
    });
});

test('api: body edited after signing is 401', function () {
    at_with_server(function ($ctx) {
        $body = at_json(at_guide());
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', $body, ['tamper' => str_replace('Ana', 'Eva', $body)]);
        eq($r['status'], 401);
        eq(at_dir_entries($ctx['data']), []);
    });
});

test('api: signature for another slug path is 401', function () {
    at_with_server(function ($ctx) {
        $body = at_json(at_guide());
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', $body, ['signPath' => '/api/guides/apto-5555/draft']);
        eq($r['status'], 401);
        eq(at_dir_entries($ctx['data']), []);
    });
});

test('api: reserved, traversal and malformed slugs are 400', function () {
    at_with_server(function ($ctx) {
        $body = at_json(at_guide());
        foreach (['apartasuite-613t2', '../etc', 'Apto-1204', 'a.b', 'tests', '_guide', '%2e%2e'] as $slug) {
            $r = at_signed($ctx, 'PUT', "/api/guides/$slug/draft", $body);
            eq($r['status'], 400, "slug $slug");
            eq($r['json']['ok'] ?? null, false, "slug $slug json");
        }
        eq(at_signed($ctx, 'GET', '/api/guides/../etc')['status'], 400, 'GET ../etc');
        eq(at_dir_entries($ctx['data']), [], 'nothing written');
        ok(!file_exists(dirname($ctx['data']) . '/etc'), 'no traversal');
    });
});

test('api: draft that is not JSON or names another slug is 400', function () {
    at_with_server(function ($ctx) {
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', '{not json')['status'], 400, 'not json');
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', '[1,2]')['status'], 400, 'list');
        $g = at_guide();
        $g['slug'] = 'apto-5555';
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g))['status'], 400, 'slug mismatch');
        eq(at_dir_entries($ctx['data']), [], 'nothing saved');
    });
});

test('api: draft with problems is saved and problems returned', function () {
    at_with_server(function ($ctx) {
        $g = at_guide();
        $g['hosts']['whatsapp'] = '3001234567';
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g));
        eq($r['status'], 200);
        ok(in_array('hosts.whatsapp', array_column($r['json']['problems'], 'field'), true), 'whatsapp problem');
        eq(at_signed($ctx, 'GET', '/api/guides/apto-1204')['json']['draft']['hosts']['whatsapp'], '3001234567');
        $p = at_signed($ctx, 'POST', '/api/guides/apto-1204/publish');
        eq($p['status'], 409, 'publish refused');
        ok(in_array('hosts.whatsapp', array_column($p['json']['problems'], 'field'), true));
    });
});

test('api: publish with a missing photo is 409 naming the file', function () {
    at_with_server(function ($ctx) {
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json(at_guide()))['status'], 200);
        at_upload($ctx, 'apto-1204', 'a1.jpg');
        at_upload($ctx, 'apto-1204', 'a2.jpg');
        $p = at_signed($ctx, 'POST', '/api/guides/apto-1204/publish');
        eq($p['status'], 409, $p['raw']);
        eq($p['json']['ok'], false);
        $hit = array_values(array_filter($p['json']['problems'], fn ($x) => $x['field'] === 'photos.2.file'));
        eq(count($hit), 1, 'problem on photos.2.file');
        ok(strpos($hit[0]['message'], 'a3.jpg') !== false, 'message names a3.jpg');
        eq(at_signed($ctx, 'GET', '/api/guides/apto-1204')['json']['published'], null, 'not published');
    });
});

test('api: photo refusals', function () {
    at_with_server(function ($ctx) {
        $r = at_upload($ctx, 'apto-1204', 'x.gif', 'GIF89a', 'image/gif');
        eq($r['status'], 415, 'gif type');
        $r = at_upload($ctx, 'apto-1204', 'x.jpg', "hola, no soy una foto\n");
        eq($r['status'], 400, 'text named jpg');
        ok(is_string($r['json']['error'] ?? null) && $r['json']['error'] !== '', 'photo_accept message');
        $big = str_repeat('a', 8 * 1024 * 1024 + 1);
        $r = at_upload($ctx, 'apto-1204', 'big.jpg', $big);
        eq($r["status"], 413, "over 8 MB " . substr($r["raw"], 0, 600));
        eq(at_dir_entries($ctx['data']), [], 'nothing stored');
    });
});

test('api: wrong method is 405, unknown action 404', function () {
    at_with_server(function ($ctx) {
        $r = at_signed($ctx, 'GET', '/api/guides/apto-1204/publish');
        eq($r['status'], 405);
        eq($r['json']['ok'], false);
        eq(at_signed($ctx, 'POST', '/api/guides/apto-1204/delete')['status'], 404, 'unknown action');
        eq(at_signed($ctx, 'POST', '/api/guides/apto-1204/rollback', '{"version":5}')['status'], 400, 'bad rollback body');
        eq(at_signed($ctx, 'POST', '/api/guides/apto-1204/rollback')['status'], 409, 'nothing to roll back');
    });
});

test('api: a filesystem failure with display_errors on is a clean JSON 500 with no paths', function () {
    at_with_server(function ($ctx) {
        // .lock is a folder, so fopen() in the store warns and fails.
        mkdir($ctx['data'] . '/apto-1204/.lock', 0700, true);
        $r = at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json(at_guide()));
        eq($r['status'], 500, 'status, body: ' . substr($r['raw'], 0, 300));
        $json = json_decode($r['raw'], true);
        ok(is_array($json), 'whole body is JSON: ' . substr($r['raw'], 0, 300));
        eq($json['ok'], false);
        eq($r['headers']['content-type'] ?? '', 'application/json; charset=utf-8');
        $raw = str_replace('\\/', '/', $r['raw']);
        $leaks = [$ctx['data'], str_replace('/', '\\', $ctx['data']), basename(dirname($ctx['data'])), 'store.php', 'Warning'];
        foreach ($leaks as $leak) {
            ok(stripos($raw, $leak) === false, 'body leaks ' . $leak);
        }
    }, '1');
});

test('api: DELETE photo removes unused files and refuses used ones', function () {
    at_with_server(function ($ctx) {
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json(at_guide()))['status'], 200);
        foreach (['a1.jpg', 'a2.jpg', 'a3.jpg', 'candado.jpg'] as $name) {
            eq(at_upload($ctx, 'apto-1204', $name)['status'], 200);
        }
        $photos = $ctx['data'] . '/apto-1204/photos';

        eq(at_http($ctx, 'DELETE', '/api/guides/apto-1204/photos/candado.jpg')['status'], 401, 'unsigned');
        ok(is_file($photos . '/candado.jpg'), 'unsigned deletes nothing');

        $r = at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos/candado.jpg');
        eq($r['status'], 200, $r['raw']);
        eq($r['json'], ['ok' => true]);
        ok(!file_exists($photos . '/candado.jpg'), 'file gone');
        eq(at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos/candado.jpg')['status'], 404, 'already gone');
        eq(at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos/Mal..jpg')['status'], 404, 'malformed name');

        $r = at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos/a1.jpg');
        eq($r['status'], 409, 'used by the draft');
        ok(is_string($r['json']['error'] ?? null) && strpos($r['json']['error'], 'guía') !== false, 'Spanish message: ' . $r['raw']);
        ok(is_file($photos . '/a1.jpg'), 'used file kept');

        $r = at_signed($ctx, 'POST', '/api/guides/apto-1204/photos/a1.jpg');
        eq($r['status'], 405, 'POST on a photo');
        eq($r['headers']['allow'] ?? null, 'DELETE');
        eq(at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos')['status'], 405, 'DELETE on the collection');
        eq(at_signed($ctx, 'DELETE', '/api/guides/apto-1204/draft/x.jpg')['status'], 404, 'no file under draft');
        eq(at_signed($ctx, 'DELETE', '/api/guides/apto-1204/photos/a1.jpg/x')['status'], 404, 'too deep');
    });
});

test('api: rollback answers with the restored guide also in the draft', function () {
    at_with_server(function ($ctx) {
        $g = at_guide();
        eq(at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g))['status'], 200);
        foreach (['a1.jpg', 'a2.jpg', 'a3.jpg'] as $name) {
            at_upload($ctx, 'apto-1204', $name);
        }
        $v1 = at_signed($ctx, 'POST', '/api/guides/apto-1204/publish')['json']['version'];
        $g2 = $g;
        $g2['tagline']['es'] = 'Versión dos.';
        at_signed($ctx, 'PUT', '/api/guides/apto-1204/draft', at_json($g2));
        at_signed($ctx, 'POST', '/api/guides/apto-1204/publish');
        eq(at_signed($ctx, 'POST', '/api/guides/apto-1204/rollback')['json']['version'], $v1);
        $s = at_signed($ctx, 'GET', '/api/guides/apto-1204')['json'];
        eq($s['draft'], $g, 'draft reset');
        eq($s['published'], $g);
    });
});

// ---- base64 JSON photo upload (hosts that reject binary POST bodies) ----

function at_upload_json(array $ctx, string $slug, array $payload, array $opt = []): array
{
    return at_signed($ctx, 'POST', "/api/guides/$slug/photos", json_encode($payload), [
        'headers' => ['Content-Type' => 'application/json'],
    ] + $opt);
}

test('api: JSON base64 photo upload stores the photo', function () {
    at_with_server(function ($ctx) {
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'j1.jpg', 'type' => 'image/jpeg', 'data' => base64_encode(at_jpeg())]);
        eq($r['status'], 200, $r['raw']);
        eq($r['json'], ['ok' => true, 'file' => 'j1.jpg']);
        ok(is_file($ctx['data'] . '/apto-1204/photos/j1.jpg'), 'stored');
        // the body name wins over ?name=
        $r = at_signed($ctx, 'POST', '/api/guides/apto-1204/photos?name=query', json_encode(['name' => 'body', 'type' => 'image/jpeg', 'data' => base64_encode(at_jpeg())]), ['headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        eq($r['status'], 200, $r['raw']);
        eq($r['json'], ['ok' => true, 'file' => 'body.jpg']);
    });
});

test('api: JSON photo upload rejects bad input', function () {
    at_with_server(function ($ctx) {
        $good = ['name' => 'x', 'type' => 'image/jpeg', 'data' => base64_encode(at_jpeg())];
        $r = at_upload_json($ctx, 'apto-1204', ['data' => '@@@not base64@@@'] + $good);
        eq($r['status'], 400, 'invalid base64');
        ok(is_string($r['json']['error'] ?? null));
        foreach (['name', 'type', 'data'] as $k) {
            $p = $good;
            unset($p[$k]);
            eq(at_upload_json($ctx, 'apto-1204', $p)['status'], 400, "missing $k");
        }
        eq(at_upload_json($ctx, 'apto-1204', ['data' => 123] + $good)['status'], 400, 'non string data');
        $r = at_signed($ctx, 'POST', '/api/guides/apto-1204/photos', '{not json', ['headers' => ['Content-Type' => 'application/json']]);
        eq($r['status'], 400, 'broken json');
        eq(at_upload_json($ctx, 'apto-1204', ['type' => 'image/gif'] + $good)['status'], 415, 'wrong type');
        eq(at_upload_json($ctx, 'apto-1204', ['type' => 'image/png'] + $good)['status'], 400, 'type not matching bytes');
        ok(!is_dir($ctx['data'] . '/apto-1204/photos') || at_dir_entries($ctx['data'] . '/apto-1204/photos') === [], 'nothing stored');
    });
});

test('api: JSON photo upload enforces 8 MB on the decoded bytes', function () {
    at_with_server(function ($ctx) {
        $big = str_repeat('A', 8 * 1024 * 1024 + 1);
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'big', 'type' => 'image/jpeg', 'data' => base64_encode($big)]);
        eq($r['status'], 413, substr($r['raw'], 0, 300));
    });
});

test('api: unsigned JSON photo upload is 401 and writes nothing', function () {
    at_with_server(function ($ctx) {
        $body = json_encode(['name' => 'u', 'type' => 'image/jpeg', 'data' => base64_encode(at_jpeg())]);
        $r = at_http($ctx, 'POST', '/api/guides/apto-1204/photos', $body, ['Content-Type' => 'application/json']);
        eq($r['status'], 401);
        ok(!is_dir($ctx['data'] . '/apto-1204'), 'nothing written');
        // a signature over a different name is rejected too (the name is in the signed body)
        $r = at_signed($ctx, 'POST', '/api/guides/apto-1204/photos', $body, ['headers' => ['Content-Type' => 'application/json'], 'tamper' => str_replace('"u"', '"v"', $body)]);
        eq($r['status'], 401);
        ok(!is_dir($ctx['data'] . '/apto-1204'), 'nothing written after tamper');
    });
});

test('api: JSON hex photo upload stores the photo', function () {
    at_with_server(function ($ctx) {
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'h1', 'type' => 'image/jpeg', 'encoding' => 'hex', 'data' => bin2hex(at_jpeg())]);
        eq($r['status'], 200, $r['raw']);
        eq($r['json'], ['ok' => true, 'file' => 'h1.jpg']);
        ok(is_file($ctx['data'] . '/apto-1204/photos/h1.jpg'), 'stored');
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'h2', 'type' => 'image/jpeg', 'encoding' => 'hex', 'data' => strtoupper(bin2hex(at_jpeg()))]);
        eq($r['status'], 200, 'uppercase hex ' . $r['raw']);
        eq(at_upload_json($ctx, 'apto-1204', ['name' => 'h3', 'type' => 'image/jpeg', 'encoding' => 'base64', 'data' => base64_encode(at_jpeg())])['status'], 200, 'explicit base64');
    });
});

test('api: JSON hex photo upload rejects bad input', function () {
    at_with_server(function ($ctx) {
        $good = ['name' => 'x', 'type' => 'image/jpeg', 'encoding' => 'hex'];
        foreach (['abc', 'zz11', 'ff d8', ''] as $bad) {
            $r = at_upload_json($ctx, 'apto-1204', $good + ['data' => $bad]);
            eq($r['status'], 400, "bad hex '$bad'");
            ok(is_string($r['json']['error'] ?? null));
        }
        eq(at_upload_json($ctx, 'apto-1204', ['encoding' => 'rot13', 'data' => 'aa'] + $good)['status'], 400, 'unknown encoding');
        ok(!is_dir($ctx['data'] . '/apto-1204/photos') || at_dir_entries($ctx['data'] . '/apto-1204/photos') === [], 'nothing stored');
    });
});

test('api: JSON hex photo upload enforces 8 MB on the decoded bytes', function () {
    at_with_server(function ($ctx) {
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'big', 'type' => 'image/jpeg', 'encoding' => 'hex', 'data' => bin2hex(str_repeat('A', 8 * 1024 * 1024 + 1))]);
        eq($r['status'], 413, substr($r['raw'], 0, 300));
    });
});

// ---- chunked photo upload (the host alters request bodies above about 2.6 MB) ----

function at_big_jpeg(int $pad): string
{
    $j = at_jpeg();
    return substr($j, 0, -2) . str_repeat("\x12", $pad) . "\xFF\xD9";
}

function at_part(array $ctx, string $upload, int $part, int $parts, string $chunk, string $name = 'big', array $extra = [], array $opt = []): array
{
    return at_upload_json($ctx, 'apto-1204', ['name' => $name, 'type' => 'image/jpeg', 'encoding' => 'hex',
        'upload' => $upload, 'part' => $part, 'parts' => $parts, 'data' => bin2hex($chunk)] + $extra, $opt);
}

test('api: 3 part upload of a photo over 2 MB assembles and stores it', function () {
    at_with_server(function ($ctx) {
        $img = at_big_jpeg(2300000);
        $chunks = str_split($img, 1048576);
        eq(count($chunks), 3);
        $r = at_part($ctx, 'abcd1234ef', 1, 3, $chunks[0]);
        eq([$r['status'], $r['json']], [200, ['ok' => true, 'received' => 1, 'expected' => 2]], $r['raw']);
        $r = at_part($ctx, 'abcd1234ef', 2, 3, $chunks[1]);
        eq($r['json'], ['ok' => true, 'received' => 2, 'expected' => 3]);
        ok(is_dir($ctx['data'] . '/apto-1204/.uploads/abcd1234ef'), 'parts kept while uploading');
        $r = at_part($ctx, 'abcd1234ef', 3, 3, $chunks[2]);
        eq([$r['status'], $r['json']], [200, ['ok' => true, 'file' => 'big.jpg']], $r['raw']);
        eq(strlen((string) file_get_contents($ctx['data'] . '/apto-1204/photos/big.jpg')), strlen($img), 'assembled bytes');
        ok(!is_dir($ctx['data'] . '/apto-1204/.uploads/abcd1234ef'), 'upload dir deleted');
    });
});

test('api: chunk order, resend and bad ids', function () {
    at_with_server(function ($ctx) {
        $c = str_repeat("\x12", 1000);
        $r = at_part($ctx, 'abcd1234ef', 2, 3, $c);
        eq([$r['status'], $r['json']['expected'] ?? null], [409, 1], 'part 2 first ' . $r['raw']);
        eq(at_part($ctx, 'abcd1234ef', 1, 3, $c)['status'], 200);
        $r = at_part($ctx, 'abcd1234ef', 3, 3, $c);
        eq([$r['status'], $r['json']['expected'] ?? null, $r['json']['error'] ?? null], [409, 2, 'Parte fuera de orden'], 'skip ahead');
        $r = at_part($ctx, 'abcd1234ef', 1, 3, $c);
        eq([$r['status'], $r['json']], [200, ['ok' => true, 'received' => 1, 'expected' => 2]], 'resend last is idempotent');
        eq(at_part($ctx, 'abcd1234ef', 2, 3, $c)['json']['expected'], 3);
        $r = at_part($ctx, 'abcd1234ef', 2, 3, $c);
        eq([$r['status'], $r['json']['expected']], [200, 3], 'resend 2');
        eq(at_part($ctx, 'abcd1234ef', 1, 3, $c)['status'], 409, 'older than the last part');
        foreach (['short', 'UPPERCASE12', 'has-dash-123', str_repeat('a', 41)] as $bad) {
            eq(at_part($ctx, $bad, 1, 2, $c)['status'], 400, "bad id $bad");
        }
        eq(at_part($ctx, 'abcd1234ef', 1, 17, $c)['status'], 400, 'parts over 16');
        eq(at_part($ctx, 'abcd1234ef', 0, 2, $c)['status'], 400, 'part 0');
        eq(at_part($ctx, 'abcd1234ef', 3, 2, $c)['status'], 400, 'part over parts');
    });
});

test('api: chunk size limits', function () {
    at_with_server(function ($ctx) {
        eq(at_part($ctx, 'abcd1234ef', 1, 2, str_repeat('A', 1048576 + 1))['status'], 413, 'part over 1 MB');
        $r = at_upload_json($ctx, 'apto-1204', ['name' => 'x', 'type' => 'image/jpeg', 'data' => base64_encode(str_repeat('A', 1048576 + 1))]);
        eq($r['status'], 413, 'single part over 1 MB');
        // 9 parts are over the 8 MB assembled limit: the last one is refused
        $mb = str_repeat('A', 1048576);
        for ($i = 1; $i <= 8; $i++) {
            eq(at_part($ctx, 'bbbb1234ef', $i, 9, $mb)['status'], 200, "part $i");
        }
        $r = at_part($ctx, 'bbbb1234ef', 9, 9, 'A');
        eq($r['status'], 413, 'assembled over 8 MB ' . $r['raw']);
        ok(!is_dir($ctx['data'] . '/apto-1204/.uploads/bbbb1234ef'), 'refused upload cleaned up');
        ok(!is_dir($ctx['data'] . '/apto-1204/photos') || at_dir_entries($ctx['data'] . '/apto-1204/photos') === [], 'nothing stored');
        // exactly 8 MB assembled passes the size gate (then fails as not a photo)
        for ($i = 1; $i < 8; $i++) {
            at_part($ctx, 'cccc1234ef', $i, 8, $mb);
        }
        eq(at_part($ctx, 'cccc1234ef', 8, 8, $mb)['status'], 400, 'exactly 8 MB reaches photo_accept');
    });
});

test('api: unsigned chunk is 401 and writes nothing', function () {
    at_with_server(function ($ctx) {
        $body = json_encode(['name' => 'u', 'type' => 'image/jpeg', 'encoding' => 'hex', 'upload' => 'abcd1234ef', 'part' => 1, 'parts' => 2, 'data' => 'ffd8']);
        eq(at_http($ctx, 'POST', '/api/guides/apto-1204/photos', $body, ['Content-Type' => 'application/json'])['status'], 401);
        ok(!is_dir($ctx['data'] . '/apto-1204'), 'nothing written');
    });
});

test('api: stale uploads are swept when a new upload starts', function () {
    at_with_server(function ($ctx) {
        $up = $ctx['data'] . '/apto-1204/.uploads';
        mkdir($up . '/oldold1234', 0700, true);
        file_put_contents($up . '/oldold1234/meta.json', json_encode(['name' => 'x', 'type' => 'image/jpeg', 'parts' => 2, 'next' => 2, 'created' => time() - 7200]));
        mkdir($up . '/nometa1234', 0700, true);
        touch($up . '/nometa1234', time() - 7200);
        mkdir($up . '/fresh12345', 0700, true);
        file_put_contents($up . '/fresh12345/meta.json', json_encode(['name' => 'x', 'type' => 'image/jpeg', 'parts' => 2, 'next' => 2, 'created' => time()]));
        eq(at_part($ctx, 'abcd1234ef', 1, 2, str_repeat('A', 10))['status'], 200);
        ok(!is_dir($up . '/oldold1234') && !is_dir($up . '/nometa1234'), 'old dirs removed');
        ok(is_dir($up . '/fresh12345') && is_dir($up . '/abcd1234ef'), 'fresh ones kept');
    });
});

test('store: .uploads never resolves as a photo', function () {
    $dir = sys_get_temp_dir() . '/guide-up-test-' . bin2hex(random_bytes(6));
    mkdir($dir . '/apto-1204/.uploads/abcd1234ef', 0700, true);
    file_put_contents($dir . '/apto-1204/.uploads/abcd1234ef/part-1', 'x');
    putenv('GUIDE_DATA_DIR=' . $dir);
    try {
        require_once __DIR__ . '/../_guide/lib/store.php';
        foreach (['.uploads', '.uploads/abcd1234ef/part-1', '../.uploads/x.jpg', 'part-1'] as $f) {
            eq(store_photo_path('apto-1204', $f), null, $f);
        }
    } finally {
        putenv('GUIDE_DATA_DIR');
        at_rrmdir($dir);
    }
});
