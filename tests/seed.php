<?php
declare(strict_types=1);

// Seeds a local data dir with the 613T2 guide for the visual check:
//   GUIDE_DATA_DIR=/tmp/guides php tests/seed.php [palette] [motion]
// then
//   GUIDE_SECRET=<32+ chars> GUIDE_DATA_DIR=/tmp/guides php -S 127.0.0.1:8765 _guide/dev-router.php
// and open http://127.0.0.1:8765/apto-613t2-test/ (gate code 613) next to /apartasuite-613t2/.
//
// It publishes tests/fixtures/613t2.json under the slug apto-613t2-test, uploads the
// img_* photos from apartasuite-613t2/ through the store (names become img-kitchen.jpg
// and so on, the store's naming rule), and picks the same building places, in the
// same order, as the hand-written 613T2 page so the two can be compared side by side.
// Re-running replaces that one guide; nothing else in the data dir is touched.

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/store.php';
require_once __DIR__ . '/../_guide/lib/building.php';
require_once __DIR__ . '/../_guide/lib/validate.php';
require_once __DIR__ . '/../_guide/lib/themes.php';

const SEED_SLUG = 'apto-613t2-test';

if (PHP_SAPI !== 'cli') {
    exit(1);
}
$dir = getenv('GUIDE_DATA_DIR');
if ($dir === false || $dir === '') {
    fwrite(STDERR, "Set GUIDE_DATA_DIR to a scratch folder first (this script never writes to the real data dir).\n");
    exit(1);
}
if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
    fwrite(STDERR, "Could not create $dir\n");
    exit(1);
}

$palette = $argv[1] ?? 'caribe';
$motion = $argv[2] ?? 'marino';
if (!in_array($palette, GUIDE_PALETTES, true) || !in_array($motion, GUIDE_MOTIONS, true)) {
    fwrite(STDERR, 'Palettes: ' . implode(', ', GUIDE_PALETTES) . "\nMotions: " . implode(', ', GUIDE_MOTIONS) . "\n");
    exit(1);
}

// Start this guide from scratch so photo names do not pick up -2 suffixes.
seed_rrmdir(rtrim($dir, '/\\') . '/' . SEED_SLUG);

$g = json_decode((string) file_get_contents(__DIR__ . '/fixtures/613t2.json'), true);
$g['slug'] = SEED_SLUG;
$g['theme'] = ['palette' => $palette, 'motion' => $motion];
// Same cards and order as apartasuite-613t2/index.html; La Samaria comes from the building list.
$g['places']['building'] = ['playa-del-ritmo', 'zazue', 'pizza-el-vomito', 'centro-historico', 'quinta-san-pedro', 'harrys', 'cambiante', 'holguer', 'la-samaria', 'crepes-carulla', 'el-rodadero', 'tayrona', 'playa-blanca', 'minca', 'marina', 'museo-oro'];
$g['places']['own'] = [];

$src = __DIR__ . '/../apartasuite-613t2/';
foreach ($g['photos'] as $i => $p) {
    $bytes = file_get_contents($src . $p['file']);
    if ($bytes === false) {
        fwrite(STDERR, "Missing photo {$p['file']}\n");
        exit(1);
    }
    $ext = strtolower(pathinfo($p['file'], PATHINFO_EXTENSION)) === 'png' ? 'png' : 'jpg';
    $g['photos'][$i]['file'] = store_add_photo(SEED_SLUG, $p['file'], $bytes, $ext);
}
$host = file_get_contents($src . 'host-photo.jpg');
if ($host !== false) {
    $g['hosts']['photo'] = store_add_photo(SEED_SLUG, 'host-photo.jpg', $host, 'jpg');
}
$lock = file_get_contents($src . 'lock-yale.jpg');
if ($lock !== false) {
    $g['lock']['photo'] = store_add_photo(SEED_SLUG, 'lock-yale.jpg', $lock, 'jpg');
}

$building = guide_building('delventto');
if ($building === null) {
    fwrite(STDERR, "Building file _guide/buildings/delventto.json is missing\n");
    exit(1);
}
$problems = validate_guide($g, $building, SEED_SLUG);
foreach ($problems as $pr) {
    fwrite(STDERR, "problem: {$pr['field']}: {$pr['message']}\n");
}
store_put_draft(SEED_SLUG, $g);
$version = store_publish(SEED_SLUG);
echo 'Published ' . SEED_SLUG . " version $version ($palette / $motion) in $dir\n";
echo 'Gate code: ' . $g['gateCode'] . "\n";
exit($problems === [] ? 0 : 1);

function seed_rrmdir(string $d): void
{
    if (!is_dir($d)) {
        return;
    }
    foreach (scandir($d) ?: [] as $e) {
        if ($e !== '.' && $e !== '..') {
            $p = $d . '/' . $e;
            is_dir($p) ? seed_rrmdir($p) : unlink($p);
        }
    }
    rmdir($d);
}
