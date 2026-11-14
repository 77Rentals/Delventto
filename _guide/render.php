<?php
declare(strict_types=1);

// Guide page for /<slug>/ (and /<slug>/?preview=1 for the draft).
// Order: slug check (404, before the store), guide lookup (404), gate (form or
// cookie), then the guide. Nothing from the guide is sent before the gate.

// PHP errors go to the server log, never into the page (they would print paths).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/slug.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/building.php';
require_once __DIR__ . '/lib/view.php';

/** One month, in seconds. */
const RENDER_COOKIE_MAX_AGE = 2592000;

render_main();

function render_main(): void
{
    header('X-Robots-Tag: noindex');
    header('Cache-Control: private, no-store');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-Content-Type-Options: nosniff');

    $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
    if (preg_match('#^/([a-z0-9-]+)/$#D', $path, $m) !== 1 || !slug_valid($m[1]) || slug_reserved($m[1])) {
        render_status(404, 'No encontramos esta guía.');
        return;
    }
    $slug = $m[1];
    $preview = ($_GET['preview'] ?? null) === '1';

    try {
        $st = store_get($slug);
        $guide = $preview ? $st['draft'] : $st['published'];
        if (!is_array($guide)) {
            render_status(404, 'No encontramos esta guía.');
            return;
        }
        $building = guide_building(is_string($guide['building'] ?? null) ? $guide['building'] : '');
        if ($building === null) {
            throw new RuntimeException('Unknown building for guide ' . $slug);
        }
        $secret = guide_secret();

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        if ($method === 'POST') {
            $code = is_string($_POST['code'] ?? null) ? $_POST['code'] : '';
            $name = is_string($_POST['name'] ?? null) ? trim($_POST['name']) : '';
            if (render_code_matches($code, $guide['gateCode'] ?? null)) {
                header('Set-Cookie: ' . gate_cookie_name($slug) . '=' . gate_token($secret, $slug)
                    . '; Max-Age=' . RENDER_COOKIE_MAX_AGE . '; Path=/' . $slug . '/; HttpOnly; SameSite=Lax'
                    . (render_is_https() ? '; Secure' : ''));
                header('Location: /' . $slug . '/' . ($preview ? '?preview=1' : ''), true, 303);
                return;
            }
            render_html(200, render_gate($guide, $building, true, $name));
            return;
        }

        if (!gate_passed($secret, $slug, $_COOKIE)) {
            render_html(200, render_gate($guide, $building, false, ''));
            return;
        }
        render_html(200, render_guide($guide, $building, $preview ? 'preview' : 'published'));
    } catch (Throwable $e) {
        error_log('guide render: ' . get_class($e) . ': ' . $e->getMessage());
        render_status(500, 'La guía no está disponible en este momento. Intenta otra vez en un rato.');
    }
}

/** Case-insensitive, constant-time comparison of the posted code with gateCode. */
function render_code_matches(string $posted, $gateCode): bool
{
    if (!is_string($gateCode) || $gateCode === '') {
        return false;
    }
    $norm = fn (string $s) => strtoupper(trim($s));
    return hash_equals($norm($gateCode), $norm($posted));
}

function render_is_https(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    return ($https !== '' && $https !== 'off')
        || (string) ($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function render_html(int $status, string $html): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo $html;
}

/** A small page for 404 and 500, with no detail about the cause. */
function render_status(int $status, string $message): void
{
    render_html($status, '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<meta name="robots" content="noindex, nofollow"><title>' . $status . '</title>'
        . '<style>body{font-family:system-ui,sans-serif;background:#FDFAF5;color:#1C1C1C;display:flex;'
        . 'min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px;text-align:center}</style>'
        . '</head><body><p>' . h($message) . '</p></body></html>');
}
