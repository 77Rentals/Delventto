<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/slug.php';

/*
 * Guide store. Layout under guide_data_dir()/<slug>/:
 *   draft.json                 the working copy the bot edits
 *   published.json             {"version": id, "guide": {...}} (absent when unpublished)
 *   versions/<id>.json         every published guide, id = UTC yyyymmddThhmmssZ[-n]
 *   photos/<name>.<ext>        uploaded photos
 *   .lock                      flock target; every read and write holds it
 * Every file write goes to a temp file in the same folder and is renamed into place.
 */

const STORE_MAX_PHOTOS = 40;
const STORE_PHOTO_EXTS = ['jpg', 'png', 'webp'];
const STORE_VERSION_RE = '/^[0-9]{8}T[0-9]{6}Z(-[0-9]{1,4})?$/D';
const STORE_PHOTO_RE = '/^[a-z0-9]+(-[a-z0-9]+)*\.(jpg|png|webp)$/D';

/** A refusal the caller can show; the code is the matching HTTP status (404, 409). */
final class GuideStoreError extends RuntimeException
{
}

/** ['draft'=>?array, 'published'=>?array, 'versions'=>[['id','publishedAt']], 'current'=>?string] */
function store_get(string $slug): array
{
    $dir = store_dir($slug);
    $empty = ['draft' => null, 'published' => null, 'versions' => [], 'current' => null];
    if (!is_dir($dir)) {
        return $empty;
    }
    return store_locked($dir, LOCK_SH, function () use ($dir) {
        $pub = store_read_published($dir);
        return [
            'draft' => store_read_json($dir . '/draft.json'),
            'published' => $pub['guide'] ?? null,
            'versions' => array_map(
                fn (string $id) => ['id' => $id, 'publishedAt' => store_version_time($id)],
                store_version_ids($dir)
            ),
            'current' => $pub['version'] ?? null,
        ];
    });
}

function store_put_draft(string $slug, array $g): void
{
    $dir = store_dir($slug);
    store_mkdir($dir);
    store_locked($dir, LOCK_EX, function () use ($dir, $g) {
        store_write_json($dir . '/draft.json', $g);
    });
}

/**
 * Stores photo bytes under a sanitized name and returns the file name.
 * $name's stem becomes lowercase [a-z0-9-]; collisions get -2, -3, ...
 */
function store_add_photo(string $slug, string $name, string $bytes, string $ext): string
{
    if (!in_array($ext, STORE_PHOTO_EXTS, true)) {
        throw new InvalidArgumentException('Unsupported photo extension');
    }
    $dir = store_dir($slug);
    store_mkdir($dir . '/photos');
    return store_locked($dir, LOCK_EX, function () use ($dir, $name, $bytes, $ext) {
        $photos = $dir . '/photos';
        $count = 0;
        foreach (scandir($photos) ?: [] as $f) {
            if (preg_match(STORE_PHOTO_RE, $f) === 1) {
                $count++;
            }
        }
        if ($count >= STORE_MAX_PHOTOS) {
            throw new GuideStoreError('Ya hay ' . STORE_MAX_PHOTOS . ' fotos, que es el máximo por guía', 409);
        }
        $stem = store_photo_stem($name);
        $file = $stem . '.' . $ext;
        for ($n = 2; file_exists($photos . '/' . $file); $n++) {
            $file = $stem . '-' . $n . '.' . $ext;
        }
        store_write_atomic($photos . '/' . $file, $bytes);
        return $file;
    });
}

/**
 * Copies the draft to published.json and keeps it as a new version; returns the version id.
 * With $expected, refuses (409) unless the draft is still exactly that guide, so a
 * caller can validate first and publish only what it validated.
 */
function store_publish(string $slug, ?array $expected = null): string
{
    $dir = store_dir($slug);
    if (!is_dir($dir)) {
        throw new GuideStoreError('Todavía no hay borrador para publicar', 409);
    }
    return store_locked($dir, LOCK_EX, function () use ($dir, $expected) {
        $draft = store_read_json($dir . '/draft.json');
        if ($draft === null) {
            throw new GuideStoreError('Todavía no hay borrador para publicar', 409);
        }
        if ($expected !== null && $draft !== $expected) {
            throw new GuideStoreError('El borrador cambió mientras se revisaba, intenta publicar otra vez', 409);
        }
        store_mkdir($dir . '/versions');
        $base = gmdate('Ymd\THis\Z');
        $id = $base;
        for ($n = 2; file_exists($dir . '/versions/' . $id . '.json'); $n++) {
            $id = $base . '-' . $n;
        }
        // Version first: a crash before published.json leaves the old guide live, never a half state.
        store_write_json($dir . '/versions/' . $id . '.json', $draft);
        store_write_json($dir . '/published.json', ['version' => $id, 'guide' => $draft]);
        return $id;
    });
}

/**
 * Republishes a kept version and returns its id. With no $version: the version before
 * the current one, or the latest one when nothing is published.
 */
function store_rollback(string $slug, ?string $version): string
{
    $dir = store_dir($slug);
    if (!is_dir($dir)) {
        throw new GuideStoreError('Esta guía no tiene versiones publicadas', 409);
    }
    return store_locked($dir, LOCK_EX, function () use ($dir, $version) {
        $ids = store_version_ids($dir);
        if ($ids === []) {
            throw new GuideStoreError('Esta guía no tiene versiones publicadas', 409);
        }
        if ($version !== null) {
            if (preg_match(STORE_VERSION_RE, $version) !== 1 || !in_array($version, $ids, true)) {
                throw new GuideStoreError('Esa versión no existe', 404);
            }
            $target = $version;
        } else {
            $current = store_read_published($dir)['version'] ?? null;
            $idx = is_string($current) ? array_search($current, $ids, true) : false;
            if ($idx === false) {
                $target = $ids[count($ids) - 1];
            } elseif ($idx === 0) {
                throw new GuideStoreError('No hay una versión anterior a la actual', 409);
            } else {
                $target = $ids[$idx - 1];
            }
        }
        $guide = store_read_json($dir . '/versions/' . $target . '.json');
        if ($guide === null) {
            throw new RuntimeException('Version file vanished');
        }
        store_write_json($dir . '/published.json', ['version' => $target, 'guide' => $guide]);
        return $target;
    });
}

/** Takes the guide offline. Draft, photos and versions stay. Safe to repeat. */
function store_unpublish(string $slug): void
{
    $dir = store_dir($slug);
    if (!is_dir($dir)) {
        return;
    }
    store_locked($dir, LOCK_EX, function () use ($dir) {
        $file = $dir . '/published.json';
        if (is_file($file) && !unlink($file)) {
            throw new RuntimeException('Could not unpublish');
        }
    });
}

/** Absolute path of a stored photo, or null when the name is malformed or the file is missing. */
function store_photo_path(string $slug, string $file): ?string
{
    if (!slug_valid($slug) || preg_match(STORE_PHOTO_RE, $file) !== 1) {
        return null;
    }
    $path = store_dir($slug) . '/photos/' . $file;
    return is_file($path) ? $path : null;
}

// ---- internals ----

function store_dir(string $slug): string
{
    if (!slug_valid($slug)) {
        throw new InvalidArgumentException('Invalid slug');
    }
    return rtrim(guide_data_dir(), '/\\') . '/' . $slug;
}

function store_mkdir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create a guide data folder');
    }
}

/** Runs $fn while holding flock($mode) on <dir>/.lock. */
function store_locked(string $dir, int $mode, callable $fn)
{
    $fh = fopen($dir . '/.lock', 'c');
    if ($fh === false) {
        throw new RuntimeException('Could not open the guide lock');
    }
    try {
        if (!flock($fh, $mode)) {
            throw new RuntimeException('Could not lock the guide');
        }
        return $fn();
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function store_read_json(string $file): ?array
{
    if (!is_file($file)) {
        return null;
    }
    $raw = file_get_contents($file);
    $data = $raw === false ? null : json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('Corrupt guide data file: ' . basename($file));
    }
    return $data;
}

/** ['version'=>string, 'guide'=>array] or null when unpublished. */
function store_read_published(string $dir): ?array
{
    $pub = store_read_json($dir . '/published.json');
    if ($pub === null) {
        return null;
    }
    if (!is_string($pub['version'] ?? null) || !is_array($pub['guide'] ?? null)) {
        throw new RuntimeException('Corrupt published.json');
    }
    return $pub;
}

function store_write_json(string $file, array $data): void
{
    $json = json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    store_write_atomic($file, $json . "\n");
}

function store_write_atomic(string $file, string $bytes): void
{
    $tmp = $file . '.tmp-' . bin2hex(random_bytes(6));
    if (file_put_contents($tmp, $bytes) !== strlen($bytes)) {
        @unlink($tmp);
        throw new RuntimeException('Could not write guide data');
    }
    @chmod($tmp, 0600);
    if (!rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException('Could not save guide data');
    }
}

/** Version ids in publish order (base timestamp, then numeric suffix). */
function store_version_ids(string $dir): array
{
    $ids = [];
    if (!is_dir($dir . '/versions')) {
        return $ids;
    }
    foreach (scandir($dir . '/versions') ?: [] as $f) {
        if (substr($f, -5) === '.json' && preg_match(STORE_VERSION_RE, substr($f, 0, -5)) === 1) {
            $ids[] = substr($f, 0, -5);
        }
    }
    usort($ids, function (string $a, string $b): int {
        [$ba, $na] = array_pad(explode('-', $a, 2), 2, '1');
        [$bb, $nb] = array_pad(explode('-', $b, 2), 2, '1');
        return [$ba, (int) $na] <=> [$bb, (int) $nb];
    });
    return $ids;
}

/** "20261114T101010Z" (optionally "-2") to "2026-11-14T10:10:10Z". */
function store_version_time(string $id): string
{
    return substr($id, 0, 4) . '-' . substr($id, 4, 2) . '-' . substr($id, 6, 2) . 'T'
        . substr($id, 9, 2) . ':' . substr($id, 11, 2) . ':' . substr($id, 13, 2) . 'Z';
}

function store_photo_stem(string $name): string
{
    $s = mb_strtolower($name, 'UTF-8');
    $s = strtr($s, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
    $s = (string) preg_replace('/\.[a-z0-9]{1,5}$/D', '', $s);
    $s = trim((string) preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = trim(substr($s, 0, 60), '-');
    return $s === '' ? 'foto' : $s;
}
