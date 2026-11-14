<?php
declare(strict_types=1);

require_once __DIR__ . '/themes.php';

// View helpers for template.php and gate.php. Every string from a guide or a
// building file goes out through h() or t(); nothing is printed raw.

/** Templates refuse to run unless loaded through this file. */
if (!defined('GUIDE_VIEW')) {
    define('GUIDE_VIEW', true);
}

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Bilingual text as <span data-es="…" data-en="…">es</span>, the mechanism the
 * guide's language toggle swaps. Empty string when both languages are empty.
 */
function t(array $T): string
{
    $es = is_string($T['es'] ?? null) ? $T['es'] : '';
    $en = is_string($T['en'] ?? null) ? $T['en'] : '';
    if ($es === '' && $en === '') {
        return '';
    }
    if ($en === '') {
        $en = $es;
    } elseif ($es === '') {
        $es = $en;
    }
    return '<span data-es="' . h($es) . '" data-en="' . h($en) . '">' . h($es) . '</span>';
}

/** t() for fixed template copy. */
function tx(string $es, string $en): string
{
    return t(['es' => $es, 'en' => $en]);
}

/** A usable T value ({es, en}, at least one non-empty string) or null. */
function tval($v): ?array
{
    if (!is_array($v)) {
        return null;
    }
    $es = is_string($v['es'] ?? null) ? trim($v['es']) : '';
    $en = is_string($v['en'] ?? null) ? trim($v['en']) : '';
    if ($es === '' && $en === '') {
        return null;
    }
    return ['es' => $es, 'en' => $en];
}

/** The string at $v, or '' when it is not a string. */
function sval($v): string
{
    return is_string($v) ? trim($v) : '';
}

/** A list (or [] when $v is not an array). */
function lval($v): array
{
    return is_array($v) ? array_values($v) : [];
}

/** Replace {key} placeholders in both languages. */
function tsub(array $T, array $repl): array
{
    $from = array_map(fn ($k) => '{' . $k . '}', array_keys($repl));
    $to = array_values($repl);
    return ['es' => str_replace($from, $to, $T['es']), 'en' => str_replace($from, $to, $T['en'])];
}

/** Paragraphs split on blank lines; one T per paragraph when both languages agree on the count. */
function tparas(array $T): array
{
    $split = fn (string $s) => array_values(array_filter(array_map('trim', preg_split('/\n\s*\n/', $s) ?: []), fn ($p) => $p !== ''));
    $es = $split($T['es']);
    $en = $split($T['en']);
    if (count($es) > 1 && count($es) === count($en)) {
        $out = [];
        foreach ($es as $i => $p) {
            $out[] = ['es' => $p, 'en' => $en[$i]];
        }
        return $out;
    }
    return [$T];
}

/** "15:00" to "3:00 PM" (613T2 shows 12-hour times). */
function fmt_time(string $hhmm): string
{
    if (preg_match('/^([01][0-9]|2[0-3]):([0-5][0-9])$/D', $hhmm, $m) !== 1) {
        return $hhmm;
    }
    $hr = (int) $m[1];
    $suffix = $hr < 12 ? 'AM' : 'PM';
    $hr12 = $hr % 12 === 0 ? 12 : $hr % 12;
    return $hr12 . ':' . $m[2] . ' ' . $suffix;
}

/**
 * A link that is safe to put in href/src: https:// with a host, or a same-site path
 * ("/x", never "//x" or "/\x"), no whitespace or backslashes. Null otherwise.
 */
function safe_url($u): ?string
{
    if (!is_string($u) || $u === '' || preg_match('/[\s\\\\"<>]/', $u) === 1) {
        return null;
    }
    if (preg_match('#^https://[a-z0-9.-]+(:[0-9]+)?([/?\#].*)?$#iD', $u) === 1) {
        return $u;
    }
    if ($u[0] === '/' && (strlen($u) === 1 || $u[1] !== '/')) {
        return $u;
    }
    return null;
}

/**
 * Same-site URL of a guide photo, served by photo.php behind the gate. In preview the
 * URL carries ?preview=1 so photo.php checks the file against the draft, not the published guide.
 */
function photo_url(string $slug, string $file, bool $preview = false): string
{
    return '/' . rawurlencode($slug) . '/photo/' . rawurlencode($file) . ($preview ? '?preview=1' : '');
}

function maps_search_url(string $query): string
{
    return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
}

function wa_url(string $phone): string
{
    return 'https://wa.me/' . preg_replace('/\D+/', '', $phone);
}

/**
 * How to show a tour video link: ['video', src] for a file, ['iframe', src] for
 * YouTube, Vimeo or Drive, ['link', href] for anything else usable, null when unsafe.
 */
function video_embed($url): ?array
{
    $u = safe_url($url);
    if ($u === null) {
        return null;
    }
    $path = (string) parse_url($u, PHP_URL_PATH);
    if (preg_match('/\.(mp4|webm|mov|m4v)$/iD', $path) === 1) {
        return ['video', $u];
    }
    if ($u[0] === '/') {
        return ['link', $u];
    }
    $host = strtolower((string) parse_url($u, PHP_URL_HOST));
    $query = [];
    parse_str((string) parse_url($u, PHP_URL_QUERY), $query);
    $id = '/^[A-Za-z0-9_-]{6,20}$/D';
    if (in_array($host, ['www.youtube.com', 'youtube.com', 'm.youtube.com'], true)) {
        $v = is_string($query['v'] ?? null) ? $query['v'] : '';
        if ($v === '' && preg_match('#^/(shorts|embed|live)/([^/]+)#', $path, $m) === 1) {
            $v = $m[2];
        }
        if (preg_match($id, $v) === 1) {
            return ['iframe', 'https://www.youtube-nocookie.com/embed/' . $v];
        }
    }
    if ($host === 'youtu.be' && preg_match('#^/([^/]+)#', $path, $m) === 1 && preg_match($id, $m[1]) === 1) {
        return ['iframe', 'https://www.youtube-nocookie.com/embed/' . $m[1]];
    }
    if (in_array($host, ['vimeo.com', 'www.vimeo.com'], true) && preg_match('#^/([0-9]{5,12})#', $path, $m) === 1) {
        return ['iframe', 'https://player.vimeo.com/video/' . $m[1]];
    }
    if ($host === 'drive.google.com' && preg_match('#^/file/d/([A-Za-z0-9_-]{10,80})#', $path, $m) === 1) {
        return ['iframe', 'https://drive.google.com/file/d/' . $m[1] . '/preview'];
    }
    return ['link', $u];
}

/**
 * A title with one part emphasized, per language: "Apartasuite <em>613T2</em>".
 * Falls back to plain t() when the emphasized part is not in both languages.
 */
function title_html(array $T, string $emph): string
{
    if ($emph === '' || strpos($T['es'], $emph) === false || strpos($T['en'], $emph) === false) {
        return t($T);
    }
    [$esA, $esB] = explode($emph, $T['es'], 2);
    [$enA, $enB] = explode($emph, $T['en'], 2);
    // The parts around the emphasis differ per language ("Edificio |Delventto|" vs
    // "|Delventto| Building"), so each side keeps its own, possibly empty, text and
    // its spacing; t() would copy one language into the other.
    return title_part($esA, $enA) . '<em>' . h($emph) . '</em>' . title_part($esB, $enB);
}

/** A language span that may be empty in one language; nothing when empty in both. */
function title_part(string $es, string $en): string
{
    if (trim($es) === '' && trim($en) === '') {
        return '';
    }
    return '<span data-es="' . h($es) . '" data-en="' . h($en) . '">' . h($es) . '</span>';
}

/** /_guide/assets/<file> with a cache-busting version from its modification time. */
function guide_asset(string $file): string
{
    $path = __DIR__ . '/../assets/' . $file;
    $v = is_file($path) ? dechex((int) filemtime($path)) : '0';
    return '/_guide/assets/' . $file . '?v=' . $v;
}

/** The guide page as one HTML document. $mode is 'published' or 'preview'. */
function render_guide(array $g, array $building, string $mode): string
{
    return guide_view(__DIR__ . '/../template.php', [
        'g' => $g,
        'b' => $building,
        'mode' => $mode === 'preview' ? 'preview' : 'published',
        'theme' => guide_theme($g),
    ]);
}

/**
 * The gate page. Uses only the guide's theme and the building, never guide content:
 * the unit name would give the gate code away.
 */
function render_gate(array $g, array $building, bool $error, string $postedName): string
{
    return guide_view(__DIR__ . '/../gate.php', [
        'b' => $building,
        'theme' => guide_theme($g),
        'error' => $error,
        'postedName' => mb_substr($postedName, 0, 60),
    ]);
}

function guide_view(string $file, array $vars): string
{
    $render = static function (string $__file, array $__vars): void {
        extract($__vars, EXTR_SKIP);
        require $__file;
    };
    ob_start();
    try {
        $render($file, $vars);
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return (string) ob_get_clean();
}
