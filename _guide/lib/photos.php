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

    $orientation = $ext === 'jpg' ? photo_jpeg_orientation($bytes) : 1;

    $stripped = match ($ext) {
        'jpg' => photo_strip_jpeg($bytes),
        'png' => photo_strip_png($bytes),
        'webp' => photo_strip_webp($bytes),
    };
    if ($stripped === null) {
        return photo_fail('Esa foto parece dañada, prueba enviarla otra vez');
    }

    // GD availability is checked at runtime, not assumed.
    $resized = extension_loaded('gd') ? photo_gd_downscale($stripped, $ext, $orientation) : null;
    if ($resized !== null) {
        $stripped = $resized;
    } elseif ($ext === 'jpg' && $orientation >= 2 && $orientation <= 8) {
        // No GD, or GD failed: pixels stay as sent, so keep only the orientation tag.
        $stripped = "\xFF\xD8" . photo_orientation_app1($orientation) . substr($stripped, 2);
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
        if ($marker === 0xD9) {        // EOI before any scan: no image
            return null;
        }
        if ($marker === 0xDA) {        // SOS: scan data verbatim, cut after the last EOI
            $rest = substr($b, $pos);
            $end = strrpos($rest, "\xFF\xD9");
            return $end === false ? null : $out . substr($rest, 0, $end + 2);
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

/** Downscales to PHOTO_MAX_SIDE and re-encodes. Returns null on any failure so the caller falls back. */
function photo_gd_downscale(string $bytes, string $ext, int $orientation = 1): ?string
{
    if (!extension_loaded('gd')) {
        return null;
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return null;
    }
    if ($ext === 'jpg' && $orientation >= 2 && $orientation <= 8) {
        $src = photo_gd_orient($src, $orientation);
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
    return ($done && $out !== '') ? $out : null;
}

/** Applies an EXIF orientation (2..8) to the pixels so no EXIF is needed. */
function photo_gd_orient(GdImage $img, int $o): GdImage
{
    $rot = static fn (GdImage $i, int $deg): GdImage => imagerotate($i, $deg, 0) ?: $i;
    switch ($o) {
        case 2: imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
        case 3: return $rot($img, 180);
        case 4: imageflip($img, IMG_FLIP_VERTICAL); return $img;
        case 5: $img = $rot($img, 270); imageflip($img, IMG_FLIP_HORIZONTAL); return $img;
        case 6: return $rot($img, 270);
        case 7: $img = $rot($img, 270); imageflip($img, IMG_FLIP_VERTICAL); return $img;
        case 8: return $rot($img, 90);
    }
    return $img;
}

/** EXIF Orientation (1..8) from the first Exif APP1 of a JPEG; 1 on any problem. */
function photo_jpeg_orientation(string $b): int
{
    $len = strlen($b);
    $pos = 2;
    while ($pos + 4 <= $len && $b[$pos] === "\xFF") {
        $marker = ord($b[$pos + 1]);
        if ($marker === 0xDA || $marker === 0xD9) {
            return 1;
        }
        if ($marker === 0xFF) {
            $pos++;
            continue;
        }
        $segLen = (ord($b[$pos + 2]) << 8) | ord($b[$pos + 3]);
        if ($segLen < 2 || $pos + 2 + $segLen > $len) {
            return 1;
        }
        if ($marker === 0xE1 && substr($b, $pos + 4, 6) === "Exif\x00\x00") {
            return photo_tiff_orientation(substr($b, $pos + 10, $segLen - 8));
        }
        $pos += 2 + $segLen;
    }
    return 1;
}

function photo_tiff_orientation(string $t): int
{
    if (strlen($t) < 8) {
        return 1;
    }
    $order = substr($t, 0, 2);
    if ($order !== 'II' && $order !== 'MM') {
        return 1;
    }
    $s = $order === 'II' ? 'v' : 'n';
    $l = $order === 'II' ? 'V' : 'N';
    if (unpack($s, substr($t, 2, 2))[1] !== 42) {
        return 1;
    }
    $off = unpack($l, substr($t, 4, 4))[1];
    if ($off + 2 > strlen($t)) {
        return 1;
    }
    $n = unpack($s, substr($t, $off, 2))[1];
    for ($i = 0; $i < $n; $i++) {
        $e = $off + 2 + $i * 12;
        if ($e + 12 > strlen($t)) {
            return 1;
        }
        if (unpack($s, substr($t, $e, 2))[1] === 0x0112) {
            if (unpack($s, substr($t, $e + 2, 2))[1] !== 3 || unpack($l, substr($t, $e + 4, 4))[1] !== 1) {
                return 1;
            }
            $v = unpack($s, substr($t, $e + 8, 2))[1];
            return ($v >= 1 && $v <= 8) ? $v : 1;
        }
    }
    return 1;
}

/** A minimal APP1 holding only an Exif IFD0 with the Orientation entry. */
function photo_orientation_app1(int $o): string
{
    $tiff = "MM\x00*\x00\x00\x00\x08" . "\x00\x01" . "\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $o) . "\x00\x00" . "\x00\x00\x00\x00";
    $payload = "Exif\x00\x00" . $tiff;
    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}
