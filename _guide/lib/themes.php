<?php
declare(strict_types=1);

// Theme keys, the one place they live. The validator checks guide.theme against these
// lists and the template writes them as data-palette / data-motion on <html>.
// Colors per palette are in _guide/assets/guide.css; creatures per motion in guide.js.
// The first key of each list is the default (613T2's look).

const GUIDE_PALETTES = [
    'caribe',     // 613T2: teal and gold
    'macondo',    // 77Rentals purple and gold
    'arena',      // sand and terracotta
    'selva',      // Sierra Nevada greens and gold
    'atardecer',  // sunset coral and orange on deep plum
    'coral',      // coral pink and sea blue
    'noche',      // navy and gold
    'cafetal',    // coffee brown and cream
    'buganvilia', // bougainvillea magenta and leaf green
    'bahia',      // bay blue and white
    'tayrona',    // jungle green and sand
    'blanco',     // minimal: white, charcoal, one teal accent
];

const GUIDE_MOTIONS = [
    'marino',      // 613T2's manta rays and fish
    'tortugas',    // sea turtles
    'burbujas',    // rising bubbles
    'mariposas',   // butterflies (the Macondo motif)
    'colibries',   // hummingbirds
    'aves',        // bird silhouettes
    'palmas',      // drifting palm leaves
    'flores',      // falling bougainvillea petals
    'luciernagas', // glowing fireflies
    'ninguno',     // no creatures
];

/** The guide's palette and motion, falling back to the defaults for missing or unknown keys. */
function guide_theme(array $g): array
{
    $t = is_array($g['theme'] ?? null) ? $g['theme'] : [];
    $palette = $t['palette'] ?? null;
    $motion = $t['motion'] ?? null;
    return [
        'palette' => is_string($palette) && in_array($palette, GUIDE_PALETTES, true) ? $palette : GUIDE_PALETTES[0],
        'motion' => is_string($motion) && in_array($motion, GUIDE_MOTIONS, true) ? $motion : GUIDE_MOTIONS[0],
    ];
}
