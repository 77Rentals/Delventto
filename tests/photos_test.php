<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/photos.php';

// ---- fixtures built in code, no binary files committed ----

function fx_jpeg(string $extraSegments = ''): string
{
    $app0 = "\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
    $dqt = "\xFF\xDB\x00\x05\x00\x01\x02";
    $sos = "\xFF\xDA\x00\x04\x00\x00";
    // scan data contains an escaped FF00 and a fake-looking E1 pair after FF00
    $scan = "\x12\x34\xFF\x00\xE1\x56";
    return "\xFF\xD8" . $app0 . $extraSegments . $dqt . $sos . $scan . "\xFF\xD9";
}

function fx_exif_segment(string $marker = 'GPS'): string
{
    $payload = "Exif\x00\x00" . $marker . ' 4.5N 75.5W';
    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}

function fx_png_chunk(string $type, string $data): string
{
    return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
}

function fx_png(string $extraChunks = ''): string
{
    $ihdr = pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0);
    $idat = "\x78\x9C\x63\x60\x00\x00\x00\x02\x00\x01";
    return "\x89PNG\r\n\x1A\n" . fx_png_chunk('IHDR', $ihdr) . $extraChunks
        . fx_png_chunk('IDAT', $idat) . fx_png_chunk('IEND', '');
}

function fx_webp_chunk(string $id, string $data): string
{
    return $id . pack('V', strlen($data)) . $data . (strlen($data) % 2 ? "\x00" : '');
}

function fx_webp(string $extraChunks = ''): string
{
    $body = 'WEBP' . fx_webp_chunk('VP8X', "\x0C\x00\x00\x00\x00\x00\x00\x00\x00\x00")
        . fx_webp_chunk('VP8L', "\x2F\x00\x00\x00\x00") . $extraChunks;
    return 'RIFF' . pack('V', strlen($body)) . $body;
}

// ---- JPEG ----

test('photo_accept strips APP1 GPS from a JPEG and keeps it well formed', function () {
    $in = fx_jpeg(fx_exif_segment());
    ok(str_contains($in, 'GPS'));
    $r = photo_accept($in, 'image/jpeg');
    ok($r['ok'], 'should accept');
    eq($r['ext'], 'jpg');
    ok(!str_contains($r['bytes'], 'GPS'), 'GPS must be gone');
    ok(!str_contains($r['bytes'], "\xFF\xE1\x00"), 'no APP1 marker');
    eq(substr($r['bytes'], 0, 2), "\xFF\xD8");
    eq(substr($r['bytes'], -2), "\xFF\xD9");
    // scan data (including FF00 E1 bytes) is copied verbatim
    ok(str_contains($r['bytes'], "\x12\x34\xFF\x00\xE1\x56"));
});

test('photo_accept strips APP13 from a JPEG', function () {
    $app13 = "\xFF\xED\x00\x09Photosh";
    $r = photo_accept(fx_jpeg($app13), 'image/jpeg');
    ok($r['ok']);
    ok(!str_contains($r['bytes'], 'Photosh'));
});

test('photo_accept refuses a truncated JPEG segment', function () {
    $in = "\xFF\xD8\xFF\xE1\x00\xFFExif";
    $r = photo_accept($in, 'image/jpeg');
    ok(!$r['ok']);
    ok(isset($r['message']) && $r['message'] !== '');
});

test('photo_accept refuses a JPEG with a bad marker byte', function () {
    $in = "\xFF\xD8\x12\x34\x56\x78";
    $r = photo_accept($in, 'image/jpeg');
    ok(!$r['ok']);
});

// ---- refusals ----

test('photo_accept refuses a text file named jpg', function () {
    $r = photo_accept("hola esto no es una foto\n", 'image/jpeg');
    ok(!$r['ok']);
    eq($r['message'], 'Eso no parece una foto (JPG, PNG o WebP)');
});

test('photo_accept refuses a PNG declared as JPEG', function () {
    $r = photo_accept(fx_png(), 'image/jpeg');
    ok(!$r['ok']);
});

test('photo_accept refuses an unknown declared type', function () {
    $r = photo_accept(fx_png(), 'application/pdf');
    ok(!$r['ok']);
});

test('photo_accept refuses more than 8 MB and accepts exactly 8 MB boundary check', function () {
    $big = fx_jpeg() . str_repeat('A', 8 * 1024 * 1024);
    $r = photo_accept($big, 'image/jpeg');
    ok(!$r['ok']);
    eq($r['message'], 'Esa foto pesa más de 8 MB');
    $exact = str_repeat('A', 8 * 1024 * 1024 + 1);
    $r = photo_accept($exact, 'image/jpeg');
    eq($r['message'], 'Esa foto pesa más de 8 MB');
});

// ---- PNG ----

test('photo_accept passes a valid PNG and strips text chunks', function () {
    $extra = fx_png_chunk('tEXt', "Comment\x00GPS here")
        . fx_png_chunk('eXIf', 'GPSdata')
        . fx_png_chunk('iTXt', "k\x00\x00\x00\x00\x00GPS")
        . fx_png_chunk('zTXt', "k\x00\x00GPS");
    $r = photo_accept(fx_png($extra), 'image/png');
    ok($r['ok']);
    eq($r['ext'], 'png');
    ok(!str_contains($r['bytes'], 'GPS'));
    foreach (['tEXt', 'eXIf', 'iTXt', 'zTXt'] as $c) {
        ok(!str_contains($r['bytes'], $c), "$c removed");
    }
    ok(str_contains($r['bytes'], 'IHDR') && str_contains($r['bytes'], 'IDAT') && str_contains($r['bytes'], 'IEND'));
    eq($r['bytes'], fx_png(), 'equals clean PNG');
});

test('photo_accept refuses a truncated PNG', function () {
    $in = substr(fx_png(), 0, -6);
    $r = photo_accept($in, 'image/png');
    ok(!$r['ok']);
});

// ---- WebP ----

test('photo_accept strips EXIF and XMP from WebP and fixes the RIFF size', function () {
    $extra = fx_webp_chunk('EXIF', 'GPSdata') . fx_webp_chunk('XMP ', 'GPSxmp!');
    $in = fx_webp($extra);
    $r = photo_accept($in, 'image/webp');
    ok($r['ok']);
    eq($r['ext'], 'webp');
    ok(!str_contains($r['bytes'], 'GPS'));
    ok(!str_contains($r['bytes'], 'EXIF') && !str_contains($r['bytes'], 'XMP '));
    $size = unpack('V', substr($r['bytes'], 4, 4))[1];
    eq($size, strlen($r['bytes']) - 8);
    // VP8X exif/xmp flags cleared
    eq(ord($r['bytes'][20]) & 0x0C, 0);
});

test('photo_accept refuses a truncated WebP chunk', function () {
    $in = substr(fx_webp(fx_webp_chunk('EXIF', 'GPSdata')), 0, -4);
    $r = photo_accept($in, 'image/webp');
    ok(!$r['ok']);
});

// ---- GD path ----

test('photo_accept downscales to 1600 px when GD is loaded', function () {
    if (!extension_loaded('gd')) {
        echo "skipped: no GD (photo downscale)\n";
        return;
    }
    $img = imagecreatetruecolor(3200, 1600);
    ob_start();
    imagejpeg($img, null, 90);
    $jpg = (string) ob_get_clean();
    $r = photo_accept($jpg, 'image/jpeg');
    ok($r['ok']);
    $info = getimagesizefromstring($r['bytes']);
    eq($info[0], 1600);
    eq($info[1], 800);
});

// ---- orientation ----

function fx_exif_orient(int $o, bool $withGps = true, string $order = 'MM'): string
{
    $tiff = $order === 'MM'
        ? "MM\x00*\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01" . pack('n', $o) . "\x00\x00\x00\x00\x00\x00"
        : "II*\x00\x08\x00\x00\x00\x01\x00\x12\x01\x03\x00\x01\x00\x00\x00" . pack('v', $o) . "\x00\x00\x00\x00\x00\x00";
    $payload = "Exif\x00\x00" . $tiff . ($withGps ? 'GPS 4.5N 75.5W' : '');
    return "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
}

function fx_read_orientation(string $jpeg): ?int
{
    if (substr($jpeg, 2, 2) !== "\xFF\xE1") {
        return null;
    }
    $o = photo_jpeg_orientation($jpeg);
    return $o;
}

test('orientation 6 survives, GPS does not', function () {
    foreach (['MM', 'II'] as $order) {
        $r = photo_accept(fx_jpeg(fx_exif_orient(6, true, $order)), 'image/jpeg');
        ok($r['ok']);
        eq(substr($r['bytes'], 2, 2), "\xFF\xE1", 'APP1 right after SOI');
        eq(fx_read_orientation($r['bytes']), 6);
        ok(!str_contains($r['bytes'], 'GPS'));
        eq(substr($r['bytes'], 0, 2), "\xFF\xD8");
        eq(substr($r['bytes'], -2), "\xFF\xD9");
    }
});

test('orientation 1 or absent gives no APP1', function () {
    $r = photo_accept(fx_jpeg(fx_exif_orient(1)), 'image/jpeg');
    ok($r['ok']);
    ok(!str_contains($r['bytes'], "\xFF\xE1"));
    $r = photo_accept(fx_jpeg(fx_exif_segment()), 'image/jpeg');
    ok($r['ok']);
    ok(!str_contains($r['bytes'], "\xFF\xE1"));
});

test('malformed Exif block gives no APP1 and does not refuse', function () {
    $payload = "Exif\x00\x00MM\x00*\xFF\xFF\xFF\xFFGPS";
    $bad = "\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload;
    $r = photo_accept(fx_jpeg($bad), 'image/jpeg');
    ok($r['ok']);
    ok(!str_contains($r['bytes'], "\xFF\xE1"));
    ok(!str_contains($r['bytes'], 'GPS'));
});

// ---- trailers ----

test('photo_accept drops data after the final EOI', function () {
    $r = photo_accept(fx_jpeg() . "Exif\x00\x00GPS 4.5N", 'image/jpeg');
    ok($r['ok']);
    ok(!str_contains($r['bytes'], 'GPS'));
    eq($r['bytes'], fx_jpeg());
});

test('photo_accept refuses JPEG with EOI before any scan', function () {
    $in = "\xFF\xD8\xFF\xD9\xFF\xE1\x00\x0EExif\x00\x00GPS";
    $r = photo_accept($in, 'image/jpeg');
    ok(!$r['ok']);
});

test('photo_accept refuses JPEG scan with no EOI', function () {
    $in = substr(fx_jpeg(), 0, -2);
    $r = photo_accept($in, 'image/jpeg');
    ok(!$r['ok']);
});
