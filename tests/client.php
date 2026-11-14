<?php
declare(strict_types=1);

// Signed CLI client for the live check.
// Usage: php tests/client.php <METHOD> <path> [file-or-json] [content-type]
//        php tests/client.php UPLOAD <slug> <name> <image-file>   (base64 JSON photo upload)
// Env: GUIDE_BASE_URL (default https://delventto.cloud),
//      GUIDE_SECRET_FILE (path to a file holding the secret; never passed on argv).

require_once __DIR__ . '/../_guide/lib/auth.php';

/** Headers for a signed request. The signed path excludes the query string, like the server. */
function client_signed_headers(string $secret, int $ts, string $method, string $pathWithQuery, string $body): array
{
    $path = explode('?', $pathWithQuery, 2)[0];
    return [
        'X-Guide-Timestamp' => (string) $ts,
        'X-Guide-Signature' => sign_request($secret, $ts, strtoupper($method), $path, $body),
    ];
}

/** MIME type from the file's magic bytes, or null when it is not a JPG, PNG or WebP. */
function client_image_type(string $bytes): ?string
{
    if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($bytes, "\x89PNG\r\n\x1A\n", 8) === 0) {
        return 'image/png';
    }
    if (strncmp($bytes, 'RIFF', 4) === 0 && substr($bytes, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return null;
}

/**
 * Signed base64 JSON photo upload (DreamHost rejects binary POST bodies).
 * Returns ['path','body','ctype','headers'], or null when the bytes are not an image.
 */
function client_upload_request(string $secret, int $ts, string $slug, string $name, string $bytes): ?array
{
    $type = client_image_type($bytes);
    if ($type === null) {
        return null;
    }
    $path = '/api/guides/' . $slug . '/photos';
    $body = json_encode(['name' => $name, 'type' => $type, 'data' => base64_encode($bytes)], JSON_UNESCAPED_SLASHES);
    return [
        'path' => $path,
        'body' => $body,
        'ctype' => 'application/json',
        'headers' => client_signed_headers($secret, $ts, 'POST', $path, $body),
    ];
}

function client_main(array $argv): int
{
    $upload = strtoupper($argv[1] ?? '') === 'UPLOAD';
    if (count($argv) < 3 || ($upload && count($argv) < 5)) {
        fwrite(STDERR, "Usage: php tests/client.php <METHOD> <path> [file-or-json] [content-type]\n       php tests/client.php UPLOAD <slug> <name> <image-file>\n");
        return 2;
    }
    $method = strtoupper($argv[1]);
    $path = $argv[2];
    $arg = $argv[3] ?? '';
    $ctype = $argv[4] ?? '';

    $secretFile = (string) getenv('GUIDE_SECRET_FILE');
    if ($secretFile === '' || !is_file($secretFile)) {
        fwrite(STDERR, "Set GUIDE_SECRET_FILE to a file containing the secret.\n");
        return 2;
    }
    $secret = trim((string) file_get_contents($secretFile));
    if ($secret === '') {
        fwrite(STDERR, "Secret file is empty.\n");
        return 2;
    }
    $base = rtrim((string) (getenv('GUIDE_BASE_URL') ?: 'https://delventto.cloud'), '/');

    $body = '';
    $uploadHeaders = null;
    if ($upload) {
        $file = $argv[4];
        $bytes = is_file($file) ? (string) file_get_contents($file) : '';
        $req = client_upload_request($secret, time(), $argv[2], $argv[3], $bytes);
        if ($req === null) {
            fwrite(STDERR, "Not a readable JPG, PNG or WebP file: $file\n");
            return 2;
        }
        $method = 'POST';
        $path = $req['path'];
        $body = $req['body'];
        $ctype = $req['ctype'];
        $uploadHeaders = $req['headers'];
    } elseif ($arg !== '') {
        if (is_file($arg)) {
            $body = (string) file_get_contents($arg);
            if ($ctype === '') {
                $ext = strtolower(pathinfo($arg, PATHINFO_EXTENSION));
                $types = ['json' => 'application/json', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
                    'png' => 'image/png', 'webp' => 'image/webp'];
                $ctype = $types[$ext] ?? 'application/octet-stream';
            }
        } else {
            $body = $arg;
            if ($ctype === '') {
                $ctype = 'application/json';
            }
        }
    }

    $lines = [];
    foreach ($uploadHeaders ?? client_signed_headers($secret, time(), $method, $path, $body) as $k => $v) {
        $lines[] = "$k: $v";
    }
    if ($ctype !== '') {
        $lines[] = 'Content-Type: ' . $ctype;
    }

    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    if ($body !== '') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        fwrite(STDERR, 'Request failed: ' . curl_error($ch) . "\n");
        return 1;
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    echo "HTTP $status\n";
    $decoded = json_decode((string) $resp, true);
    if ($decoded === null) {
        echo (string) $resp . "\n";
    } else {
        echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }
    return $status >= 200 && $status < 300 ? 0 : 1;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    exit(client_main($argv));
}
