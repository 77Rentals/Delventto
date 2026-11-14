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
    // expose_php is PHP_INI_SYSTEM: it has no effect in .user.ini, so the scripts remove the header.
    ok(!array_key_exists('expose_php', $v), 'expose_php in .user.ini does nothing');
});

test('every entry script removes X-Powered-By before any output', function () {
    foreach (['api.php', 'render.php', 'photo.php'] as $f) {
        $src = (string) file_get_contents(__DIR__ . '/../_guide/' . $f);
        $at = strpos($src, "header_remove('X-Powered-By');");
        ok($at !== false, "$f removes X-Powered-By");
        $main = preg_match('/^(api|render|photo)_main\(\);$/m', $src, $m, PREG_OFFSET_CAPTURE) === 1 ? $m[0][1] : PHP_INT_MAX;
        ok($at < $main, "$f removes it before its main function runs");
    }
});

test('responses carry no X-Powered-By header', function () {
    at_with_server(function ($ctx) {
        foreach (['/api/guides/apto-1204', '/apto-1204/', '/apto-1204/photo/a1.jpg'] as $target) {
            $r = at_http($ctx, 'GET', $target);
            ok($r['status'] > 0, "$target answered");
            ok(!isset($r['headers']['x-powered-by']), "$target sends X-Powered-By");
        }
    });
});

test('deploy workflow hardens the remote and never echoes the secret', function () {
    $t = (string) @file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
    ok(strpos($t, 'appleboy/ssh-action@v1.0.3') !== false);
    ok(strpos($t, 'GUIDE_API_SECRET') !== false);
    ok(strpos($t, 'secrets.FTP_SERVER') !== false);
    ok(preg_match('/echo[^\n]*GUIDE_API_SECRET/', $t) === 0, 'secret echoed');
    ok(strpos($t, "\xE2\x80\x94") === false && strpos($t, "\xE2\x80\x93") === false, 'dash in workflow');
    ok(preg_match('/set\s+-[a-wyz]*x/', $t) === 0 && strpos($t, 'xtrace') === false, 'no set -x: it would print the secret');
});

function deploy_text(): string
{
    return (string) @file_get_contents(__DIR__ . '/../.github/workflows/deploy.yml');
}

/** The workflow's steps, split on "- name:" (the checkout step has no name and comes first). */
function deploy_steps(): array
{
    return preg_split('/^\s*- name:/m', deploy_text());
}

test('deploy: checkout keeps no git credentials and SCP skips tooling folders', function () {
    $t = deploy_text();
    ok(preg_match('/uses: actions\/checkout@v4\s+with:\s+persist-credentials: false/', $t) === 1, 'persist-credentials: false');
    ok(strpos($t, 'source: "./*,!./.git,!./.github,!./tests,!./.superpowers"') !== false, 'SCP source exclusions');
    eq(substr_count($t, 'appleboy/scp-action@v0.1.7'), 1, 'one SCP step: ./* already ships .htaccess and .user.ini');
    ok(strpos($t, '~/') === false, 'absolute /home/rentals77 paths only');
});

test('deploy: a pre-deploy step backs up .htaccess and reports PHP without printing contents', function () {
    $steps = deploy_steps();
    $pre = null;
    $scp = null;
    foreach ($steps as $i => $s) {
        if ($pre === null && strpos($s, 'htaccess.backup-') !== false) {
            $pre = $i;
        }
        if ($scp === null && strpos($s, 'appleboy/scp-action') !== false) {
            $scp = $i;
        }
    }
    ok($pre !== null && $scp !== null && $pre < $scp, 'backup step runs before SCP');
    $s = $steps[$pre];
    ok(strpos($s, 'appleboy/ssh-action@v1.0.3') !== false, 'ssh-action');
    ok(strpos($s, 'umask 077') !== false && strpos($s, 'mkdir -p /home/rentals77/delventto-private') !== false, 'private folder first');
    ok(strpos($s, '/home/rentals77/delventto-private/htaccess.backup-$(date -u +%Y%m%dT%H%M%SZ)') !== false, 'UTC timestamp');
    ok(strpos($s, 'htaccess backup: yes (') !== false && strpos($s, 'htaccess backup: none') !== false, 'only yes/none lines');
    ok(preg_match('/\b(cat|head|tail|less|more)\b[^\n]*\.htaccess/', $s) === 0, 'never prints .htaccess');
    ok(strpos($s, 'php -v | head -1') !== false, 'php version line');
    foreach (['gd', 'mbstring', 'json', 'fileinfo'] as $m) {
        ok(strpos($s, $m) !== false, "checks $m");
    }
});

test('deploy: the post-deploy step locks down the private folders and the config file', function () {
    $steps = deploy_steps();
    $post = end($steps);
    ok(strpos($post, 'GUIDE_API_SECRET') !== false, 'last step writes the secret');
    ok(strpos($post, 'chmod 700 /home/rentals77/delventto-private /home/rentals77/delventto-guides') !== false, 'chmod 700 folders');
    ok(strpos($post, "<?php return ['secret' => '%s'];") !== false, 'single quoted PHP string');
    ok(strpos($post, 'chmod 600 /home/rentals77/delventto-private/config.php') !== false, 'chmod 600 config');
    ok(strpos($post, 'process list') !== false, 'comment on the drone-ssh process list exposure');
    ok(strpos($post, 'rm -rf /home/rentals77/delventto.cloud/.git ') !== false, 'remote rm backstop kept');
});
