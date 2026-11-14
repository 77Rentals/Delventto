<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/store.php';

function st_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }
        $p = $dir . '/' . $e;
        is_dir($p) ? st_rrmdir($p) : @unlink($p);
    }
    @rmdir($dir);
}

/** Runs $fn with GUIDE_DATA_DIR pointing at a fresh temp dir, then restores the env and deletes the dir. */
function st_with_store(callable $fn): void
{
    $old = getenv('GUIDE_DATA_DIR');
    $dir = sys_get_temp_dir() . '/guide-store-test-' . bin2hex(random_bytes(6));
    mkdir($dir, 0700, true);
    putenv('GUIDE_DATA_DIR=' . $dir);
    try {
        $fn($dir);
    } finally {
        putenv($old === false ? 'GUIDE_DATA_DIR' : 'GUIDE_DATA_DIR=' . $old);
        st_rrmdir($dir);
    }
}

function st_guide(string $tagline = 'uno'): array
{
    $g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/minimal.json'), true);
    $g['tagline']['es'] = $tagline;
    return $g;
}

/** Asserts that $fn throws GuideStoreError with the given code. */
function st_throws(callable $fn, int $code, string $msg): void
{
    try {
        $fn();
    } catch (GuideStoreError $e) {
        eq($e->getCode(), $code, $msg . ' (code)');
        ok($e->getMessage() !== '', $msg . ' (message)');
        return;
    }
    throw new RuntimeException($msg . ': expected GuideStoreError ' . $code);
}

function st_no_temp_files(string $dir): void
{
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        ok(strpos($f->getFilename(), '.tmp-') === false, 'temp file left behind: ' . $f->getFilename());
    }
}

test('store: unknown slug is empty and creates nothing', function () {
    st_with_store(function ($dir) {
        $s = store_get('apto-9999');
        eq($s['draft'], null);
        eq($s['published'], null);
        eq($s['versions'], []);
        eq($s['current'], null);
        ok(!file_exists($dir . '/apto-9999'), 'no folder for a read');
    });
});

test('store: put draft then get', function () {
    st_with_store(function ($dir) {
        $g = st_guide();
        store_put_draft('apto-1204', $g);
        $s = store_get('apto-1204');
        eq($s['draft'], $g);
        eq($s['published'], null);
        eq($s['versions'], []);
        store_put_draft('apto-1204', st_guide('dos'));
        eq(store_get('apto-1204')['draft']['tagline']['es'], 'dos', 'overwrite');
        st_no_temp_files($dir);
    });
});

test('store: publish creates a version and published', function () {
    st_with_store(function ($dir) {
        $g = st_guide();
        store_put_draft('apto-1204', $g);
        $v = store_publish('apto-1204');
        ok(preg_match('/^[0-9]{8}T[0-9]{6}Z$/D', $v) === 1, 'version id format: ' . $v);
        ok(is_file($dir . '/apto-1204/versions/' . $v . '.json'), 'version file');
        $s = store_get('apto-1204');
        eq($s['published'], $g);
        eq($s['current'], $v);
        eq(count($s['versions']), 1);
        eq($s['versions'][0]['id'], $v);
        $iso = substr($v, 0, 4) . '-' . substr($v, 4, 2) . '-' . substr($v, 6, 2) . 'T'
            . substr($v, 9, 2) . ':' . substr($v, 11, 2) . ':' . substr($v, 13, 2) . 'Z';
        eq($s['versions'][0]['publishedAt'], $iso);
        st_no_temp_files($dir);
    });
});

test('store: publish without a draft is refused', function () {
    st_with_store(function () {
        st_throws(fn () => store_publish('apto-1204'), 409, 'no draft');
    });
});

test('store: publish refuses a draft that changed since it was checked', function () {
    st_with_store(function () {
        store_put_draft('apto-1204', st_guide('uno'));
        st_throws(fn () => store_publish('apto-1204', st_guide('otro')), 409, 'changed draft');
        eq(store_get('apto-1204')['published'], null);
        $v = store_publish('apto-1204', st_guide('uno'));
        ok($v !== '', 'matching expected draft publishes');
    });
});

test('store: publishing twice in the same second gives distinct, ordered versions', function () {
    st_with_store(function () {
        store_put_draft('apto-1204', st_guide());
        $ids = [];
        for ($i = 0; $i < 12; $i++) {
            $ids[] = store_publish('apto-1204');
        }
        eq(count(array_unique($ids)), 12, 'unique ids');
        $listed = array_column(store_get('apto-1204')['versions'], 'id');
        eq($listed, $ids, 'listed in publish order');
        eq(store_get('apto-1204')['current'], end($ids));
    });
});

test('store: rollback with no argument restores the previous published', function () {
    st_with_store(function () {
        store_put_draft('apto-1204', st_guide('uno'));
        $v1 = store_publish('apto-1204');
        store_put_draft('apto-1204', st_guide('dos'));
        $v2 = store_publish('apto-1204');
        ok($v1 !== $v2, 'two versions');
        eq(store_get('apto-1204')['published']['tagline']['es'], 'dos');

        eq(store_rollback('apto-1204', null), $v1);
        $s = store_get('apto-1204');
        eq($s['published']['tagline']['es'], 'uno');
        eq($s['current'], $v1);
        eq($s['draft']['tagline']['es'], 'uno', 'draft reset to the restored version');
        eq(count($s['versions']), 2, 'rollback adds no version');

        st_throws(fn () => store_rollback('apto-1204', null), 409, 'nothing before the oldest');
        eq(store_get('apto-1204')['current'], $v1, 'still v1 after refused rollback');
    });
});

test('store: rollback to a named version, unknown or malformed version refused', function () {
    st_with_store(function () {
        store_put_draft('apto-1204', st_guide('uno'));
        $v1 = store_publish('apto-1204');
        store_put_draft('apto-1204', st_guide('dos'));
        $v2 = store_publish('apto-1204');
        store_rollback('apto-1204', $v1);
        eq(store_rollback('apto-1204', $v2), $v2);
        eq(store_get('apto-1204')['published']['tagline']['es'], 'dos');
        st_throws(fn () => store_rollback('apto-1204', '20000101T000000Z'), 404, 'unknown');
        st_throws(fn () => store_rollback('apto-1204', '../draft'), 404, 'malformed');
    });
});

test('store: rollback resets the draft, so the next edit and publish builds on the restored guide', function () {
    st_with_store(function ($dir) {
        store_put_draft('apto-1204', st_guide('uno'));
        $v1 = store_publish('apto-1204');
        store_put_draft('apto-1204', st_guide('dos'));
        store_publish('apto-1204');
        store_put_draft('apto-1204', st_guide('tres sin publicar'));

        eq(store_rollback('apto-1204', $v1), $v1);
        $s = store_get('apto-1204');
        eq($s['draft'], $s['published'], 'draft equals the restored guide');
        eq($s['draft']['tagline']['es'], 'uno');
        st_no_temp_files($dir);

        // The owner's next small edit starts from the restored guide, not from "dos".
        $next = $s['draft'];
        $next['welcome']['es'] = 'Bienvenido otra vez.';
        store_put_draft('apto-1204', $next);
        store_publish('apto-1204');
        eq(store_get('apto-1204')['published']['tagline']['es'], 'uno', 'removed content stays removed');
    });
});

test('store: rollback with nothing published is refused', function () {
    st_with_store(function () {
        st_throws(fn () => store_rollback('apto-1204', null), 409, 'no versions');
    });
});

test('store: unpublish clears published but keeps versions, and repeats safely', function () {
    st_with_store(function ($dir) {
        store_put_draft('apto-1204', st_guide());
        $v = store_publish('apto-1204');
        store_unpublish('apto-1204');
        $s = store_get('apto-1204');
        eq($s['published'], null);
        eq($s['current'], null);
        eq(array_column($s['versions'], 'id'), [$v]);
        ok($s['draft'] !== null, 'draft kept');
        store_unpublish('apto-1204');
        store_unpublish('apto-5555');
        ok(!file_exists($dir . '/apto-5555'), 'unpublishing an unknown slug creates nothing');
        // rollback after unpublish republishes the latest version
        eq(store_rollback('apto-1204', null), $v);
        eq(store_get('apto-1204')['current'], $v);
    });
});

test('store: photo names are sanitized and collisions get -2, -3', function () {
    st_with_store(function ($dir) {
        eq(store_add_photo('apto-1204', 'Sala Principal.JPG', 'x', 'jpg'), 'sala-principal.jpg');
        eq(store_add_photo('apto-1204', 'sala principal', 'y', 'jpg'), 'sala-principal-2.jpg');
        eq(store_add_photo('apto-1204', 'SALA_principal.jpeg', 'z', 'jpg'), 'sala-principal-3.jpg');
        eq(store_add_photo('apto-1204', 'sala-principal', 'p', 'png'), 'sala-principal.png', 'other ext no clash');
        eq(store_add_photo('apto-1204', '../../etc/passwd', 'q', 'jpg'), 'etc-passwd.jpg');
        eq(store_add_photo('apto-1204', 'Baño Niños.webp', 'r', 'webp'), 'bano-ninos.webp');
        eq(store_add_photo('apto-1204', '', 's', 'jpg'), 'foto.jpg');
        eq(store_add_photo('apto-1204', '***', 't', 'jpg'), 'foto-2.jpg');
        eq(file_get_contents($dir . '/apto-1204/photos/sala-principal-2.jpg'), 'y');
        st_no_temp_files($dir);
    });
});

test('store: 41st photo refused', function () {
    st_with_store(function () {
        for ($i = 1; $i <= 40; $i++) {
            store_add_photo('apto-1204', 'f' . $i, 'b', 'jpg');
        }
        st_throws(fn () => store_add_photo('apto-1204', 'f41', 'b', 'jpg'), 409, '41st');
        eq(store_photo_path('apto-1204', 'f41.jpg'), null, 'not written');
    });
});

test('store: bad extension refused', function () {
    st_with_store(function () {
        $threw = false;
        try {
            store_add_photo('apto-1204', 'x', 'b', 'php');
        } catch (InvalidArgumentException $e) {
            $threw = true;
        }
        ok($threw, 'php ext');
    });
});

test('store: photo path only for existing, well formed names', function () {
    st_with_store(function ($dir) {
        $f = store_add_photo('apto-1204', 'a1', 'b', 'jpg');
        $p = store_photo_path('apto-1204', $f);
        ok($p !== null && is_file($p), 'existing');
        eq(store_photo_path('apto-1204', 'nope.jpg'), null, 'missing');
        eq(store_photo_path('apto-1204', '../draft.json'), null, 'traversal');
        eq(store_photo_path('apto-1204', 'A1.jpg'), null, 'uppercase');
        eq(store_photo_path('apto-1204', 'a1.php'), null, 'ext');
        eq(store_photo_path('../x', 'a1.jpg'), null, 'bad slug');
    });
});

test('store: invalid slug is refused for writes', function () {
    st_with_store(function ($dir) {
        foreach (['../etc', 'Apto', 'a/b', 'a.b', ''] as $bad) {
            $threw = false;
            try {
                store_put_draft($bad, st_guide());
            } catch (InvalidArgumentException $e) {
                $threw = true;
            }
            ok($threw, 'slug ' . var_export($bad, true));
        }
        eq(array_values(array_diff(scandir($dir) ?: [], ['.', '..'])), [], 'nothing written');
    });
});
