<?php
declare(strict_types=1);

// Signed guide API for the WhatsApp bot. Contract: GET /api/guides/{slug},
// PUT .../draft, POST .../photos?name=, DELETE .../photos/{file}, POST .../publish,
// .../rollback, .../unpublish.
// Order per request: size limit, signature (401, nothing touched), slug (400), route.

// PHP errors go to the server log, never into a response (they would leak paths and break the JSON).
ini_set('display_errors', '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');
ob_start();

require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/slug.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/validate.php';
require_once __DIR__ . '/lib/photos.php';
require_once __DIR__ . '/lib/store.php';
require_once __DIR__ . '/lib/building.php';

const API_PREFIX = '/api/guides/';
const API_MAX_JSON_BYTES = 1024 * 1024;
const API_LOG_MAX_BYTES = 5 * 1024 * 1024;
const API_ROUTES = [
    '' => 'GET',
    'draft' => 'PUT',
    'photos' => 'POST',
    'publish' => 'POST',
    'rollback' => 'POST',
    'unpublish' => 'POST',
];

api_main();

function api_main(): void
{
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $path = explode('?', (string) ($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
    $extra = [];
    try {
        [$status, $data, $extra] = array_pad(api_handle($method, $path), 3, []);
    } catch (GuideStoreError $e) {
        $status = $e->getCode() >= 400 && $e->getCode() < 500 ? $e->getCode() : 409;
        $data = api_error($e->getMessage());
    } catch (Throwable $e) {
        error_log('guide api: ' . get_class($e) . ': ' . $e->getMessage());
        $status = 500;
        $data = api_error('Error interno del servidor, intenta otra vez en un momento');
    }
    api_send($status, $data, $extra);
    api_log($method, $path, $status);
}

/** Returns [status, data] or [status, data, extraHeaders]. */
function api_handle(string $method, string $path): array
{
    if (strncmp($path, API_PREFIX, strlen(API_PREFIX)) !== 0) {
        return [404, api_error('No existe')];
    }
    $parts = explode('/', substr($path, strlen(API_PREFIX)));
    $slug = $parts[0];
    $action = $parts[1] ?? '';

    // Refuse oversized bodies before reading or verifying anything.
    $limit = API_MAX_JSON_BYTES;
    if ($action === 'photos') {
        $limit = api_content_type(api_headers()) === 'application/json' ? api_photo_json_limit() : PHOTO_MAX_BYTES;
    }
    $declared = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
    if ($declared !== '' && (!ctype_digit($declared) || (int) $declared > $limit)) {
        return [413, api_error('El contenido es demasiado grande')];
    }
    $body = file_get_contents('php://input', false, null, 0, $limit + 1);
    $body = $body === false ? '' : $body;
    if (strlen($body) > $limit) {
        return [413, api_error('El contenido es demasiado grande')];
    }

    $headers = api_headers();
    if (!verify_request(guide_secret(), $headers, $method, $path, $body, time())) {
        return [401, api_error('Firma inválida o vencida')];
    }

    if (!slug_valid($slug) || slug_reserved($slug)) {
        return [400, api_error('Esa dirección de guía no es válida o ya está en uso')];
    }
    // DELETE /api/guides/{slug}/photos/{file}
    if ($action === 'photos' && count($parts) === 3) {
        if ($method !== 'DELETE') {
            return [405, api_error('Método no permitido'), ['Allow' => 'DELETE']];
        }
        store_delete_photo($slug, $parts[2]);
        return [200, ['ok' => true]];
    }
    if (count($parts) > 2 || !array_key_exists($action, API_ROUTES)) {
        return [404, api_error('No existe')];
    }
    if ($method !== API_ROUTES[$action]) {
        return [405, api_error('Método no permitido'), ['Allow' => API_ROUTES[$action]]];
    }

    switch ($action) {
        case '':
            return api_get($slug);
        case 'draft':
            return api_put_draft($slug, $body);
        case 'photos':
            return api_add_photo($slug, $body, $headers);
        case 'publish':
            return api_publish($slug);
        case 'rollback':
            return api_rollback($slug, $body);
        default:
            store_unpublish($slug);
            return [200, ['ok' => true]];
    }
}

function api_get(string $slug): array
{
    $s = store_get($slug);
    return [200, [
        'ok' => true,
        'exists' => $s['draft'] !== null || $s['published'] !== null || $s['versions'] !== [],
        'draft' => $s['draft'],
        'published' => $s['published'],
        'versions' => $s['versions'],
        'current' => $s['current'],
        'url' => api_guide_url($slug),
    ]];
}

function api_put_draft(string $slug, string $body): array
{
    $g = api_decode_object($body);
    if ($g === null) {
        return [400, api_error('La guía no es un JSON válido')];
    }
    if (array_key_exists('slug', $g) && $g['slug'] !== $slug) {
        return [400, api_error('La dirección de la guía no coincide con la de la página')];
    }
    $problems = validate_guide($g, api_building_for($g), $slug);
    store_put_draft($slug, $g);
    return [200, ['ok' => true, 'problems' => $problems]];
}

function api_content_type(array $headers): string
{
    return strtolower(trim(explode(';', (string) ($headers['content-type'] ?? $_SERVER['CONTENT_TYPE'] ?? ''), 2)[0]));
}

function api_add_photo(string $slug, string $body, array $headers): array
{
    $type = api_content_type($headers);
    $name = $_GET['name'] ?? '';
    $name = is_string($name) ? $name : '';
    if ($type === 'application/json') {
        // Base64 mode for hosts that reject binary POST bodies: {"name","type","data"}.
        $req = api_decode_object($body);
        if ($req === null || !is_string($req['name'] ?? null) || !is_string($req['type'] ?? null) || !is_string($req['data'] ?? null)) {
            return [400, api_error('Manda un JSON con name, type y data (la foto en base64)')];
        }
        $raw = base64_decode($req['data'], true);
        if ($raw === false || $raw === '') {
            return [400, api_error('La foto en base64 no es válida')];
        }
        if (strlen($raw) > PHOTO_MAX_BYTES) {
            return [413, api_error('Esa foto pesa más de 8 MB')];
        }
        $name = $req['name'];
        $type = strtolower(trim($req['type']));
    } else {
        $raw = $body;
    }
    if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return [415, api_error('Manda la foto como JPG, PNG o WebP')];
    }
    $photo = photo_accept($raw, $type);
    if (!$photo['ok']) {
        return [400, api_error($photo['message'])];
    }
    $file = store_add_photo($slug, $name, $photo['bytes'], $photo['ext']);
    return [200, ['ok' => true, 'file' => $file]];
}

function api_publish(string $slug): array
{
    $draft = store_get($slug)['draft'];
    if ($draft === null) {
        return [409, ['ok' => false, 'problems' => [['field' => 'draft', 'message' => 'Todavía no hay borrador para publicar']]]];
    }
    $problems = validate_guide($draft, api_building_for($draft), $slug);
    foreach (guide_photo_refs($draft) as [$field, $file]) {
        if (store_photo_path($slug, $file) === null) {
            $problems[] = ['field' => $field, 'message' => "No encuentro la foto $file, envíala otra vez"];
        }
    }
    if ($problems !== []) {
        return [409, ['ok' => false, 'problems' => $problems]];
    }
    $version = store_publish($slug, $draft);
    return [200, ['ok' => true, 'url' => api_guide_url($slug), 'version' => $version]];
}

function api_rollback(string $slug, string $body): array
{
    $version = null;
    if (trim($body) !== '') {
        $req = api_decode_object($body);
        if ($req === null) {
            return [400, api_error('El cuerpo debe ser JSON, por ejemplo {"version": "20261114T101010Z"}')];
        }
        $v = $req['version'] ?? null;
        if ($v !== null && !is_string($v)) {
            return [400, api_error('La versión debe ser un texto')];
        }
        $version = $v;
    }
    $id = store_rollback($slug, $version);
    return [200, ['ok' => true, 'url' => api_guide_url($slug), 'version' => $id]];
}

// ---- helpers ----

function api_building_for(array $g): array
{
    $id = $g['building'] ?? null;
    return (is_string($id) ? guide_building($id) : null) ?? [];
}

/** Decodes a JSON object (not a list or scalar) into an array, or null. */
function api_decode_object(string $body): ?array
{
    $obj = json_decode($body);
    if (!is_object($obj)) {
        return null;
    }
    $arr = json_decode($body, true);
    return is_array($arr) ? $arr : null;
}

function api_guide_url(string $slug): string
{
    $base = getenv('GUIDE_BASE_URL');
    $base = ($base === false || $base === '') ? 'https://delventto.cloud' : $base;
    return rtrim($base, '/') . '/' . $slug . '/';
}

/** Request headers with lowercased names. */
function api_headers(): array
{
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (is_string($k) && strncmp($k, 'HTTP_', 5) === 0 && is_string($v)) {
            $out[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
        }
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() ?: [] as $k => $v) {
            $out[strtolower((string) $k)] = (string) $v;
        }
    }
    return $out;
}

function api_error(string $message): array
{
    return ['ok' => false, 'error' => $message];
}

function api_send(int $status, array $data, array $extra = []): void
{
    // Drop anything printed before this point so only the JSON goes out.
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('X-Content-Type-Options: nosniff');
    foreach ($extra as $k => $v) {
        header($k . ': ' . $v);
    }
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/** One line per request: ISO time, method, path, status. Never the body, query or headers. */
function api_log(string $method, string $path, int $status): void
{
    $dir = guide_data_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
        return;
    }
    $file = $dir . '/api.log';
    if (@filesize($file) > API_LOG_MAX_BYTES) {
        @rename($file, $file . '.1');
    }
    $clean = static fn (string $s, int $max): string => substr((string) preg_replace('/[^\x21-\x7E]/', '?', $s), 0, $max);
    $line = gmdate('Y-m-d\TH:i:s\Z') . ' ' . $clean($method, 10) . ' ' . $clean($path, 200) . ' ' . $status . "\n";
    @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}

/** Body cap for the base64 JSON photo mode: base64 of PHOTO_MAX_BYTES (4/3) plus room for the JSON envelope. */
function api_photo_json_limit(): int
{
    return intdiv(PHOTO_MAX_BYTES + 2, 3) * 4 + 4096;
}
