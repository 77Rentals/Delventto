<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/auth.php';
require_once __DIR__ . '/../_guide/lib/store.php';
require_once __DIR__ . '/../_guide/lib/building.php';
require_once __DIR__ . '/../_guide/lib/validate.php';
require_once __DIR__ . '/../_guide/lib/themes.php';
require_once __DIR__ . '/../_guide/lib/view.php';

// Renderer tests. Unit tests call render_guide()/render_gate() directly with the real
// building file; integration tests run render.php and photo.php behind the dev router
// (helpers at_port_open, at_http and at_rrmdir come from api_test.php).

const RT_SECRET = 'render-test-secret-render-test-secret-0123';
const RT_SECTIONS = ['welcome', 'checkin', 'tour', 'gallery', 'amenities-building', 'map', 'places', 'suite-details', 'house-rules', 'hosts-sec', 'sos'];

function rt_fixture(string $name): array
{
    $g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/' . $name . '.json'), true);
    if (!is_array($g)) {
        throw new RuntimeException('bad fixture ' . $name);
    }
    return $g;
}

function rt_building(): array
{
    $old = getenv('GUIDE_BUILDINGS_DIR');
    putenv('GUIDE_BUILDINGS_DIR');
    try {
        $b = guide_building('delventto');
    } finally {
        if ($old !== false) {
            putenv('GUIDE_BUILDINGS_DIR=' . $old);
        }
    }
    if ($b === null) {
        throw new RuntimeException('building file delventto.json missing or invalid');
    }
    return $b;
}

function rt_render(array $g, string $mode = 'published'): string
{
    return render_guide($g, rt_building(), $mode);
}

function rt_no_dashes(string $html, string $what): void
{
    ok(strpos($html, "\u{2014}") === false, "$what contains an em dash");
    ok(strpos($html, "\u{2013}") === false, "$what contains an en dash");
}

/** WCAG 2 contrast ratio of two #rrggbb colors. */
function rt_contrast(string $a, string $b): float
{
    $lum = function (string $hex): float {
        $hex = ltrim($hex, '#');
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        $l = array_map(function ($v) {
            $v /= 255;
            return $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
        }, $c);
        return 0.2126 * $l[0] + 0.7152 * $l[1] + 0.0722 * $l[2];
    };
    $x = $lum($a);
    $y = $lum($b);
    return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
}

/** ['caribe' => ['brand' => '#4AADA8', ...], ...] parsed from guide.css palette blocks. */
function rt_palettes_from_css(): array
{
    $css = (string) file_get_contents(__DIR__ . '/../_guide/assets/guide.css');
    preg_match_all('/\[data-palette="([a-z]+)"\]\s*\{([^}]*)\}/', $css, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as [, $name, $body]) {
        preg_match_all('/--([a-z0-9-]+)\s*:\s*([^;]+);/', $body, $vars, PREG_SET_ORDER);
        foreach ($vars as [, $k, $v]) {
            $out[$name][$k] = trim($v);
        }
    }
    return $out;
}

// ---------- themes and validator ----------

test('themes: 12 palettes and 10 motions, defaults first', function () {
    eq(GUIDE_PALETTES, ['caribe', 'macondo', 'arena', 'selva', 'atardecer', 'coral', 'noche', 'cafetal', 'buganvilia', 'bahia', 'tayrona', 'blanco']);
    eq(GUIDE_MOTIONS, ['marino', 'tortugas', 'burbujas', 'mariposas', 'colibries', 'aves', 'palmas', 'flores', 'luciernagas', 'ninguno']);
    eq(guide_theme([]), ['palette' => 'caribe', 'motion' => 'marino']);
    eq(guide_theme(['theme' => ['palette' => 'noche']]), ['palette' => 'noche', 'motion' => 'marino']);
    eq(guide_theme(['theme' => ['palette' => 'nope', 'motion' => 'nope']]), ['palette' => 'caribe', 'motion' => 'marino']);
});

test('themes: guide.css has a variable block for every palette', function () {
    $pal = rt_palettes_from_css();
    $need = ['brand', 'brand-deep', 'brand-dark', 'accent', 'accent-soft', 'accent-ink', 'label-ink', 'cream', 'ink', 'warm', 'line', 'deep', 'creature', 'creature-2', 'brand-rgb', 'brand-dark-rgb', 'accent-soft-rgb', 'hero-grad'];
    foreach (GUIDE_PALETTES as $p) {
        ok(isset($pal[$p]), "no block for palette $p");
        foreach ($need as $k) {
            ok(isset($pal[$p][$k]), "palette $p lacks --$k");
        }
        foreach (['brand' => 'brand-rgb', 'brand-dark' => 'brand-dark-rgb', 'accent-soft' => 'accent-soft-rgb'] as $hexKey => $rgbKey) {
            $hex = ltrim($pal[$p][$hexKey], '#');
            $rgb = hexdec(substr($hex, 0, 2)) . ',' . hexdec(substr($hex, 2, 2)) . ',' . hexdec(substr($hex, 4, 2));
            eq(str_replace(' ', '', $pal[$p][$rgbKey]), $rgb, "palette $p --$rgbKey must match --$hexKey");
        }
    }
    eq($pal['caribe']['brand'], '#4AADA8', 'caribe keeps 613T2 values');
    eq($pal['macondo']['brand'], '#8B3FA1', 'macondo keeps the Macondo purple');
});

test('themes: text on background contrast is at least 4.5:1', function () {
    $pal = rt_palettes_from_css();
    $w = '#FFFFFF';
    // [foreground, background, where]
    $core = [
        ['ink', 'cream', 'body text'],
        ['ink', $w, 'text on cards'],
        ['cream', 'brand-dark', 'top bar, footer and hosts text'],
    ];
    $full = array_merge($core, [
        ['brand-dark', 'accent', 'gate button, language toggle, step numbers'],
        ['cream', 'brand', 'gallery and PDF buttons, eyebrows and chips on cream'],
        ['accent-soft', 'brand-dark', 'hero eyebrow, hosts heading, footer heading'],
        ['accent-soft', 'brand-deep', 'check-in card heading'],
        ['brand-deep', $w, 'headings on cards'],
        [$w, 'brand', 'activity tags'],
        [$w, 'warm', 'food tags'],
        ['accent-ink', $w, 'place distance and menu links'],
        ['label-ink', 'accent-soft', 'WiFi box labels'],
        ['brand-dark', 'accent-soft', 'WiFi box values'],
    ]);
    foreach (GUIDE_PALETTES as $p) {
        // caribe and macondo are the owner's existing designs, kept as they are; only the core pairs bind them.
        $pairs = in_array($p, ['caribe', 'macondo'], true) ? $core : $full;
        foreach ($pairs as [$fg, $bg, $where]) {
            $a = $fg[0] === '#' ? $fg : $pal[$p][$fg];
            $b = $bg[0] === '#' ? $bg : $pal[$p][$bg];
            $r = rt_contrast($a, $b);
            ok($r >= 4.5, sprintf('%s: %s (%s on %s) is %.2f:1', $p, $where, $fg, $bg, $r));
        }
    }
});

test('themes: guide.js draws creatures for every motion except ninguno', function () {
    $js = (string) file_get_contents(__DIR__ . '/../_guide/assets/guide.js');
    foreach (GUIDE_MOTIONS as $m) {
        if ($m === 'ninguno') {
            continue;
        }
        ok(preg_match('/\b' . $m . '\s*:/', $js) === 1, "guide.js has no creatures for $m");
    }
    ok(strpos($js, 'prefers-reduced-motion') !== false, 'guide.js must respect prefers-reduced-motion');
});

// ---------- building file ----------

test('building: delventto.json has id, places and is unconfirmed', function () {
    $b = rt_building();
    eq($b['id'] ?? null, 'delventto');
    ok(array_key_exists('confirmedBy', $b) && $b['confirmedBy'] === null, 'confirmedBy must be null');
    ok(array_key_exists('confirmedAt', $b) && $b['confirmedAt'] === null, 'confirmedAt must be null');
    $keys = ['playa-del-ritmo', 'zazue', 'pizza-el-vomito', 'centro-historico', 'quinta-san-pedro', 'harrys', 'cambiante', 'holguer', 'la-samaria', 'crepes-carulla', 'el-rodadero', 'tayrona', 'playa-blanca', 'minca', 'marina', 'museo-oro'];
    eq(array_keys($b['places']), $keys);
    foreach ($b['places'] as $k => $pl) {
        foreach (['name', 'tag', 'distance', 'text'] as $f) {
            ok(isset($pl[$f]['es'], $pl[$f]['en']), "place $k lacks $f");
        }
    }
});

test('building: no em or en dashes anywhere in the building file', function () {
    rt_no_dashes((string) file_get_contents(__DIR__ . '/../_guide/buildings/delventto.json'), 'delventto.json');
});

test('building: both fixtures validate against the real building file', function () {
    $b = rt_building();
    foreach (['613t2', 'minimal'] as $f) {
        $g = rt_fixture($f);
        eq(validate_guide($g, $b, $g['slug']), [], "$f against delventto.json");
    }
});

// ---------- render_guide ----------

test('render: 613t2 has every 613T2 section', function () {
    $html = rt_render(rt_fixture('613t2'));
    foreach (RT_SECTIONS as $id) {
        ok(strpos($html, 'id="' . $id . '"') !== false, "missing section $id");
    }
    ok(strpos($html, 'Claro203a') !== false, 'WiFi network shown');
    ok(strpos($html, '/apartasuite-613t2/photo/img_kitchen.jpg') !== false, 'photos go through the photo route');
    ok(strpos($html, '/apartasuite-613t2/suite-tour.mp4') !== false, 'tour video');
    ok(strpos($html, 'Ninson y Natalia') !== false, 'host names');
    ok(strpos($html, 'Parque Nacional Natural Tayrona') !== false, 'selected building place');
});

test('render: section order follows 613T2', function () {
    $html = rt_render(rt_fixture('613t2'));
    $last = -1;
    foreach (RT_SECTIONS as $id) {
        $pos = strpos($html, 'id="' . $id . '"');
        ok($pos !== false && $pos > $last, "section $id out of order");
        $last = $pos;
    }
});

test('render: minimal guide leaves absent sections out cleanly', function () {
    $html = rt_render(rt_fixture('minimal'));
    ok(strpos($html, 'id="tour"') === false, 'no tour section');
    ok(stripos($html, 'pdf') === false, 'no PDF link');
    ok(strpos($html, 'class="place"') === false, 'no own-places cards');
    ok(strpos($html, 'id="places"') === false, 'no places section when nothing is picked');
    ok(strpos($html, 'wifi-box') === false, 'no WiFi box');
    ok(strpos($html, 'class="host-photo"') === false, 'no hosts photo');
    ok(strpos($html, 'ac-steps') === false, 'no A/C step');
    ok(preg_match('#<h[1-3][^>]*>(\s|<span[^>]*>\s*</span>|<em[^>]*>\s*</em>)*</h[1-3]>#', $html) !== 1, 'no empty heading');
    ok(preg_match('#<(p|strong|b|div class="place-meta")[^>]*>\s*</\1>#', $html) !== 1, 'no empty text element');
    foreach (['welcome', 'checkin', 'gallery', 'amenities-building', 'map', 'house-rules', 'hosts-sec', 'sos'] as $id) {
        ok(strpos($html, 'id="' . $id . '"') !== false, "minimal still has $id");
    }
});

test('render: building before-leaving defaults fill an empty list', function () {
    $html = rt_render(rt_fixture('minimal'));
    ok(strpos($html, 'Cierra la llave del gas') !== false, 'default before-leaving items');
});

test('render: captions and every string are escaped', function () {
    $g = rt_fixture('613t2');
    $g['photos'][0]['caption']['es'] = 'Cocina "&<" nueva';
    $g['welcome']['es'] = 'Hola <script>alert(1)</script>';
    $g['hosts']['names'] = 'Ana & <b>Luis</b>';
    $html = rt_render($g);
    ok(strpos($html, 'Cocina &quot;&amp;&lt;&quot; nueva') !== false, 'caption escaped');
    ok(strpos($html, '"&<"') === false, 'raw caption not present');
    ok(strpos($html, '<script>alert(1)') === false, 'welcome escaped');
    ok(strpos($html, '<b>Luis</b>') === false, 'host names escaped');
});

test('render: unsafe links are dropped', function () {
    $g = rt_fixture('613t2');
    $g['tourVideo']['url'] = '/\\evil.example/x.mp4';
    $g['pdf'] = ['url' => 'javascript:alert(1)'];
    $html = rt_render($g);
    ok(strpos($html, 'evil.example') === false, 'backslash path dropped');
    ok(strpos($html, 'javascript:alert') === false, 'javascript url dropped');
    ok(strpos($html, 'id="tour"') === false, 'tour section omitted when its link is unusable');
});

test('render: PDF and YouTube links', function () {
    $g = rt_fixture('613t2');
    $g['pdf'] = ['url' => 'https://example.com/guia.pdf'];
    $g['tourVideo'] = ['url' => 'https://www.youtube.com/watch?v=abcDEF12345'];
    $html = rt_render($g);
    ok(strpos($html, 'href="https://example.com/guia.pdf"') !== false, 'PDF link');
    ok(strpos($html, 'https://www.youtube-nocookie.com/embed/abcDEF12345') !== false, 'YouTube embed');
});

test('render: no dashes, noindex, no GATE_CODE, no door code', function () {
    foreach (['613t2', 'minimal'] as $f) {
        foreach (['published', 'preview'] as $mode) {
            $html = rt_render(rt_fixture($f), $mode);
            rt_no_dashes($html, "$f $mode");
            ok(strpos($html, '<meta name="robots" content="noindex') !== false, "$f noindex meta");
            ok(strpos($html, 'GATE_CODE') === false, "$f has GATE_CODE");
        }
    }
    $html = rt_render(rt_fixture('613t2'));
    ok(strpos($html, 'gate-input') === false, 'no gate inside the guide');
});

test('render: theme attributes', function () {
    $g = rt_fixture('minimal');
    $html = rt_render($g);
    ok(strpos($html, 'data-palette="caribe"') !== false, 'default palette');
    ok(strpos($html, 'data-motion="marino"') !== false, 'default motion');
    $g['theme'] = ['palette' => 'selva', 'motion' => 'ninguno'];
    $html = rt_render($g);
    ok(strpos($html, 'data-palette="selva"') !== false, 'selva palette');
    ok(strpos($html, 'data-motion="ninguno"') !== false, 'ninguno motion');
});

test('render: preview mode shows a draft banner', function () {
    ok(strpos(rt_render(rt_fixture('minimal'), 'preview'), 'preview-banner') !== false, 'banner in preview');
    ok(strpos(rt_render(rt_fixture('minimal'), 'published'), 'preview-banner') === false, 'no banner when published');
});

test('render: t() and h()', function () {
    eq(h('a"&<'), 'a&quot;&amp;&lt;');
    eq(h(null), '');
    eq(t(['es' => 'Hola & <x>', 'en' => 'Hi "y"']), '<span data-es="Hola &amp; &lt;x&gt;" data-en="Hi &quot;y&quot;">Hola &amp; &lt;x&gt;</span>');
});

test('gate: same look, posts name and code, shows no guide data', function () {
    $g = rt_fixture('613t2');
    $html = render_gate($g, rt_building(), false, '');
    ok(strpos($html, 'method="post"') !== false, 'form posts');
    ok(strpos($html, 'name="code"') !== false && strpos($html, 'name="name"') !== false, 'name and code fields');
    ok(strpos($html, 'gate-fluid') !== false, 'fluid canvas');
    ok(strpos($html, '<meta name="robots" content="noindex') !== false, 'noindex');
    ok(strpos($html, 'data-palette="caribe"') !== false, 'palette on gate');
    foreach (['613T2', 'Apartasuite', 'Claro203a', 'Ninson', 'img_kitchen', 'GATE_CODE', 'gate-error show'] as $leak) {
        ok(strpos($html, $leak) === false, "gate leaks $leak");
    }
    rt_no_dashes($html, 'gate');
    ok(strpos($html, '<span data-es="Edificio " data-en="">Edificio </span><em>Delventto</em><span data-es="" data-en=" Building"></span>') !== false, 'title halves per language: ' . $html);
    ok(strpos($html, 'Delventto Building"') === false && strpos($html, 'Edificio Delventto Building') === false, 'no ES and EN concatenation');
    $err = render_gate($g, rt_building(), true, 'Ana <b>');
    ok(strpos($err, 'gate-error show') !== false, 'error state');
    ok(strpos($err, 'Ana &lt;b&gt;') !== false, 'posted name escaped back');
});

// ---------- integration: render.php and photo.php behind the dev router ----------

/** Starts the dev router with a seeded data dir; $fn(ctx) gets port and data dir. */
function rt_with_server(callable $seed, callable $fn): void
{
    $base = sys_get_temp_dir() . '/guide-render-test-' . bin2hex(random_bytes(6));
    $data = $base . '/data';
    mkdir($data, 0700, true);
    $old = getenv('GUIDE_DATA_DIR');
    putenv('GUIDE_DATA_DIR=' . $data);
    try {
        $seed($data);
    } finally {
        putenv($old === false ? 'GUIDE_DATA_DIR' : 'GUIDE_DATA_DIR=' . $old);
    }

    $port = null;
    for ($p = 8800; $p <= 8840; $p++) {
        if (!at_port_open($p)) {
            $port = $p;
            break;
        }
    }
    if ($port === null) {
        at_rrmdir($base);
        throw new RuntimeException('no free port in 8800..8840');
    }
    $env = getenv();
    $env['GUIDE_SECRET'] = RT_SECRET;
    $env['GUIDE_DATA_DIR'] = $data;
    unset($env['GUIDE_BUILDINGS_DIR']);
    $webroot = dirname(__DIR__);
    $cmd = [PHP_BINARY, '-d', 'display_errors=1', '-S', '127.0.0.1:' . $port, '-t', $webroot, $webroot . '/_guide/dev-router.php'];
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
        $fn(['port' => $port, 'data' => $data]);
    } finally {
        foreach ($pipes as $pp) {
            @fclose($pp);
        }
        proc_terminate($proc);
        proc_close($proc);
        at_rrmdir($base);
    }
}

/** A tiny JPEG (bytes only matter for Content-Type and length here). */
function rt_jpeg(): string
{
    return "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xFF\xD9";
}

/** Publishes minimal.json under $slug with gate code $code and its 3 photos. */
function rt_publish(string $slug, string $code = '1204', bool $keepDraft = true): void
{
    $g = rt_fixture('minimal');
    $g['slug'] = $slug;
    $g['gateCode'] = $code;
    foreach (['a1', 'a2', 'a3'] as $n) {
        store_add_photo($slug, $n, rt_jpeg(), 'jpg');
    }
    store_put_draft($slug, $g);
    store_publish($slug);
    if (!$keepDraft) {
        unlink(rtrim(guide_data_dir(), '/\\') . '/' . $slug . '/draft.json');
    }
}

function rt_cookie(string $slug): array
{
    return ['Cookie' => gate_cookie_name($slug) . '=' . gate_token(RT_SECRET, $slug)];
}

function rt_post_code(array $ctx, string $target, string $code, string $name = 'Ana'): array
{
    $body = http_build_query(['name' => $name, 'code' => $code]);
    return at_http($ctx, 'POST', $target, $body, ['Content-Type' => 'application/x-www-form-urlencoded']);
}

test('render.php: unknown, unpublished, reserved and malformed slugs are 404', function () {
    rt_with_server(function () {
        store_put_draft('solo-borrador', rt_fixture('minimal'));
    }, function ($ctx) {
        eq(at_http($ctx, 'GET', '/no-existe/')['status'], 404, 'unknown');
        eq(at_http($ctx, 'GET', '/solo-borrador/')['status'], 404, 'draft only, no preview');
        eq(at_http($ctx, 'GET', '/images/')['status'], 404, 'reserved word');
        $r = at_http($ctx, 'GET', '/no-existe/');
        ok(strpos($r['raw'], 'C:\\') === false && strpos($r['raw'], '/tmp') === false, 'no paths in 404');
    });
});

test('render.php: gate first, nothing from the guide before it', function () {
    rt_with_server(function () {
        rt_publish('apto-1204');
    }, function ($ctx) {
        $r = at_http($ctx, 'GET', '/apto-1204/');
        eq($r['status'], 200);
        eq($r['headers']['x-robots-tag'] ?? null, 'noindex');
        ok(strpos($r['raw'], 'name="code"') !== false, 'gate form');
        ok(strpos($r['raw'], '<meta name="robots" content="noindex') !== false, 'noindex meta');
        foreach (['id="welcome"', 'Ana y Luis', '/photo/', 'Bienvenido a tu apartamento', 'Apto 1204', 'Apt 1204'] as $leak) {
            ok(strpos($r['raw'], $leak) === false, "gate leaks $leak");
        }
        ok(!isset($r['headers']['set-cookie']), 'no cookie before the code');
    });
});

test('render.php: wrong code re-renders the gate with the error', function () {
    rt_with_server(function () {
        rt_publish('apto-1204');
    }, function ($ctx) {
        $r = rt_post_code($ctx, '/apto-1204/', '9999');
        eq($r['status'], 200);
        ok(strpos($r['raw'], 'gate-error show') !== false, 'error shown');
        ok(!isset($r['headers']['set-cookie']), 'no cookie on a wrong code');
        ok(strpos($r['raw'], 'id="welcome"') === false, 'no guide');
    });
});

test('render.php: right code (any case) sets the cookie and redirects', function () {
    rt_with_server(function () {
        rt_publish('apto-12b', '12B');
    }, function ($ctx) {
        $r = rt_post_code($ctx, '/apto-12b/', ' 12b ');
        eq($r['status'], 303);
        eq($r['headers']['location'] ?? null, '/apto-12b/');
        $c = $r['headers']['set-cookie'] ?? '';
        ok(strpos($c, gate_cookie_name('apto-12b') . '=' . gate_token(RT_SECRET, 'apto-12b')) === 0, 'cookie value: ' . $c);
        foreach (['HttpOnly', 'SameSite=Lax', 'Path=/apto-12b/', 'Max-Age=2592000'] as $part) {
            ok(stripos($c, $part) !== false, "cookie lacks $part: $c");
        }
        ok(stripos($c, 'Secure') === false, 'no Secure flag over plain http');
        $r = rt_post_code($ctx, '/apto-12b/?preview=1', '12B');
        eq($r['headers']['location'] ?? null, '/apto-12b/?preview=1', 'preview kept');
    });
});

test('render.php: with the cookie the guide renders', function () {
    rt_with_server(function () {
        rt_publish('apto-1204');
    }, function ($ctx) {
        $r = at_http($ctx, 'GET', '/apto-1204/', '', rt_cookie('apto-1204'));
        eq($r['status'], 200);
        eq($r['headers']['x-robots-tag'] ?? null, 'noindex');
        ok(strpos($r['raw'], 'id="welcome"') !== false, 'guide shown');
        ok(strpos($r['raw'], '/apto-1204/photo/a1.jpg') !== false, 'photo route');
        ok(strpos($r['raw'], 'name="code"') === false, 'no gate');
    });
});

test('render.php: guide A cookie does not open guide B', function () {
    rt_with_server(function () {
        rt_publish('apto-1204');
        rt_publish('apto-1205', '1205');
    }, function ($ctx) {
        $r = at_http($ctx, 'GET', '/apto-1205/', '', rt_cookie('apto-1204'));
        ok(strpos($r['raw'], 'name="code"') !== false, 'gate again');
        ok(strpos($r['raw'], 'id="welcome"') === false, 'no guide');
        eq(at_http($ctx, 'GET', '/apto-1205/photo/a1.jpg', '', rt_cookie('apto-1204'))['status'], 403, 'photo of B with A cookie');
    });
});

test('render.php: preview needs a draft, and the gate', function () {
    rt_with_server(function () {
        rt_publish('apto-1204', '1204', false);
        rt_publish('apto-1205', '1205');
    }, function ($ctx) {
        eq(at_http($ctx, 'GET', '/apto-1204/?preview=1', '', rt_cookie('apto-1204'))['status'], 404, 'no draft');
        $r = at_http($ctx, 'GET', '/apto-1205/?preview=1');
        ok(strpos($r['raw'], 'name="code"') !== false, 'preview gated');
        $r = at_http($ctx, 'GET', '/apto-1205/?preview=1', '', rt_cookie('apto-1205'));
        eq($r['status'], 200);
        ok(strpos($r['raw'], 'preview-banner') !== false, 'draft banner');
    });
});

test('photo.php: 403 without the gate, served with it, 404 when missing', function () {
    rt_with_server(function () {
        rt_publish('apto-1204');
    }, function ($ctx) {
        eq(at_http($ctx, 'GET', '/apto-1204/photo/a1.jpg')['status'], 403, 'no cookie');
        $r = at_http($ctx, 'GET', '/apto-1204/photo/a1.jpg', '', rt_cookie('apto-1204'));
        eq($r['status'], 200);
        eq($r['headers']['content-type'] ?? null, 'image/jpeg');
        eq($r['headers']['cache-control'] ?? null, 'private, max-age=86400');
        eq($r['headers']['x-content-type-options'] ?? null, 'nosniff');
        eq($r['raw'], rt_jpeg());
        eq(at_http($ctx, 'GET', '/apto-1204/photo/zz.jpg', '', rt_cookie('apto-1204'))['status'], 404, 'missing photo');
    });
});

test('render: title_html keeps each language to itself', function () {
    eq(title_html(['es' => 'Edificio Delventto', 'en' => 'Delventto Building'], 'Delventto'),
        '<span data-es="Edificio " data-en="">Edificio </span><em>Delventto</em><span data-es="" data-en=" Building"></span>');
    eq(title_html(['es' => 'Apartasuite 613T2', 'en' => 'Apartasuite 613T2'], '613T2'),
        '<span data-es="Apartasuite " data-en="Apartasuite ">Apartasuite </span><em>613T2</em>');
});

test('render: lock step titles render like 613T2, plain steps without one', function () {
    $html = rt_render(rt_fixture('613t2'));
    foreach ([['Registro', 'Registration'], ['Identificación', 'ID'], ['Acceso', 'Access']] as [$es, $en]) {
        ok(strpos($html, '<strong><span data-es="' . h($es) . '" data-en="' . $en . '">') !== false, "title $es");
    }
    ok(preg_match('#<div class="step-num">5</div><div>\s*<strong>WiFi</strong>#', $html) === 1, '613T2 numbering: WiFi is step 5');
    $min = rt_render(rt_fixture('minimal'));
    ok(preg_match('#<div class="step-num">1</div><div>\s*<p class="step-text">#', $min) === 1, 'plain T step has no title');
});

test('render: places follow places.building then places.own, whatever the card type', function () {
    $g = rt_fixture('613t2');
    $g['places']['building'] = ['playa-del-ritmo', 'zazue', 'pizza-el-vomito', 'centro-historico', 'quinta-san-pedro', 'harrys', 'cambiante', 'holguer', 'la-samaria', 'crepes-carulla', 'el-rodadero', 'tayrona'];
    $html = rt_render($g);
    $pos = fn (string $needle) => strpos($html, $needle);
    ok($pos('<h3><span data-es="Holguer Pizza"') < $pos('<h3><span data-es="Arepas de Huevo y Jugos Naturales &quot;La Samaria&quot;"') && $pos('<h3><span data-es="Arepas de Huevo y Jugos Naturales &quot;La Samaria&quot;"') < $pos('<h3><span data-es="Crepes &amp; Waffles'), 'La Samaria between Holguer and Crepes & Waffles');
    ok($pos('<h3><span data-es="Crepes &amp; Waffles') < $pos('<h3><span data-es="El Rodadero"') && $pos('<h3><span data-es="El Rodadero"') < $pos('Excursiones de un día'), 'day trips after the nearby cards');
    // own places come after the building picks
    ok($pos('Arepas de Huevo y Jugos Naturales La Samaria') > $pos('<h3><span data-es="El Rodadero"'), 'own places last');
    // three grids before the day trips: photo cards, the La Samaria text card, photo cards again
    $nearby = substr($html, $pos('id="places"'), $pos('Excursiones de un día') - $pos('id="places"'));
    eq(preg_match_all('#<div class="(cards-photo|cards cards-text)">#', $nearby, $m), 4);
    eq($m[1], ['cards-photo', 'cards cards-text', 'cards-photo', 'cards cards-text']);
});
