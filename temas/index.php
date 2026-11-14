<?php
declare(strict_types=1);

// Public page where owners pick a palette and a motion for their guide. It shows no
// guide data and needs no gate. Colors come from guide.css (one [data-palette] block per
// palette, applied here to each card) and the motions from guide.js (window.GuideMotion).

require_once __DIR__ . '/../_guide/lib/view.php';

$palettes = [
    'caribe' => ['Caribe', 'turquesa y dorado, el de la guía modelo'],
    'macondo' => ['Macondo', 'morado y dorado de 77Rentals'],
    'arena' => ['Arena', 'arena y terracota'],
    'selva' => ['Selva', 'verdes de la Sierra Nevada y dorado'],
    'atardecer' => ['Atardecer', 'coral y naranja sobre ciruela'],
    'coral' => ['Coral', 'rosa coral y azul mar'],
    'noche' => ['Noche', 'azul noche y dorado'],
    'cafetal' => ['Cafetal', 'café y crema'],
    'buganvilia' => ['Buganvilia', 'fucsia de buganvilia y verde hoja'],
    'bahia' => ['Bahía', 'azul bahía y blanco'],
    'tayrona' => ['Tayrona', 'verde selva y arena'],
    'blanco' => ['Blanco', 'minimalista: blanco, gris oscuro y un acento turquesa'],
];
$motions = [
    'marino' => ['Marino', 'mantarrayas y peces, el de la guía modelo'],
    'tortugas' => ['Tortugas', 'tortugas marinas'],
    'burbujas' => ['Burbujas', 'burbujas'],
    'mariposas' => ['Mariposas', 'mariposas amarillas'],
    'colibries' => ['Colibríes', 'colibríes'],
    'aves' => ['Aves', 'aves en vuelo'],
    'palmas' => ['Palmas', 'hojas de palma'],
    'flores' => ['Flores', 'pétalos de buganvilia'],
    'luciernagas' => ['Luciérnagas', 'luciérnagas'],
    'ninguno' => ['Ninguno', 'sin animación'],
];
?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Escoge el diseño de tu guía</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(guide_asset('guide.css')) ?>">
<style>
.tm{max-width:760px;margin:0 auto;padding:28px 16px 56px;}
.tm h1{font-size:2.1rem;line-height:1.15;color:var(--brand-dark);margin-bottom:10px;}
.tm-lead{color:var(--muted);margin-bottom:28px;}
.tm h2{font-size:1.6rem;color:var(--brand-deep);margin:32px 0 14px;}
.tm-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px;}
.tm-card{background:var(--cream);border:1px solid var(--line);border-radius:16px;padding:16px;color:var(--ink);}
.tm-name{font-family:'Cormorant Garamond',serif;font-size:1.35rem;font-weight:700;color:var(--brand-dark);}
.tm-desc{font-size:.9rem;color:var(--muted);margin:2px 0 12px;}
.tm-swatches{display:flex;gap:6px;margin-bottom:12px;}
.tm-swatch{flex:1;height:34px;border-radius:8px;border:1px solid rgba(0,0,0,.08);}
.tm-sample{border-radius:12px;padding:14px;background:var(--hero-grad);color:var(--cream);}
.tm-sample-title{font-family:'Cormorant Garamond',serif;font-size:1.25rem;font-weight:600;margin-bottom:8px;}
.tm-sample .btn-brand{background:var(--accent);color:var(--brand-dark);padding:8px 18px;font-size:.8rem;}
.tm-box{position:relative;height:140px;border-radius:12px;overflow:hidden;margin:10px 0 12px;background:var(--hero-grad);--creature:#FDFAF5;--creature-2:#DEB97A;}
.tm-box .bf{--o:.8;}
.tm-box-empty{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:var(--cream);font-size:.85rem;opacity:.8;}
.tm-play{border:0;font-family:inherit;}
.tm-foot{margin-top:32px;color:var(--muted);font-size:.9rem;}
</style>
</head>
<body>
<main class="tm">
  <h1>Escoge el diseño de tu guía</h1>
  <p class="tm-lead">Mira los colores y las animaciones, y cuéntanos por WhatsApp cuál te gusta de cada una. Puedes cambiarlos cuando quieras.</p>

  <h2>Colores</h2>
  <div class="tm-grid">
<?php foreach ($palettes as $key => [$label, $desc]): ?>
    <div class="tm-card" data-palette="<?= h($key) ?>">
      <div class="tm-name"><?= h($label) ?></div>
      <p class="tm-desc"><?= h($label) ?>: <?= h($desc) ?></p>
      <div class="tm-swatches" aria-hidden="true">
<?php foreach (['--brand', '--brand-dark', '--accent', '--creature', '--cream'] as $var): ?>
        <span class="tm-swatch" style="background:var(<?= h($var) ?>)"></span>
<?php endforeach; ?>
      </div>
      <div class="tm-sample">
        <div class="tm-sample-title">Bienvenido a tu apartamento</div>
        <span class="btn-brand">Ver la guía</span>
      </div>
    </div>
<?php endforeach; ?>
  </div>

  <h2>Animaciones</h2>
  <div class="tm-grid">
<?php foreach ($motions as $key => [$label, $desc]): ?>
    <div class="tm-card" data-motion-card="<?= h($key) ?>">
      <div class="tm-name"><?= h($label) ?></div>
      <p class="tm-desc"><?= mb_strtolower($label) === $desc ? 'Con ' . h($desc) . ' flotando' : h($label) . ': ' . h($desc) ?></p>
      <div class="tm-box" aria-hidden="true"></div>
      <button type="button" class="btn-brand tm-play" data-motion="<?= h($key) ?>">Ver <?= h(mb_strtolower($label)) ?></button>
    </div>
<?php endforeach; ?>
  </div>

  <p class="tm-foot">Cada guía lleva un color y una animación. Si no escoges, usamos Caribe con Marino, los de la guía modelo.</p>
</main>
<script src="<?= h(guide_asset('guide.js')) ?>"></script>
<script>
(function () {
  document.querySelectorAll('.tm-play').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var key = btn.getAttribute('data-motion');
      var box = btn.parentNode.querySelector('.tm-box');
      if (key === 'ninguno') {
        box.innerHTML = '<div class="tm-box-empty">Sin animación</div>';
      } else if (window.GuideMotion) {
        window.GuideMotion.play(box, key);
      }
    });
  });
})();
</script>
</body>
</html>
