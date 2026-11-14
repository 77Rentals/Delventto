<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/config.php';
require_once __DIR__ . '/../_guide/lib/slug.php';
require_once __DIR__ . '/../_guide/lib/validate.php';

function vt_building(): array
{
    return [
        'id' => 'delventto',
        'places' => [
            'playa-del-ritmo' => ['name' => 'Playa del Ritmo'],
            'tayrona' => ['name' => 'Tayrona'],
        ],
    ];
}

function vt_fixture(string $name): array
{
    $raw = file_get_contents(__DIR__ . '/fixtures/' . $name . '.json');
    $data = json_decode((string) $raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('bad fixture ' . $name);
    }
    return $data;
}

function vt_run(array $g): array
{
    return validate_guide($g, vt_building(), (string) ($g['slug'] ?? ''));
}

function vt_fields(array $problems): array
{
    return array_map(fn ($p) => $p['field'], $problems);
}

function vt_has(array $problems, string $field): bool
{
    return in_array($field, vt_fields($problems), true);
}

test('validate: 613t2 fixture is valid', function () {
    eq(vt_run(vt_fixture('613t2')), []);
});

test('validate: minimal fixture is valid', function () {
    eq(vt_run(vt_fixture('minimal')), []);
});

test('validate: fixtures contain no em or en dashes', function () {
    foreach (['613t2', 'minimal'] as $f) {
        $raw = (string) file_get_contents(__DIR__ . '/fixtures/' . $f . '.json');
        ok(strpos($raw, "\u{2014}") === false && strpos($raw, "\u{2013}") === false, "$f has a dash");
    }
});

test('validate: missing hosts.whatsapp gives one problem on that field', function () {
    $g = vt_fixture('613t2');
    unset($g['hosts']['whatsapp']);
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'hosts.whatsapp');
    eq($p[0]['message'], 'Falta el WhatsApp del anfitrión');
});

test('validate: bad whatsapp format is caught', function () {
    $g = vt_fixture('613t2');
    $g['hosts']['whatsapp'] = '311 754 8493';
    ok(vt_has(vt_run($g), 'hosts.whatsapp'));
});

test('validate: em dash in welcome.es is caught', function () {
    $g = vt_fixture('613t2');
    $g['welcome']['es'] = "Hola \u{2014} bienvenido";
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'welcome.es');
    eq($p[0]['message'], 'Cambia la raya por coma o punto');
});

test('validate: en dash is caught too', function () {
    $g = vt_fixture('613t2');
    $g['coffee']['en'] = "9:00 \u{2013} 6:00";
    ok(vt_has(vt_run($g), 'coffee.en'));
});

test('validate: door code in lock step is caught', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['text']['es'] = 'Clave de la puerta: 4821';
    $p = vt_run($g);
    ok(vt_has($p, 'lock.steps.0.text.es'));
    $msg = '';
    foreach ($p as $x) {
        if ($x['field'] === 'lock.steps.0.text.es' && strpos($x['message'], 'código de puerta') !== false) {
            $msg = $x['message'];
        }
    }
    eq($msg, 'Eso parece un código de puerta. El código se lo mandas tú al huésped por WhatsApp; no va en la guía.');
});

test('validate: standalone number in lock step is caught even without a keyword', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'][1]['text']['en'] = 'Type 482913 and press the button';
    ok(vt_has(vt_run($g), 'lock.steps.1.text.en'));
});

test('validate: door code keyword in welcome, rules and hosts is caught', function () {
    $g = vt_fixture('613t2');
    $g['welcome']['en'] = 'Your PIN is 9921 on arrival';
    ok(vt_has(vt_run($g), 'welcome.en'));
    $g = vt_fixture('613t2');
    $g['rules'][0]['text']['es'] = 'Contraseña de la puerta 123456';
    ok(vt_has(vt_run($g), 'rules.0.text.es'));
    $g = vt_fixture('613t2');
    $g['hosts']['about']['es'] = 'El código es 5555';
    ok(vt_has(vt_run($g), 'hosts.about.es'));
});

test('validate: lock step without a number is fine', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['text']['es'] = 'Pulsa el botón Yale y luego tu código';
    eq(vt_run($g), []);
});

test('validate: wifi password with digits is exempt', function () {
    $g = vt_fixture('613t2');
    $g['wifi']['password'] = '2GbR9cdH';
    eq(vt_run($g), []);
    $g['wifi']['password'] = 'clave 12345678';
    eq(vt_run($g), []);
});

test('validate: places.building must exist in the building file', function () {
    $g = vt_fixture('613t2');
    $g['places']['building'] = ['nope'];
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'places.building');
});

test('validate: building must equal the building id', function () {
    $g = vt_fixture('613t2');
    $g['building'] = 'otro';
    ok(vt_has(vt_run($g), 'building'));
});

test('validate: slug must equal the URL slug and be valid', function () {
    $g = vt_fixture('613t2');
    $p = validate_guide($g, vt_building(), 'otro-slug');
    ok(vt_has($p, 'slug'));
    $g['slug'] = 'Bad Slug';
    ok(vt_has(validate_guide($g, vt_building(), 'Bad Slug'), 'slug'));
});

test('validate: photos needs 3 to 40', function () {
    $g = vt_fixture('613t2');
    $g['photos'] = array_slice($g['photos'], 0, 2);
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'photos');
    $g = vt_fixture('613t2');
    $one = $g['photos'][0];
    $g['photos'] = array_fill(0, 41, $one);
    ok(vt_has(vt_run($g), 'photos'));
});

test('validate: tourVideo.url must be https or a same-site path', function () {
    $g = vt_fixture('613t2');
    $g['tourVideo']['url'] = 'http://x';
    ok(vt_has(vt_run($g), 'tourVideo.url'));
    $g['tourVideo']['url'] = '//evil.example/x';
    ok(vt_has(vt_run($g), 'tourVideo.url'));
    $g['tourVideo']['url'] = 'https://youtu.be/abc';
    eq(vt_run($g), []);
    $g['pdf'] = ['url' => 'ftp://x'];
    ok(vt_has(vt_run($g), 'pdf.url'));
    $g['pdf'] = ['url' => '/apartasuite-613t2/guia.pdf'];
    eq(vt_run($g), []);
});

test('validate: HTML in a caption is caught', function () {
    $g = vt_fixture('613t2');
    $g['photos'][0]['caption']['es'] = 'Cocina <b>nueva</b>';
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'photos.0.caption.es');
    eq($p[0]['message'], 'Sin HTML, por favor');
});

test('validate: theme keys', function () {
    $g = vt_fixture('613t2');
    $g['theme'] = ['palette' => 'selva', 'motion' => 'ninguno'];
    eq(vt_run($g), []);
    $g['theme'] = ['palette' => 'neon', 'motion' => 'marino'];
    $p = vt_run($g);
    eq(count($p), 1);
    eq($p[0]['field'], 'theme.palette');
    eq($p[0]['message'], 'Ese tema no existe');
    $g['theme'] = ['palette' => 'caribe', 'motion' => 'lluvia'];
    ok(vt_has(vt_run($g), 'theme.motion'));
    $g['theme'] = ['palette' => 'tayrona', 'motion' => 'luciernagas'];
    eq(vt_run($g), [], 'new theme keys are accepted');
    $g['theme'] = ['palette' => 'nope', 'motion' => 'luciernagas'];
    eq(vt_fields(vt_run($g)), ['theme.palette']);
    $g['theme'] = ['palette' => 'tayrona', 'motion' => 'nope'];
    eq(vt_fields(vt_run($g)), ['theme.motion']);
});

test('validate: T needs both languages and respects limits per language', function () {
    $g = vt_fixture('613t2');
    $g['name']['en'] = '';
    ok(vt_has(vt_run($g), 'name.en'));
    $g = vt_fixture('613t2');
    unset($g['tagline']['es']);
    ok(vt_has(vt_run($g), 'tagline.es'));
    $g = vt_fixture('613t2');
    $g['name']['es'] = str_repeat('a', 61);
    ok(vt_has(vt_run($g), 'name.es'));
    $g['name']['es'] = str_repeat('ñ', 60);
    eq(vt_run($g), []);
});

test('validate: lengths count characters not bytes', function () {
    $g = vt_fixture('613t2');
    $g['highlights'][0]['es'] = str_repeat('é', 40);
    eq(vt_run($g), []);
    $g['highlights'][0]['es'] = str_repeat('é', 41);
    ok(vt_has(vt_run($g), 'highlights.0.es'));
});

test('validate: times must be HH:MM', function () {
    $g = vt_fixture('613t2');
    $g['checkin'] = '3 PM';
    ok(vt_has(vt_run($g), 'checkin'));
    $g = vt_fixture('613t2');
    $g['checkout'] = '25:00';
    ok(vt_has(vt_run($g), 'checkout'));
    $g = vt_fixture('613t2');
    unset($g['checkout']);
    ok(vt_has(vt_run($g), 'checkout'));
});

test('validate: lock steps 1 to 6, gateCode and unit formats', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'] = [];
    ok(vt_has(vt_run($g), 'lock.steps'));
    $g = vt_fixture('613t2');
    $s = $g['lock']['steps'][0];
    $g['lock']['steps'] = array_fill(0, 7, $s);
    ok(vt_has(vt_run($g), 'lock.steps'));
    $g = vt_fixture('613t2');
    $g['gateCode'] = 'a b';
    ok(vt_has(vt_run($g), 'gateCode'));
    $g = vt_fixture('613t2');
    $g['unit'] = '';
    ok(vt_has(vt_run($g), 'unit'));
});

test('validate: own places limits and required keys', function () {
    $g = vt_fixture('613t2');
    unset($g['places']['own'][0]['mapsQuery']);
    ok(vt_has(vt_run($g), 'places.own.0.mapsQuery'));
    $g = vt_fixture('613t2');
    $g['places']['own'][1]['text']['es'] = str_repeat('a', 301);
    ok(vt_has(vt_run($g), 'places.own.1.text.es'));
    $g = vt_fixture('613t2');
    $p = $g['places']['own'][0];
    $g['places']['own'] = array_fill(0, 11, $p);
    ok(vt_has(vt_run($g), 'places.own'));
});

test('validate: missing required top-level fields are reported', function () {
    foreach (['name', 'tagline', 'welcome', 'lock', 'places', 'photos', 'hosts', 'features', 'rules', 'beforeLeaving', 'highlights'] as $k) {
        $g = vt_fixture('613t2');
        unset($g[$k]);
        ok(vt_has(vt_run($g), $k) || count(array_filter(vt_fields(vt_run($g)), fn ($f) => strpos($f, $k . '.') === 0)) > 0, "missing $k not reported");
    }
});

test('validate: wrong types do not crash', function () {
    $g = vt_fixture('613t2');
    $g['name'] = 'texto plano';
    $g['photos'] = 'no';
    $g['hosts'] = ['whatsapp' => 5];
    $p = vt_run($g);
    ok(count($p) >= 3);
});

test('validate: unit number in a lock step is not a door code', function () {
    $g = vt_fixture('613t2');
    $g['unit'] = '1204';
    $g['lock']['steps'][0]['text']['es'] = 'Sube al piso 12, apartamento 1204';
    eq(vt_run($g), []);
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['text']['es'] = 'Apartamento 613T2, tu código llega por WhatsApp';
    eq(vt_run($g), []);
});

test('validate: keywords match whole words only', function () {
    $g = vt_fixture('613t2');
    $g['welcome']['es'] = 'Shopping center a 200 metros';
    eq(vt_run($g), []);
    $g['welcome']['es'] = 'Opiniones increíbles, 500 reseñas y un decode de 300 cosas';
    eq(vt_run($g), []);
    $g['welcome']['es'] = 'Usa el código postal 470001 para tus envíos';
    eq(vt_run($g), []);
});

test('validate: digits before the keyword are caught', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['text']['es'] = '4821 es tu código';
    ok(vt_has(vt_run($g), 'lock.steps.0.text.es'));
    $g = vt_fixture('613t2');
    $g['welcome']['en'] = 'Use 4821 as your PIN';
    ok(vt_has(vt_run($g), 'welcome.en'));
});

test('validate: keyword plus code still caught after the fix', function () {
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['text']['es'] = 'Clave de la puerta: 4821';
    ok(vt_has(vt_run($g), 'lock.steps.0.text.es'));
});

test('validate: lock steps accept plain T and {title, text}', function () {
    $g = vt_fixture('613t2');
    eq($g['lock']['steps'][0]['title']['es'], 'Registro', 'fixture carries the 613T2 titles');
    $g['lock']['steps'][] = ['es' => 'Paso sin título', 'en' => 'Step with no title'];
    $g['lock']['steps'][] = ['text' => ['es' => 'Solo texto', 'en' => 'Text only']];
    eq(vt_run($g), [], 'mixed shapes');
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0] = ['title' => ['es' => 'Acceso', 'en' => 'Access']];
    eq(vt_fields(vt_run($g)), ['lock.steps.0.text'], 'title without text');
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['title']['en'] = str_repeat('a', 41);
    eq(vt_fields(vt_run($g)), ['lock.steps.0.title.en'], 'title over 40');
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['title']['es'] = 'Clave 4821';
    ok(vt_has(vt_run($g), 'lock.steps.0.title.es'), 'door code in a title');
    $g = vt_fixture('613t2');
    $g['lock']['steps'][0]['title']['es'] = 'Acceso <b>';
    ok(vt_has(vt_run($g), 'lock.steps.0.title.es'), 'HTML in a title');
});

test('validate: door code in a plain T lock step is still caught', function () {
    $g = vt_fixture('minimal');
    $g['lock']['steps'][0]['es'] = 'Clave de la puerta: 4821';
    ok(vt_has(vt_run($g), 'lock.steps.0.es'));
});
