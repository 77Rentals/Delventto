<?php
declare(strict_types=1);

// Local router that mimics the planned .htaccess:
//   php -S 127.0.0.1:8765 -t . _guide/dev-router.php   (from the repo root)
// /api/guides/... -> api.php, /<slug>/photo/<file> -> photo.php,
// /<slug>/ -> render.php when no real file or folder has that name, else the static file.
// photo.php and render.php arrive in a later task; until then those routes are 404.

$webroot = dirname(__DIR__);
$path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];

if (preg_match('#^/(\.git|tests)(/|$)#i', $path) === 1) {
    http_response_code(404);
    return true;
}

if (strncmp($path, '/api/guides/', 12) === 0) {
    require __DIR__ . '/api.php';
    return true;
}

if (preg_match('#^/[a-z0-9-]+/photo/[a-z0-9.-]+$#D', $path) === 1) {
    return dev_route(__DIR__ . '/photo.php');
}

if (preg_match('#^/([a-z0-9-]+)(/?)$#D', $path, $m) === 1 && !file_exists($webroot . '/' . $m[1])) {
    if ($m[2] === '') {
        $query = (string) ($_SERVER['QUERY_STRING'] ?? '');
        header('Location: /' . $m[1] . '/' . ($query !== '' ? '?' . $query : ''), true, 301);
        return true;
    }
    return dev_route(__DIR__ . '/render.php');
}

return false; // let php -S serve the static file

function dev_route(string $script): bool
{
    if (!is_file($script)) {
        http_response_code(404);
        echo "Not found\n";
        return true;
    }
    require $script;
    return true;
}
