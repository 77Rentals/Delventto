<?php
declare(strict_types=1);

const PHOTO_MAX_BYTES = 8 * 1024 * 1024;
const PHOTO_MAX_SIDE = 1600;

function photo_fail(string $message): array
{
    return ['ok' => false, 'message' => $message];
}

/**
 * Validates an uploaded photo, strips location and text metadata and, when GD
 * is available, downscales it. Returns ['ok'=>true,'bytes'=>..,'ext'=>..] or
 * ['ok'=>false,'message'=>..].
 */
function photo_accept(string $bytes, string $declaredType): array
{
    if (strlen($bytes) > PHOTO_MAX_BYTES) {
        return photo_fail('Esa foto pesa más de 8 MB');
    }

    $ext = photo_sniff($bytes);
    if ($ext === null) {
        return photo_fail('Eso no parece una foto (JPG, PNG o WebP)');
    }

    $declared = photo_declared_ext($declaredType);
    if ($declared !== $ext) {
        return photo_fail('El tipo de archivo no coincide con la foto, prueba enviarla otra vez');
    }

    $stripped = match ($ext) {
        'jpg' => photo_strip_jpeg($bytes),
        'png' => photo_strip_png($bytes),
        'webp' => photo_strip_webp($bytes),
    };
    if ($stripped === null) {
        return photo_fail('Esa foto parece dañada, prueba enviarla otra vez');
    }

    // GD availability is checked at runtime, not assumed.
    if (extension_loaded('gd')) {
        $stripped = photo_gd_downscale($stripped, $ext);
    }

    return ['ok' => true, 'bytes' => $stripped, 'ext' => $ext];
}

function photo_sniff(string $b): ?string
{
    if (strncmp($b, "\xFF\xD8\xFF", 3) === 0) {
        return 'jpg';
    }
    if (strncmp($b, "\x89PNG", 4) === 0) {
        return 'png';
    }
    if (strlen($b) >= 12 && strncmp($b, 'RIFF', 4) === 0 && substr($b, 8, 4) === 'WEBP') {
        return 'webp';
    }
    return null;
}

function photo_declared_ext(string $type): ?string
{
    return match (strtolower(trim($type))) {
        'image/jpeg', 'image/jpg', 'image/pjpeg', 'jpg', 'jpeg' => 'jpg',
        'image/png', 'png' => 'png',
        'image/webp', 'webp' => 'webp',
        default => null,
    };
}

/** Drops APP1 and APP13 segments up to SOS, copies the rest verbatim. Null if malformed. */
function photo_strip_jpeg(string $b): ?string
{
    $len = strlen($b);
    $out = "\xFF\xD8";
    $pos = 2;
    while (true) {
        if ($pos + 2 > $len || $b[$pos] !== "\xFF") {
            return null;
        }
        $marker = ord($b[$pos + 1]);
        if ($marker === 0xFF) {        // fill byte
            $pos++;
            continue;
        }
        if ($marker === 0xDA || $marker === 0xD9) { // SOS or early EOI: rest is verbatim
            return $out . substr($b, $pos);
        }
        if ($marker === 0x01 || ($marker >= 0xD0 && $marker <= 0xD8) || $marker === 0x00) {
            if ($marker === 0x00 || $marker === 0xD8) {
                return null;           // not valid before SOS
            }
            $out .= substr($b, $pos, 2);
            $pos += 2;
            continue;
        }
        if ($pos + 4 > $len) {
            return null;
        }
        $segLen = (ord($b[$pos + 2]) << 8) | ord($b[$pos + 3]);
        if ($segLen < 2 || $pos + 2 + $segLen > $len) {
            return null;
        }
        if ($marker !== 0xE1 && $marker !== 0xED) {
            $out .= substr($b, $pos, 2 + $segLen);
        }
        $pos += 2 + $segLen;
    }
}

/** Drops eXIf, tEXt, iTXt and zTXt chunks. Null if malformed. */
function photo_strip_png(string $b): ?string
{
    $len = strlen($b);
    $out = substr($b, 0, 8);
    $pos = 8;
    $sawEnd = false;
    while ($pos < $len) {
        if ($pos + 12 > $len) {
            return null;
        }
        $clen = unpack('N', substr($b, $pos, 4))[1];
        $type = substr($b, $pos + 4, 4);
        $total = 12 + $clen;
        if ($pos + $total > $len) {
            return null;
        }
        if (!in_array($type, ['eXIf', 'tEXt', 'iTXt', 'zTXt'], true)) {
            $out .= substr($b, $pos, $total);
        }
        $pos += $total;
        if ($type === 'IEND') {
            $sawEnd = true;
            break;
        }
    }
    return $sawEnd ? $out : null;
}

/** Drops EXIF and XMP chunks, clears their VP8X flags and fixes the RIFF size. Null if malformed. */
function photo_strip_webp(string $b): ?string
{
    $len = strlen($b);
    $declared = unpack('V', substr($b, 4, 4))[1] + 8;
    if ($declared > $len || $declared < 12) {
        return null;
    }
    $body = '';
    $pos = 12;
    while ($pos < $declared) {
        if ($pos + 8 > $declared) {
            return null;
        }
        $id = substr($b, $pos, 4);
        $size = unpack('V', substr($b, $pos + 4, 4))[1];
        $total = 8 + $size + ($size % 2);
        if ($pos + 8 + $size > $declared) {
            return null;
        }
        $chunk = substr($b, $pos, 8 + $size);
        if ($size % 2) {
            $chunk .= "\x00";
        }
        if ($id === 'EXIF' || $id === 'XMP ') {
            // dropped
        } else {
            if ($id === 'VP8X' && $size >= 1) {
                $chunk[8] = chr(ord($chunk[8]) & ~0x0C);
            }
            $body .= $chunk;
        }
        $pos += $total;
    }
    $body = 'WEBP' . $body;
    return 'RIFF' . pack('V', strlen($body)) . $body;
}

/** Downscales to PHOTO_MAX_SIDE and re-encodes. Falls back to the input bytes on any failure. */
function photo_gd_downscale(string $bytes, string $ext): string
{
    if (!extension_loaded('gd')) {
        return $bytes;
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return $bytes;
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $long = max($w, $h);
    if ($long > PHOTO_MAX_SIDE) {
        $scale = PHOTO_MAX_SIDE / $long;
        $scaled = imagescale($src, max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
        if ($scaled !== false) {
            $src = $scaled;
        }
    }
    ob_start();
    $done = match ($ext) {
        'jpg' => imagejpeg($src, null, 82),
        'png' => imagepng($src, null, 6),
        'webp' => function_exists('imagewebp') ? imagewebp($src, null, 82) : false,
        default => false,
    };
    $out = (string) ob_get_clean();
    return ($done && $out !== '') ? $out : $bytes;
}
