<?php
declare(strict_types=1);

require_once __DIR__ . '/../_guide/lib/themes.php';

function temas_render(): string
{
    ob_start();
    try {
        require __DIR__ . '/../temas/index.php';
    } finally {
        $html = (string) ob_get_clean();
    }
    return $html;
}

test('temas: lists every palette and motion, is noindex and has no dashes', function () {
    $html = temas_render();
    foreach (GUIDE_PALETTES as $k) {
        ok(strpos($html, 'data-palette="' . $k . '"') !== false, "palette $k missing");
    }
    foreach (GUIDE_MOTIONS as $k) {
        ok(strpos($html, 'data-motion="' . $k . '"') !== false, "motion $k missing");
    }
    ok(strpos($html, '<meta name="robots" content="noindex">') !== false, 'noindex missing');
    ok(strpos($html, 'Escoge el diseño de tu guía') !== false, 'title missing');
    ok(strpos($html, "\u{2014}") === false && strpos($html, "\u{2013}") === false, 'em or en dash found');
});

test('temas: shows no guide data and the folder reserves the slug', function () {
    $html = temas_render();
    ok(stripos($html, 'gateCode') === false && stripos($html, 'wifi') === false && stripos($html, '613') === false);
    require_once __DIR__ . '/../_guide/lib/slug.php';
    ok(slug_reserved('temas'));
});
