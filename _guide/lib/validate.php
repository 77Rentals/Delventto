<?php
declare(strict_types=1);

require_once __DIR__ . '/slug.php';
require_once __DIR__ . '/themes.php';

const GUIDE_MSG_HTML = 'Sin HTML, por favor';
const GUIDE_MSG_DASH = 'Cambia la raya por coma o punto';
const GUIDE_MSG_DOOR = 'Eso parece un código de puerta. El código se lo mandas tú al huésped por WhatsApp; no va en la guía.';
const GUIDE_MSG_THEME = 'Ese tema no existe';

/**
 * Validate a guide against the Guide JSON schema.
 * Returns a list of ['field' => 'hosts.whatsapp', 'message' => '...'] (empty when valid).
 * Messages are short Spanish sentences the bot reads to the owner.
 * File references (photos, lock.photo, hosts.photo, ac.photos) are NOT checked here.
 */
function validate_guide(array $g, array $building, string $slug): array
{
    $p = [];

    // slug
    $gs = $g['slug'] ?? null;
    if (!is_string($gs) || $gs === '') {
        guide_problem($p, 'slug', 'Falta el nombre de la dirección de la guía');
    } elseif (!slug_valid($gs) || mb_strlen($gs) < 3) {
        guide_problem($p, 'slug', 'La dirección de la guía solo puede tener minúsculas, números y guiones (3 a 40 caracteres)');
    } elseif ($gs !== $slug) {
        guide_problem($p, 'slug', 'La dirección de la guía no coincide con la de la página');
    }

    // building
    $b = $g['building'] ?? null;
    $buildingId = $building['id'] ?? null;
    if (!is_string($b) || $b === '') {
        guide_problem($p, 'building', 'Falta el edificio');
    } elseif ($b !== $buildingId) {
        guide_problem($p, 'building', 'Ese edificio no existe');
    }

    // gateCode, unit
    $gc = $g['gateCode'] ?? null;
    if (!is_string($gc) || $gc === '') {
        guide_problem($p, 'gateCode', 'Falta el código de entrada a la guía (el número del apartamento)');
    } elseif (preg_match('/^[0-9A-Za-z]{2,8}$/D', $gc) !== 1) {
        guide_problem($p, 'gateCode', 'El código de entrada a la guía debe tener de 2 a 8 letras o números, sin espacios');
    }
    guide_check_string($p, $g['unit'] ?? null, 'unit', 'el número del apartamento', 1, 20);

    // name, tagline, highlights, welcome
    guide_check_t($p, $g['name'] ?? null, 'name', 'el nombre', 60);
    guide_check_t($p, $g['tagline'] ?? null, 'tagline', 'la frase de presentación', 200);
    guide_check_list($p, $g['highlights'] ?? null, 'highlights', 'los destacados', 0, 4, function ($item, $f) use (&$p) {
        guide_check_t($p, $item, $f, 'el destacado', 40);
    });
    guide_check_t($p, $g['welcome'] ?? null, 'welcome', 'el mensaje de bienvenida', 1500);

    // times
    foreach (['checkin' => 'la hora de check-in', 'checkout' => 'la hora de check-out'] as $k => $label) {
        $v = $g[$k] ?? null;
        if (!is_string($v) || $v === '') {
            guide_problem($p, $k, "Falta $label");
        } elseif (preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $v) !== 1) {
            guide_problem($p, $k, ucfirst($label) . ' debe tener formato HH:MM, por ejemplo 15:00');
        }
    }

    // lock
    $lock = $g['lock'] ?? null;
    if (!is_array($lock)) {
        guide_problem($p, 'lock', 'Faltan los pasos para abrir la puerta');
    } else {
        // A step is a T, or {title?: T [..40], text: T} when it has a bold title (613T2 style).
        guide_check_list($p, $lock['steps'] ?? null, 'lock.steps', 'los pasos para abrir la puerta', 1, 6, function ($item, $f) use (&$p) {
            if (is_array($item) && (array_key_exists('title', $item) || array_key_exists('text', $item))) {
                guide_check_t($p, $item['text'] ?? null, "$f.text", 'el texto del paso', null);
                if (array_key_exists('title', $item)) {
                    guide_check_t($p, $item['title'], "$f.title", 'el título del paso', 40);
                }
                return;
            }
            guide_check_t($p, $item, $f, 'el paso', null);
        });
        guide_check_optional_string($p, $lock, 'photo', 'lock.photo', 'la foto de la cerradura');
    }

    // ac (optional)
    if (array_key_exists('ac', $g)) {
        $ac = $g['ac'];
        if (!is_array($ac)) {
            guide_problem($p, 'ac', 'El aire acondicionado está mal escrito');
        } else {
            guide_check_list($p, $ac['steps'] ?? null, 'ac.steps', 'los pasos del aire acondicionado', 0, 8, function ($item, $f) use (&$p) {
                guide_check_t($p, $item, $f, 'el paso', null);
            });
            if (array_key_exists('note', $ac)) {
                guide_check_t($p, $ac['note'], 'ac.note', 'la nota del aire acondicionado', null);
            }
            if (array_key_exists('photos', $ac)) {
                guide_check_list($p, $ac['photos'], 'ac.photos', 'las fotos del aire acondicionado', 0, 3, function ($item, $f) use (&$p) {
                    guide_check_string($p, $item, $f, 'la foto', 1, 200);
                });
            }
        }
    }

    // wifi (optional)
    if (array_key_exists('wifi', $g)) {
        $w = $g['wifi'];
        if (!is_array($w)) {
            guide_problem($p, 'wifi', 'El WiFi está mal escrito');
        } else {
            guide_check_string($p, $w['ssid'] ?? null, 'wifi.ssid', 'el nombre de la red WiFi', 1, 40);
            guide_check_string($p, $w['password'] ?? null, 'wifi.password', 'la clave del WiFi', 1, 64);
        }
    }

    // features
    guide_check_list($p, $g['features'] ?? null, 'features', 'las características', 0, 12, function ($item, $f) use (&$p) {
        guide_check_icon_text($p, $item, $f, 'la característica');
    });

    // tapWater, coffee, waste (optional)
    foreach (['tapWater' => 'el aviso del agua del grifo', 'coffee' => 'el texto del café', 'waste' => 'el texto de residuos'] as $k => $label) {
        if (array_key_exists($k, $g)) {
            guide_check_t($p, $g[$k], $k, $label, null);
        }
    }

    // rules, beforeLeaving
    guide_check_list($p, $g['rules'] ?? null, 'rules', 'las reglas extra', 0, 15, function ($item, $f) use (&$p) {
        guide_check_icon_text($p, $item, $f, 'la regla');
    });
    guide_check_list($p, $g['beforeLeaving'] ?? null, 'beforeLeaving', 'la lista de antes de salir', 0, 10, function ($item, $f) use (&$p) {
        guide_check_icon_text($p, $item, $f, 'el ítem');
    });

    // places
    $pl = $g['places'] ?? null;
    if (!is_array($pl)) {
        guide_problem($p, 'places', 'Faltan los lugares recomendados');
    } else {
        $keys = array_map('strval', array_keys(is_array($building['places'] ?? null) ? $building['places'] : []));
        $sel = $pl['building'] ?? null;
        if (!guide_is_list($sel)) {
            guide_problem($p, 'places.building', 'Faltan los lugares del edificio que quieres incluir');
        } else {
            foreach ($sel as $k) {
                if (!is_string($k) || !in_array($k, $keys, true)) {
                    guide_problem($p, 'places.building', 'Ese lugar del edificio no existe');
                    break;
                }
            }
        }
        guide_check_list($p, $pl['own'] ?? null, 'places.own', 'tus lugares recomendados', 0, 10, function ($item, $f) use (&$p) {
            if (!is_array($item)) {
                guide_problem($p, $f, 'Un lugar recomendado está mal escrito');
                return;
            }
            guide_check_string($p, $item['name'] ?? null, "$f.name", 'el nombre del lugar', 1, 60);
            guide_check_t($p, $item['tag'] ?? null, "$f.tag", 'la etiqueta del lugar', null);
            guide_check_t($p, $item['distance'] ?? null, "$f.distance", 'la distancia del lugar', null);
            guide_check_t($p, $item['text'] ?? null, "$f.text", 'la descripción del lugar', 300);
            guide_check_string($p, $item['mapsQuery'] ?? null, "$f.mapsQuery", 'la búsqueda en el mapa del lugar', 1, 120);
        });
    }

    // photos
    guide_check_list($p, $g['photos'] ?? null, 'photos', 'las fotos', 3, 40, function ($item, $f) use (&$p) {
        if (!is_array($item)) {
            guide_problem($p, $f, 'Una foto está mal escrita');
            return;
        }
        guide_check_string($p, $item['file'] ?? null, "$f.file", 'el archivo de la foto', 1, 200);
        guide_check_t($p, $item['caption'] ?? null, "$f.caption", 'el pie de foto', 60);
    });

    // tourVideo, pdf (optional)
    foreach (['tourVideo' => 'el video del recorrido', 'pdf' => 'el PDF'] as $k => $label) {
        if (!array_key_exists($k, $g)) {
            continue;
        }
        $v = $g[$k];
        if (!is_array($v) || !is_string($v['url'] ?? null) || $v['url'] === '') {
            guide_problem($p, "$k.url", "Falta el enlace de $label");
        } elseif (!guide_url_ok($v['url'])) {
            guide_problem($p, "$k.url", "El enlace de $label debe empezar con https://");
        }
    }

    // hosts
    $h = $g['hosts'] ?? null;
    if (!is_array($h)) {
        guide_problem($p, 'hosts', 'Faltan los datos de los anfitriones');
    } else {
        guide_check_string($p, $h['names'] ?? null, 'hosts.names', 'los nombres de los anfitriones', 1, 60);
        guide_check_t($p, $h['about'] ?? null, 'hosts.about', 'la presentación de los anfitriones', 800);
        $wa = $h['whatsapp'] ?? null;
        if (!is_string($wa) || $wa === '') {
            guide_problem($p, 'hosts.whatsapp', 'Falta el WhatsApp del anfitrión');
        } elseif (preg_match('/^\+\d{8,15}$/D', $wa) !== 1) {
            guide_problem($p, 'hosts.whatsapp', 'El WhatsApp del anfitrión debe ir con el signo + y el indicativo, por ejemplo +573001234567');
        }
        guide_check_optional_string($p, $h, 'photo', 'hosts.photo', 'la foto de los anfitriones');
    }

    // theme (optional)
    if (array_key_exists('theme', $g)) {
        $t = $g['theme'];
        if (!is_array($t)) {
            guide_problem($p, 'theme', GUIDE_MSG_THEME);
        } else {
            if (array_key_exists('palette', $t) && (!is_string($t['palette']) || !in_array($t['palette'], GUIDE_PALETTES, true))) {
                guide_problem($p, 'theme.palette', GUIDE_MSG_THEME);
            }
            if (array_key_exists('motion', $t) && (!is_string($t['motion']) || !in_array($t['motion'], GUIDE_MOTIONS, true))) {
                guide_problem($p, 'theme.motion', GUIDE_MSG_THEME);
            }
        }
    }

    // content checks over every string
    $known = guide_known_numbers($g);
    foreach (guide_strings($g, '') as [$path, $str]) {
        if (strpos($str, '<') !== false || strpos($str, '>') !== false) {
            guide_problem($p, $path, GUIDE_MSG_HTML);
        }
        if (strpos($str, "\u{2014}") !== false || strpos($str, "\u{2013}") !== false) {
            guide_problem($p, $path, GUIDE_MSG_DASH);
        }
        if (guide_looks_like_door_code($path, $str, $known)) {
            guide_problem($p, $path, GUIDE_MSG_DOOR);
        }
    }

    return $p;
}

/** Add a problem unless the same field and message are already there. */
function guide_problem(array &$p, string $field, string $message): void
{
    foreach ($p as $x) {
        if ($x['field'] === $field && $x['message'] === $message) {
            return;
        }
    }
    $p[] = ['field' => $field, 'message' => $message];
}

function guide_is_list($v): bool
{
    return is_array($v) && ($v === [] || array_keys($v) === range(0, count($v) - 1));
}

function guide_check_string(array &$p, $v, string $field, string $label, int $min, int $max): void
{
    if (!is_string($v) || trim($v) === '') {
        guide_problem($p, $field, "Falta $label");
    } elseif (mb_strlen($v) > $max) {
        guide_problem($p, $field, ucfirst($label) . " es muy largo (máximo $max caracteres)");
    }
}

function guide_check_optional_string(array &$p, array $parent, string $key, string $field, string $label): void
{
    if (array_key_exists($key, $parent)) {
        guide_check_string($p, $parent[$key], $field, $label, 1, 200);
    }
}

/** A T value: both es and en present and non-empty; $max applies per language (characters). */
function guide_check_t(array &$p, $v, string $field, string $label, ?int $max): void
{
    if (!is_array($v)) {
        guide_problem($p, $field, "Falta $label");
        return;
    }
    foreach (['es' => 'en español', 'en' => 'en inglés'] as $lang => $name) {
        $s = $v[$lang] ?? null;
        if (!is_string($s) || trim($s) === '') {
            guide_problem($p, "$field.$lang", "Falta $label $name");
        } elseif ($max !== null && mb_strlen($s) > $max) {
            guide_problem($p, "$field.$lang", ucfirst($label) . " $name es muy largo (máximo $max caracteres)");
        }
    }
}

function guide_check_icon_text(array &$p, $item, string $field, string $label): void
{
    if (!is_array($item)) {
        guide_problem($p, $field, ucfirst($label) . ' está mal escrito');
        return;
    }
    $icon = $item['icon'] ?? null;
    if (!is_string($icon) || $icon === '') {
        guide_problem($p, "$field.icon", "Falta el ícono de $label");
    } elseif (mb_strlen($icon) > 4) {
        guide_problem($p, "$field.icon", "El ícono de $label es muy largo");
    }
    guide_check_t($p, $item['text'] ?? null, "$field.text", $label, null);
}

function guide_check_list(array &$p, $v, string $field, string $label, int $min, int $max, callable $each): void
{
    if (!guide_is_list($v)) {
        guide_problem($p, $field, "Faltan $label");
        return;
    }
    $n = count($v);
    if ($n < $min) {
        guide_problem($p, $field, "Faltan $label (mínimo $min)");
    } elseif ($n > $max) {
        guide_problem($p, $field, "Hay demasiados en $label (máximo $max)");
        return;
    }
    foreach ($v as $i => $item) {
        $each($item, "$field.$i");
    }
}

/**
 * At least as strict as safe_url() in view.php, so a link the validator accepts is never dropped
 * at render: https:// with a plain host, or a same-site path ("/x", never "//x"), and no
 * whitespace, control characters, backslashes, quotes or angle brackets.
 */
function guide_url_ok(string $u): bool
{
    if ($u === '' || preg_match('/[\s\x00-\x1F\x7F\\\\"<>]/', $u) === 1) {
        return false;
    }
    if (preg_match('#^https://[a-z0-9.-]+(:[0-9]+)?([/?\#].*)?$#iD', $u) === 1) {
        return true;
    }
    return $u[0] === '/' && (strlen($u) === 1 || $u[1] !== '/');
}

/** Flatten every string leaf into [dotted.path, value]. */
function guide_strings($v, string $path): array
{
    if (is_string($v)) {
        return [[$path, $v]];
    }
    if (!is_array($v)) {
        return [];
    }
    $out = [];
    foreach ($v as $k => $item) {
        $out = array_merge($out, guide_strings($item, $path === '' ? (string) $k : $path . '.' . $k));
    }
    return $out;
}

/** Lowercased unit, gateCode and the digit runs inside them: numbers that are not door codes. */
function guide_known_numbers(array $g): array
{
    $out = [];
    foreach (['unit', 'gateCode'] as $k) {
        $v = $g[$k] ?? null;
        if (is_string($v) && $v !== '') {
            $out[] = mb_strtolower($v);
            if (preg_match_all('/\d+/', $v, $m)) {
                foreach ($m[0] as $d) {
                    $out[] = $d;
                }
            }
        }
    }
    return $out;
}

/**
 * Paths the door-code scan skips: WiFi credentials, the WhatsApp number, photo file
 * names and links. Every other string in the guide is scanned for keyword plus number.
 */
function guide_door_code_exempt(string $path): bool
{
    if (in_array($path, ['wifi.password', 'wifi.ssid', 'hosts.whatsapp', 'lock.photo', 'hosts.photo', 'tourVideo.url', 'pdf.url'], true)) {
        return true;
    }
    return preg_match('/^(photos\.\d+\.file|ac\.photos\.\d+)$/D', $path) === 1;
}

function guide_looks_like_door_code(string $path, string $str, array $known = []): bool
{
    if (guide_door_code_exempt($path)) {
        return false;
    }
    $isCode = function (string $n) use ($known): bool {
        return strlen($n) >= 3 && strlen($n) <= 8 && !in_array(mb_strtolower($n), $known, true);
    };
    $kw = '(?<!\p{L})(?:c[oó]digo(?!\s+postal(?!\p{L}))|clave|code|pin|contrase[ñn]a|password|combinaci[oó]n|combination)(?!\p{L})';
    if (preg_match_all('/' . $kw . '\D{0,25}?(?<!\d)(\d+)(?!\d)/iu', $str, $m)) {
        foreach ($m[1] as $n) {
            if ($isCode($n)) {
                return true;
            }
        }
    }
    if (preg_match_all('/(?<!\d)(\d+)(?!\d)\D{0,25}?' . $kw . '/iu', $str, $m)) {
        foreach ($m[1] as $n) {
            if ($isCode($n)) {
                return true;
            }
        }
    }
    if (strpos($path, 'lock.steps') === 0 && preg_match_all('/(?<!\d)\d{4,8}(?!\d)/', $str, $m)) {
        foreach ($m[0] as $n) {
            if ($isCode($n)) {
                return true;
            }
        }
    }
    return false;
}
