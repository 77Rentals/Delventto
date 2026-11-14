<?php
declare(strict_types=1);

// Guide photo for /<slug>/photo/<file>: only for guests who passed that guide's gate.

// PHP errors go to the server log, never into the response (they would print paths).
ini_set('display_errors', '0');
ini_set('log_errors', '1');

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/slug.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/store.php';

const PHOTO_TYPES = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];

photo_main();

function photo_main(): void
{
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');

    $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
    if (preg_match('#^/([a-z0-9-]+)/photo/([a-z0-9.-]+)$#D', $path, $m) !== 1 || !slug_valid($m[1]) || slug_reserved($m[1])) {
        photo_status(404);
        return;
    }
    [, $slug, $file] = $m;
    try {
        if (!gate_passed(guide_secret(), $slug, $_COOKIE)) {
            photo_status(403);
            return;
        }
        $full = store_photo_path($slug, $file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($full === null || !isset(PHOTO_TYPES[$ext])) {
            photo_status(404);
            return;
        }
        $size = filesize($full);
        header('Content-Type: ' . PHOTO_TYPES[$ext]);
        header('Cache-Control: private, max-age=86400');
        if ($size !== false) {
            header('Content-Length: ' . $size);
        }
        if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'HEAD') {
            readfile($full);
        }
    } catch (Throwable $e) {
        error_log('guide photo: ' . get_class($e) . ': ' . $e->getMessage());
        photo_status(500);
    }
}

function photo_status(int $status): void
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo $status === 403 ? "Forbidden\n" : ($status === 404 ? "Not found\n" : "Error\n");
}
