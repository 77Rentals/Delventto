<?php
declare(strict_types=1);

// Static checks only. Real Apache behaviour is verified live after deploy.

function htaccess_text(): string
{
    $t = @file_get_contents(__DIR__ . '/../.htaccess');
    return $t === false ? '' : $t;
}

function userini_values(): array
{
    $out = [];
    $lines = @file(__DIR__ . '/../.user.ini', FILE_IGNORE_NEW_LINES);
    foreach ($lines ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';' || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $out[$k] = $v;
    }
    return $out;
}

test('htaccess exists with the basics', function () {
    $t = htaccess_text();
    ok($t !== '', '.htaccess missing');
    ok(strpos($t, 'RewriteEngine On') !== false);
    ok(strpos($t, 'Options -Indexes') !== false);
    ok(strpos($t, 'DirectoryIndex index.html index.php') !== false);
});

test('htaccess hides git, tests and tooling folders with 404', function () {
    $t = htaccess_text();
    foreach (['\.git', '/tests/', '\.superpowers', '\.github', '\.claude'] as $needle) {
        ok(preg_match('/RedirectMatch 404 .*' . preg_quote($needle, '/') . '/', $t) === 1, "no 404 rule for $needle");
    }
});

test('htaccess blocks direct /_guide/ except assets', function () {
    $t = htaccess_text();
    ok(strpos($t, '_guide/assets/') !== false, 'assets exception missing');
    ok(strpos($t, 'THE_REQUEST') !== false || strpos($t, 'REDIRECT_STATUS') !== false, 'no external-only guard');
    ok(preg_match('/RewriteRule \^_guide.*\[[^\]]*(R=404|\bF\b|\bG\b)/', $t) === 1, 'no deny rule');
});

test('htaccess routes the API with QSA', function () {
    $t = htaccess_text();
    ok(preg_match('#RewriteRule \^api/guides/\(\.\+\)\$ _guide/api\.php \[[^\]]*QSA[^\]]*L[^\]]*\]#', $t) === 1, 'api rule');
});

test('htaccess routes photos and guides behind file/dir guards', function () {
    $t = htaccess_text();
    ok(preg_match('#RewriteRule \^\(\[a-z0-9-\]\+\)/photo/\(\[a-z0-9\.-\]\+\)\$ _guide/photo\.php \[[^\]]*QSA#', $t) === 1, 'photo rule');
    ok(preg_match('#RewriteRule \^\(\[a-z0-9-\]\+\)/\?\$ _guide/render\.php \[[^\]]*QSA#', $t) === 1, 'render rule');
    ok(strpos($t, '%{REQUEST_FILENAME} !-f') !== false, '!-f guard');
    ok(strpos($t, '%{DOCUMENT_ROOT}/$1 !-d') !== false, '!-d guard on first segment');
    ok(strpos($t, '%{DOCUMENT_ROOT}/$1 !-f') !== false, '!-f guard on first segment');
    ok(strpos($t, 'R=301') !== false, 'trailing slash redirect');
});

test('htaccess has no php_value or php_flag', function () {
    $t = htaccess_text();
    ok(preg_match('/^\s*php_(value|flag)/mi', $t) === 0);
});

test('.user.ini carries the PHP settings', function () {
    $v = userini_values();
    eq($v['display_errors'] ?? null, 'Off');
    eq($v['log_errors'] ?? null, 'On');
    eq($v['post_max_size'] ?? null, '10M');
    eq($v['upload_max_filesize'] ?? null, '10M');
    eq($v['expose_php'] ?? null, 'Off');
});

test('deploy workflow hardens the remote and never echoes the secret', function () {
    $t = (string) @file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
    ok(strpos($t, 'appleboy/ssh-action@v1.0.3') !== false);
    ok(strpos($t, 'GUIDE_API_SECRET') !== false);
    ok(strpos($t, 'secrets.FTP_SERVER') !== false);
    ok(preg_match('/echo[^\n]*GUIDE_API_SECRET/', $t) === 0, 'secret echoed');
    ok(strpos($t, "\xE2\x80\x94") === false && strpos($t, "\xE2\x80\x93") === false, 'dash in workflow');
});
