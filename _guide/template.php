<?php
// Guide page, rebuilt from apartasuite-613t2/index.html section by section.
// Apartment strings come from the guide ($g), building strings from the building
// file ($b). Sections with no data are left out whole. Loaded by render_guide().
if (!defined('GUIDE_VIEW')) {
    http_response_code(404);
    exit;
}
/** @var array $g @var array $b @var string $mode @var array $theme */

$slug = sval($g['slug'] ?? null);
$unit = sval($g['unit'] ?? null);
$name = tval($g['name'] ?? null) ?? ['es' => $unit, 'en' => $unit];
$city = tval($b['city'] ?? null) ?? ['es' => '', 'en' => ''];

// Photos of the apartment (gallery), served through the gate.
$photos = [];
foreach (lval($g['photos'] ?? null) as $p) {
    if (is_array($p) && sval($p['file'] ?? null) !== '') {
        $photos[] = ['src' => photo_url($slug, $p['file']), 'caption' => tval($p['caption'] ?? null)];
    }
}

$lockSteps = array_values(array_filter(array_map('tval', lval($g['lock']['steps'] ?? null))));
$lockPhoto = sval($g['lock']['photo'] ?? null);
$ac = is_array($g['ac'] ?? null) ? $g['ac'] : null;
$wifi = is_array($g['wifi'] ?? null) && sval($g['wifi']['ssid'] ?? null) !== '' ? $g['wifi'] : null;
$tour = video_embed($g['tourVideo']['url'] ?? null);
$pdf = safe_url($g['pdf']['url'] ?? null);

$features = array_values(array_filter(lval($g['features'] ?? null), fn ($f) => is_array($f) && tval($f['text'] ?? null) !== null));
$tapWater = tval($g['tapWater'] ?? null);
$coffee = tval($g['coffee'] ?? null);
$waste = tval($g['waste'] ?? null);

$iconList = function ($list): array {
    return array_values(array_filter(lval($list), fn ($r) => is_array($r) && tval($r['text'] ?? null) !== null));
};
$rules = array_merge($iconList($b['rules'] ?? null), $iconList($g['rules'] ?? null));
$beforeLeaving = $iconList($g['beforeLeaving'] ?? null);
if ($beforeLeaving === []) {
    $beforeLeaving = $iconList($b['beforeLeaving'] ?? null);
}

// Places: the building cards the guide picked (nearby with a photo, nearby without
// one, day trips), plus the owner's own recommendations.
$bPlaces = is_array($b['places'] ?? null) ? $b['places'] : [];
$nearbyPhoto = $nearbyText = $dayTrips = [];
foreach (lval($g['places']['building'] ?? null) as $key) {
    $pl = is_string($key) ? ($bPlaces[$key] ?? null) : null;
    if (!is_array($pl) || tval($pl['name'] ?? null) === null) {
        continue;
    }
    if (($pl['group'] ?? '') === 'daytrip') {
        $dayTrips[] = $pl;
    } elseif (safe_url($pl['photo'] ?? null) !== null) {
        $nearbyPhoto[] = $pl;
    } else {
        $nearbyText[] = $pl;
    }
}
$ownPlaces = array_values(array_filter(lval($g['places']['own'] ?? null), fn ($p) => is_array($p) && sval($p['name'] ?? null) !== ''));
$hasPlaces = $nearbyPhoto || $nearbyText || $dayTrips || $ownPlaces;

$hosts = is_array($g['hosts'] ?? null) ? $g['hosts'] : [];
$hostNames = sval($hosts['names'] ?? null);
$hostAbout = tval($hosts['about'] ?? null);
$hostPhoto = sval($hosts['photo'] ?? null);
$hostWa = preg_match('/^\+\d{8,15}$/D', sval($hosts['whatsapp'] ?? null)) === 1 ? $hosts['whatsapp'] : '';

$linkLabels = [
    'map' => ['pl-map', '📍 Cómo llegar', '📍 Directions'],
    'menu' => ['pl-menu', '📋 Menú', '📋 Menu'],
    'ig' => ['pl-ig', '📸 Instagram', '📸 Instagram'],
    'wa' => ['pl-wa', '💬 WhatsApp', '💬 WhatsApp'],
];
$placeLinks = function (array $links) use ($linkLabels): string {
    $out = '';
    foreach ($links as $l) {
        $type = is_array($l) ? ($l['type'] ?? '') : '';
        $url = is_array($l) ? safe_url($l['url'] ?? null) : null;
        if ($url === null || !isset($linkLabels[$type])) {
            continue;
        }
        [$cls, $es, $en] = $linkLabels[$type];
        $out .= '<a class="' . $cls . '" href="' . h($url) . '" target="_blank" rel="noopener">' . tx($es, $en) . '</a>';
    }
    return $out === '' ? '' : '<div class="place-links">' . $out . '</div>';
};
$photoCard = function (array $pl) use ($placeLinks): string {
    $nm = tval($pl['name']);
    $tag = tval($pl['tag'] ?? null);
    $dist = tval($pl['distance'] ?? null);
    $text = tval($pl['text'] ?? null);
    $cls = ($pl['tagType'] ?? '') === 'food' ? 'tag-food' : 'tag-fun';
    $o = '<div class="place-photo">';
    $o .= '<img class="place-photo-img" src="' . h(safe_url($pl['photo'])) . '" alt="' . h($nm['es']) . '" loading="lazy">';
    $o .= '<div class="place-photo-body">';
    $o .= $tag ? '<span class="place-tag ' . $cls . '">' . t($tag) . '</span>' : '';
    $o .= '<h3>' . t($nm) . '</h3>';
    $o .= $dist ? '<div class="place-meta">' . t($dist) . '</div>' : '';
    $o .= $text ? '<p>' . t($text) . '</p>' : '';
    $o .= $placeLinks(lval($pl['links'] ?? null));
    return $o . '</div></div>';
};
$textCard = function (array $nm, ?array $tag, ?array $dist, ?array $text, string $cls, string $links): string {
    $o = '<div class="place">';
    $o .= $tag ? '<span class="place-tag ' . $cls . '">' . t($tag) . '</span>' : '';
    $o .= '<h3>' . t($nm) . '</h3>';
    $o .= $dist ? '<div class="place-meta">' . t($dist) . '</div>' : '';
    $o .= $text ? '<p>' . t($text) . '</p>' : '';
    return $o . $links . '</div>';
};
$thumb = function (string $src, ?array $label, string $alt): string {
    $o = '<span class="thumb-wrap"><img class="thumb" src="' . h($src) . '" alt="' . h($alt) . '" data-lb="' . h($src) . '" role="button" tabindex="0" loading="lazy">';
    return $o . ($label ? '<span class="thumb-label">' . t($label) . '</span>' : '') . '</span>';
};
$galItem = function (string $src, ?array $cap): string {
    $alt = $cap['es'] ?? '';
    $o = '<div class="gal-item" data-lb="' . h($src) . '" role="button" tabindex="0">';
    $o .= '<img src="' . h($src) . '" alt="' . h($alt) . '" data-alt-es="' . h($alt) . '" data-alt-en="' . h($cap['en'] ?? '') . '" loading="lazy">';
    return $o . ($cap ? '<div class="gal-item-label">' . t($cap) . '</div>' : '') . '</div>';
};
$ruleCard = function (array $r): string {
    return '<div class="rule-card"><span class="rc-icon">' . h(sval($r['icon'] ?? null)) . '</span>' . t(tval($r['text'])) . '</div>';
};

$checkin = sval($g['checkin'] ?? null);
$checkout = sval($g['checkout'] ?? null);
$airport = (int) ($b['airportMinutes'] ?? 0);
$arrival = trim(($airport > 0 ? "Desde el aeropuerto estás a solo $airport minutos en carro. " : '') . (tval($b['arrivalNote'] ?? null)['es'] ?? ''));
$arrivalEn = trim(($airport > 0 ? "The airport is just a $airport-minute drive away. " : '') . (tval($b['arrivalNote'] ?? null)['en'] ?? ''));
$reception = is_array($b['reception'] ?? null) ? $b['reception'] : [];
$energy = is_array($b['energy'] ?? null) ? $b['energy'] : [];
$step = 0;
?><!DOCTYPE html>
<html lang="es" data-palette="<?= h($theme['palette']) ?>" data-motion="<?= h($theme['motion']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($name['es']) ?> · Welcome Guide</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(guide_asset('guide.css')) ?>">
</head>
<body>

<?php if ($mode === 'preview'): ?>
<div class="preview-banner"><?= tx('Vista previa del borrador: así se verá la guía cuando la publiques.', 'Draft preview: this is how the guide will look once published.') ?></div>
<?php endif; ?>

<div class="topbar">
  <div class="brand">
    <svg viewBox="0 0 40 40" fill="none" aria-hidden="true"><path class="brand-roof" d="M6 20 L20 8 L34 20" stroke-width="3" stroke-linecap="round"/><rect class="brand-house" x="11" y="20" width="18" height="13" rx="2"/><rect class="brand-door" x="17" y="25" width="6" height="8" rx="1"/></svg>
    <div class="brand-name"><?= t($name) ?></div>
  </div>
  <div class="lang-toggle">
    <button type="button" id="btn-es" class="active" data-lang="es">ES</button>
    <button type="button" id="btn-en" data-lang="en">EN</button>
  </div>
</div>

<!-- PAGE-WIDE CREATURES (fixed, behind all content) -->
<div class="bf-page" id="bf-page" aria-hidden="true"></div>

<!-- LIGHTBOX -->
<div class="lb" id="lightbox">
  <button type="button" class="lb-close" aria-label="Cerrar">✕</button>
  <img id="lb-img" src="data:," alt="">
  <div class="lb-stack" id="lb-stack"></div>
  <video id="lb-video" controls playsinline></video>
</div>

<!-- HERO -->
<header class="hero">
  <div class="butterflies" id="butterflies" aria-hidden="true"></div>
  <?php if (tval($b['area'] ?? null)): ?><div class="hero-eyebrow"><?= t(tval($b['area'])) ?></div><?php endif; ?>
  <h1><?= tx('Bienvenido a', 'Welcome to') ?> <?= title_html($name, $unit) ?><span id="hero-name"></span></h1>
  <?php if (tval($g['tagline'] ?? null)): ?><p class="hero-sub"><?= t(tval($g['tagline'])) ?></p><?php endif; ?>
  <?php $badges = array_values(array_filter(array_map('tval', lval($g['highlights'] ?? null)))); ?>
  <?php if ($badges): ?>
  <div class="hero-badges">
    <?php foreach ($badges as $hl): ?><span class="badge"><?= t($hl) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <div class="scroll-hint"><?= tx('Desliza para explorar', 'Scroll to explore') ?></div>
</header>

<!-- WELCOME -->
<section id="welcome">
  <span class="sec-eyebrow"><?= tx('Bienvenida', 'Welcome') ?></span>
  <h2 class="sec-title"><?= tx('Tu refugio en', 'Your retreat in') ?><?php if ($city['es'] !== ''): ?> <em><?= t($city) ?></em><?php endif; ?></h2>
  <?php $welcome = tval($g['welcome'] ?? null); ?>
  <?php if ($welcome): foreach (tparas($welcome) as $i => $para): ?>
  <p class="<?= $i === 0 ? 'sec-lead' : 'welcome-more' ?> pre"><?= t($para) ?></p>
  <?php endforeach; endif; ?>
</section>

<!-- CHECK-IN -->
<section id="checkin">
  <span class="sec-eyebrow"><?= tx('Tu llegada', 'Your arrival') ?></span>
  <h2 class="sec-title"><?= tx('Check-in y', 'Check-in &') ?> <em>check-out</em></h2>
  <?php if ($arrival !== ''): ?><p class="sec-lead"><?= tx($arrival, $arrivalEn) ?></p><?php endif; ?>

  <div class="count-wrap">
    <div class="count-card gold">
      <h3>Check-in</h3>
      <div class="count-time"><?= h(fmt_time($checkin)) ?></div>
      <p class="count-note"><?= tx('Disponible desde las ' . fmt_time($checkin), 'Available from ' . fmt_time($checkin)) ?></p>
    </div>
    <div class="count-card">
      <h3>Check-out</h3>
      <div class="count-time"><?= h(fmt_time($checkout)) ?></div>
      <?php if (tval($b['checkoutNote'] ?? null)): ?><p class="count-note"><?= t(tval($b['checkoutNote'])) ?></p><?php endif; ?>
    </div>
    <div class="count-card">
      <h3><?= tx('Hora local', 'Local time') ?></h3>
      <div class="count-time" id="local-clock" data-tz="<?= h(sval($b['timezone'] ?? null) ?: 'America/Bogota') ?>">--:--</div>
      <?php if (sval($b['timezoneLabel'] ?? null) !== ''): ?><p class="count-note"><?= h($b['timezoneLabel']) ?></p><?php endif; ?>
    </div>
  </div>

  <div class="steps">
    <?php foreach ($lockSteps as $i => $ls): $step++; ?>
    <div class="step"><div class="step-num"><?= $step ?></div><div>
      <p class="step-text"><?= t($ls) ?></p>
      <?php if ($i === 0 && sval($b['address'] ?? null) !== ''): ?><p class="step-sub"><?= h($b['address']) ?></p><?php endif; ?>
      <?php if ($i === count($lockSteps) - 1 && $lockPhoto !== ''): ?>
      <p class="thumb-row"><?= $thumb(photo_url($slug, $lockPhoto), ['es' => 'Toca la imagen para ver la cerradura.', 'en' => 'Tap the image to see the lock.'], 'Cerradura') ?></p>
      <?php endif; ?>
    </div></div>
    <?php endforeach; ?>

    <?php if ($ac !== null): $step++; $acSteps = array_values(array_filter(array_map('tval', lval($ac['steps'] ?? null)))); ?>
    <div class="step"><div class="step-num"><?= $step ?></div><div>
      <strong><?= t(tval($energy['title'] ?? null) ?? ['es' => 'Aire acondicionado', 'en' => 'Air conditioning']) ?></strong>
      <?php if ($acSteps): ?>
      <ol class="ac-steps">
        <?php foreach ($acSteps as $as): ?><li><?= t($as) ?></li>
        <?php endforeach; ?>
      </ol>
      <?php endif; ?>
      <?php if (tval($ac['note'] ?? null)): ?><p class="ac-note">⚠️ <?= t(tval($ac['note'])) ?></p><?php endif; ?>
      <p class="thumb-row">
        <?php foreach (lval($energy['images'] ?? null) as $img): $src = safe_url($img['src'] ?? null); if ($src === null) { continue; } ?>
        <?= $thumb($src, tval($img['label'] ?? null), tval($img['label'] ?? null)['es'] ?? '') ?>
        <?php endforeach; ?>
        <?php foreach (lval($ac['photos'] ?? null) as $f): if (sval($f) === '') { continue; } ?>
        <?= $thumb(photo_url($slug, $f), null, 'Aire acondicionado') ?>
        <?php endforeach; ?>
        <?php $qg = $energy['quickGuide'] ?? null; $qgSrc = is_array($qg) ? safe_url($qg['src'] ?? null) : null; ?>
        <?php if ($qgSrc !== null): ?><a class="quick-btn" data-lb="<?= h($qgSrc) ?>" role="button" tabindex="0"><?= t(tval($qg['label'] ?? null) ?? ['es' => 'Guía rápida', 'en' => 'Quick guide']) ?></a><?php endif; ?>
      </p>
    </div></div>
    <?php endif; ?>

    <?php if ($wifi !== null): $step++; ?>
    <div class="step"><div class="step-num"><?= $step ?></div><div>
      <strong>WiFi</strong>
      <p><?= tx('Conéctate escaneando el código QR o escribiendo la red y clave manualmente.', 'Connect by scanning the QR code or entering the network and password manually.') ?></p>
      <div class="wifi-box">
        <div id="wifi-qr" class="wifi-qr" data-ssid="<?= h(sval($wifi['ssid'])) ?>" data-pass="<?= h(sval($wifi['password'] ?? null)) ?>"></div>
        <div><small><?= tx('Red WiFi', 'WiFi network') ?></small><b><?= h(sval($wifi['ssid'])) ?></b></div>
        <?php if (sval($wifi['password'] ?? null) !== ''): ?><div><small><?= tx('Clave', 'Password') ?></small><b><?= h($wifi['password']) ?></b></div><?php endif; ?>
      </div>
    </div></div>
    <?php endif; ?>
  </div>

  <?php $wx = is_array($b['weather'] ?? null) ? $b['weather'] : null; ?>
  <?php if ($wx !== null && is_numeric($wx['lat'] ?? null) && is_numeric($wx['lon'] ?? null)): ?>
  <div class="weather" id="weather" data-lat="<?= h((string) $wx['lat']) ?>" data-lon="<?= h((string) $wx['lon']) ?>" data-city="<?= h($city['es']) ?>" data-tz="<?= h(sval($b['timezone'] ?? null) ?: 'America/Bogota') ?>">
    <div class="weather-loading"><?= tx('Cargando el clima de ' . $city['es'] . '...', 'Loading ' . $city['en'] . ' weather...') ?></div>
  </div>
  <?php endif; ?>
</section>

<?php if ($tour !== null): ?>
<!-- VIDEO TOUR -->
<section id="tour">
  <span class="sec-eyebrow"><?= tx('Recorrido en video', 'Video tour') ?></span>
  <h2 class="sec-title"><?= tx('Camina por tu', 'Walk through your') ?> <em><?= tx('suite', 'suite') ?></em></h2>
  <p class="sec-lead"><?= tx('Un vistazo completo por tu suite antes de llegar.', 'A full walkthrough of your suite before you arrive.') ?></p>
  <?php if ($tour[0] === 'video'): ?>
  <div class="video-wrap">
    <video controls playsinline preload="metadata">
      <source src="<?= h($tour[1]) ?>" type="video/mp4">
    </video>
  </div>
  <?php elseif ($tour[0] === 'iframe'): ?>
  <div class="video-wrap video-embed">
    <iframe src="<?= h($tour[1]) ?>" title="Video" loading="lazy" allow="autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin"></iframe>
  </div>
  <?php else: ?>
  <div class="gal-drive"><a href="<?= h($tour[1]) ?>" target="_blank" rel="noopener"><?= tx('▶ Ver el recorrido en video', '▶ Watch the video tour') ?></a></div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- GALLERY -->
<section id="gallery">
  <span class="sec-eyebrow"><?= tx('Galería', 'Gallery') ?></span>
  <h2 class="sec-title"><?= tx('Imágenes de tu', 'Glimpses of your') ?> <em><?= tx('estadía', 'stay') ?></em></h2>
  <div class="gal-grid">
    <?php foreach ($photos as $p): ?><?= $galItem($p['src'], $p['caption']) ?>
    <?php endforeach; ?>
  </div>
</section>

<!-- AMENITIES (building) -->
<section id="amenities-building">
  <span class="sec-eyebrow"><?= tx('Amenidades', 'Amenities') ?></span>
  <h2 class="sec-title"><?= tx('Amenidades del', 'Building') ?> <em><?= tx('edificio', 'amenities') ?></em></h2>
  <?php $chips = array_values(array_filter(array_map('tval', lval($b['amenities'] ?? null)))); ?>
  <?php if ($chips): ?>
  <div class="amen-list">
    <?php foreach ($chips as $c): ?><span class="amen-chip"><?= t($c) ?></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if (tval($b['wristband'] ?? null)): ?>
  <div class="amen-fee">
    <span class="amen-fee-icon">🎟️</span>
    <p><?= t(tval($b['wristband'])) ?></p>
  </div>
  <?php endif; ?>
  <?php $hours = array_values(array_filter(lval($b['hours'] ?? null), fn ($x) => is_array($x) && tval($x['label'] ?? null) && tval($x['time'] ?? null))); ?>
  <?php $poolRules = array_values(array_filter(array_map('tval', lval($b['poolRules'] ?? null)))); ?>
  <?php if ($hours || $poolRules): ?>
  <div class="amen-fee">
    <span class="amen-fee-icon">🕐</span>
    <div>
      <?php if ($hours): ?>
      <p class="fee-title"><?= tx('Horarios', 'Hours') ?></p>
      <p class="fee-lines">
        <?php foreach ($hours as $i => $hr): ?><?= $i > 0 ? '<br>' : '' ?><?= t(tval($hr['label'])) ?> <strong><?= t(tval($hr['time'])) ?></strong>
        <?php endforeach; ?>
      </p>
      <?php endif; ?>
      <?php foreach ($poolRules as $i => $pr): ?><p class="pool-rule<?= $i === 0 ? ' first' : '' ?>"><?= t($pr) ?></p>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>
  <?php $amenVideo = safe_url($b['amenitiesVideo'] ?? null); ?>
  <?php if ($amenVideo !== null): ?>
  <div class="amen-video">
    <p class="amen-video-title"><?= tx('Recorrido por las amenidades', 'Amenities walkthrough') ?></p>
    <div class="video-wrap">
      <video controls playsinline preload="metadata">
        <source src="<?= h($amenVideo) ?>" type="video/mp4">
      </video>
    </div>
  </div>
  <?php endif; ?>
  <?php $amenPhotos = array_values(array_filter(lval($b['amenityPhotos'] ?? null), fn ($x) => is_array($x) && safe_url($x['src'] ?? null) !== null)); ?>
  <?php if ($amenPhotos): ?>
  <div class="gal-grid gal-grid-amen">
    <?php foreach ($amenPhotos as $ap): ?><?= $galItem(safe_url($ap['src']), tval($ap['caption'] ?? null)) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<!-- MAP -->
<?php $map = is_array($b['map'] ?? null) ? $b['map'] : []; $embed = safe_url($map['embed'] ?? null); $beachVideo = safe_url($map['beachVideo'] ?? null); ?>
<section id="map">
  <span class="sec-eyebrow"><?= tx('Tu mapa del barrio', 'Your neighborhood map') ?></span>
  <h2 class="sec-title"><?= tx('Todo está', 'Everything is') ?> <em><?= tx('cerca', 'close by') ?></em></h2>
  <div class="map-card">
    <?php if ($embed !== null): ?><iframe class="map-embed" src="<?= h($embed) ?>" title="Mapa" allowfullscreen loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe><?php endif; ?>
    <div class="map-links">
      <?php if ($beachVideo !== null): ?><a class="map-link" href="#map" data-lb="<?= h($beachVideo) ?>">🎥 <?= tx('Cómo llegar caminando a la playa', 'How to walk to the beach') ?></a><?php endif; ?>
      <?php foreach (lval($map['links'] ?? null) as $ml): $u = is_array($ml) ? safe_url($ml['url'] ?? null) : null; if ($u === null || !tval($ml['label'] ?? null)) { continue; } ?>
      <a class="map-link" href="<?= h($u) ?>" target="_blank" rel="noopener"><?= h(sval($ml['icon'] ?? null)) ?> <?= t(tval($ml['label'])) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($hasPlaces): ?>
<!-- PLACES -->
<section id="places">
  <span class="sec-eyebrow"><?= tx('Recomendaciones de tus anfitriones', "Your hosts' picks") ?></span>
  <h2 class="sec-title"><?= tx('Cosas por', 'Things to') ?> <em><?= tx('hacer y ver', 'do and see') ?></em></h2>
  <?php if ($nearbyPhoto): ?>
  <div class="cards-photo">
    <?php foreach ($nearbyPhoto as $pl): ?><?= $photoCard($pl) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($nearbyText || $ownPlaces): ?>
  <div class="cards cards-text">
    <?php foreach ($nearbyText as $pl): ?><?= $textCard(tval($pl['name']), tval($pl['tag'] ?? null), tval($pl['distance'] ?? null), tval($pl['text'] ?? null), ($pl['tagType'] ?? '') === 'food' ? 'tag-food' : 'tag-fun', $placeLinks(lval($pl['links'] ?? null))) ?>
    <?php endforeach; ?>
    <?php foreach ($ownPlaces as $op): $q = sval($op['mapsQuery'] ?? null); ?><?= $textCard(['es' => sval($op['name']), 'en' => sval($op['name'])], tval($op['tag'] ?? null), tval($op['distance'] ?? null), tval($op['text'] ?? null), 'tag-food', $q !== '' ? $placeLinks([['type' => 'map', 'url' => maps_search_url($q)]]) : '') ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($dayTrips): ?>
  <h3 class="places-subtitle"><?= tx('Excursiones de un día', 'Full day trips') ?></h3>
  <div class="cards-photo">
    <?php foreach ($dayTrips as $pl): ?><?= safe_url($pl['photo'] ?? null) !== null ? $photoCard($pl) : $textCard(tval($pl['name']), tval($pl['tag'] ?? null), tval($pl['distance'] ?? null), tval($pl['text'] ?? null), 'tag-fun', $placeLinks(lval($pl['links'] ?? null))) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($features || $tapWater || $coffee): ?>
<!-- SUITE DETAILS -->
<section id="suite-details">
  <span class="sec-eyebrow"><?= tx('Tu suite', 'Your suite') ?></span>
  <h2 class="sec-title"><?= tx('Todo lo que', 'Everything you') ?> <em><?= tx('necesitas', 'need') ?></em></h2>
  <?php if ($features): ?>
  <div class="amen">
    <?php foreach ($features as $f): ?><div><span><?= h(sval($f['icon'] ?? null)) ?></span><?= t(tval($f['text'])) ?></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
  <?php if ($tapWater): ?>
  <div class="amen-fee amen-fee-tight">
    <span class="amen-fee-icon">🚰</span>
    <p><?= t($tapWater) ?></p>
  </div>
  <?php endif; ?>
  <?php if ($coffee): ?>
  <div class="amen-fee amen-fee-tight">
    <span class="amen-fee-icon">☕</span>
    <div>
      <p class="fee-title"><?= tx('Un buen café también hace parte de las vacaciones', 'A good coffee is part of the vacation too') ?></p>
      <p class="fee-body pre"><?= t($coffee) ?></p>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<!-- HOUSE RULES -->
<section id="house-rules">
  <span class="sec-eyebrow"><?= tx('Para una estadía perfecta', 'For a perfect stay') ?></span>
  <h2 class="sec-title"><?= tx('Reglas de la', 'House') ?> <em><?= tx('casa', 'rules') ?></em></h2>
  <?php if ($rules): ?>
  <div class="rules-grid">
    <?php foreach ($rules as $r): ?><?= $ruleCard($r) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($waste): ?>
  <div class="amen-fee amen-fee-tight">
    <span class="amen-fee-icon">♻️</span>
    <div>
      <p class="fee-title"><?= tx('Manejo de residuos', 'Waste management') ?></p>
      <p class="fee-body pre"><?= t($waste) ?></p>
    </div>
  </div>
  <?php endif; ?>

  <?php $escnna = is_array($b['escnna'] ?? null) ? $b['escnna'] : null; $escImg = $escnna ? safe_url($escnna['image'] ?? null) : null; ?>
  <?php if ($escnna && $escImg !== null && tval($escnna['title'] ?? null)): ?>
  <div class="escnna-card" data-lb="<?= h($escImg) ?>" role="button" tabindex="0">
    <span class="rc-icon">⚠️</span>
    <div>
      <b><?= t(tval($escnna['title'])) ?></b>
      <?php if (tval($escnna['text'] ?? null)): ?><small><?= t(tval($escnna['text'])) ?></small><?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($beforeLeaving): ?>
  <h3 class="rules-sub"><?= tx('Antes de salir', 'Before leaving') ?></h3>
  <div class="rules-grid">
    <?php foreach ($beforeLeaving as $r): ?><?= $ruleCard($r) ?>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (tval($b['rulesLegal'] ?? null)): ?>
  <div class="amen-fee">
    <span class="amen-fee-icon">📋</span>
    <p><?= t(tval($b['rulesLegal'])) ?></p>
  </div>
  <?php endif; ?>

  <?php if ($pdf !== null): ?>
  <div class="center-row">
    <a class="btn-brand" href="<?= h($pdf) ?>" target="_blank" rel="noopener"><?= tx('📄 Ver guía oficial del apartamento (PDF)', '📄 View official apartment guide (PDF)') ?></a>
  </div>
  <?php endif; ?>
</section>

<!-- HOSTS -->
<section id="hosts-sec">
  <div class="hosts<?= $hostPhoto === '' ? ' hosts-nophoto' : '' ?>">
    <?php if ($hostPhoto !== ''): ?><div class="host-photo"><img src="<?= h(photo_url($slug, $hostPhoto)) ?>" alt="<?= h($hostNames) ?>"></div><?php endif; ?>
    <div>
      <h2><?= tx('Sobre nosotros', 'About us') ?></h2>
      <?php if ($hostAbout): ?><p class="pre"><?= t($hostAbout) ?></p><?php endif; ?>
      <?php if ($hostNames !== ''): ?><div class="host-sign"><?= h($hostNames) ?></div><?php endif; ?>
      <?php if ($hostWa !== ''): ?><a class="host-wa" href="<?= h(wa_url($hostWa)) ?>" target="_blank" rel="noopener">💬 <?= tx('Escríbenos por WhatsApp', 'Message us on WhatsApp') ?></a><?php endif; ?>
    </div>
  </div>
</section>

<!-- SOS -->
<section id="sos">
  <span class="sec-eyebrow"><?= tx('Siempre a un toque', 'One tap away') ?></span>
  <h2 class="sec-title"><?= tx('Contactos de', 'Emergency') ?> <em><?= tx('emergencia', 'contacts') ?></em></h2>
  <div class="sos">
    <?php if (preg_match('/^\+\d{8,15}$/D', sval($reception['whatsapp'] ?? null)) === 1): ?>
    <?php $rh = tval($reception['hours'] ?? null) ?? ['es' => '', 'en' => '']; $ic = sval($reception['intercom'] ?? null); ?>
    <a href="<?= h(wa_url($reception['whatsapp'])) ?>"><div class="sos-icon sos-deep">🛎️</div><div><strong><?= tx('Recepción (WhatsApp)', 'Front Desk (WhatsApp)') ?></strong><span class="num"><?= h(sval($reception['phoneDisplay'] ?? null) ?: $reception['whatsapp']) ?></span><br><small><?= tx(trim($rh['es'] . ($ic !== '' ? ' · Citófono ' . $ic : ''), ' ·'), trim($rh['en'] . ($ic !== '' ? ' · Intercom ' . $ic : ''), ' ·')) ?></small></div></a>
    <?php endif; ?>
    <?php foreach (lval($b['sos'] ?? null) as $s): $href = is_array($s) ? sval($s['href'] ?? null) : ''; if (preg_match('/^(tel:\+?[0-9]{3,15}|https:\/\/wa\.me\/[0-9]{8,15})$/D', $href) !== 1 || !tval($s['title'] ?? null)) { continue; } ?>
    <?php $color = in_array($s['color'] ?? '', ['danger', 'deep', 'brand'], true) ? $s['color'] : 'deep'; ?>
    <a href="<?= h($href) ?>"><div class="sos-icon sos-<?= h($color) ?>"><?= h(sval($s['icon'] ?? null)) ?></div><div><strong><?= t(tval($s['title'])) ?></strong><span class="num"><?= h(sval($s['number'] ?? null)) ?></span><?php if (tval($s['note'] ?? null)): ?><br><small><?= t(tval($s['note'])) ?></small><?php endif; ?></div></a>
    <?php endforeach; ?>
  </div>
</section>

<footer>
  <div class="display"><?= tx('Que disfrutes tu estadía', 'Enjoy your stay') ?></div>
  <?php if (tval($b['footerAddress'] ?? null)): ?><p><?= t(tsub(tval($b['footerAddress']), ['unit' => $unit])) ?></p><?php endif; ?>
  <div class="foot-links">
    <a href="#house-rules"><?= tx('Reglas de la casa', 'House Rules') ?></a>
  </div>
  <p class="foot-review"><?= t($name) ?> · <?= tx('Si disfrutaste tu estancia, una reseña 5 estrellas nos ayuda muchísimo ⭐', 'If you enjoyed your stay, a 5-star review helps us so much ⭐') ?></p>
</footer>

<?php if ($wifi !== null): ?><script src="<?= h(guide_asset('qrcode.min.js')) ?>"></script>
<?php endif; ?>
<script src="<?= h(guide_asset('guide.js')) ?>"></script>
</body>
</html>
