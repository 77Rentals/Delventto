<?php
declare(strict_types=1);

// Signed CLI client for the live check.
// Usage: php tests/client.php <METHOD> <path> [file-or-json] [content-type]
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

function client_main(array $argv): int
{
    if (count($argv) < 3) {
        fwrite(STDERR, "Usage: php tests/client.php <METHOD> <path> [file-or-json] [content-type]\n");
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
    if ($arg !== '') {
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
    foreach (client_signed_headers($secret, time(), $method, $path, $body) as $k => $v) {
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
