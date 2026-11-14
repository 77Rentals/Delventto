<?php
// Gate page: 613T2's passcode screen, now checked on the server. Posts name and code
// to the same URL; render.php compares the code and sets the cookie. Shows only the
// building and the theme, never guide content (the unit name is the code).
// Loaded by render_gate().
if (!defined('GUIDE_VIEW')) {
    http_response_code(404);
    exit;
}
/** @var array $b @var array $theme @var bool $error @var string $postedName */

$bName = tval($b['name'] ?? null) ?? ['es' => 'Guía de bienvenida', 'en' => 'Welcome guide'];
$short = sval($b['shortName'] ?? null);
?><!DOCTYPE html>
<html lang="es" data-palette="<?= h($theme['palette']) ?>" data-motion="<?= h($theme['motion']) ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title><?= h($bName['es']) ?> · Welcome Guide</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,500;0,600;0,700;1,400;1,500&family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= h(guide_asset('guide.css')) ?>">
</head>
<body>

<!-- PASSCODE GATE -->
<div class="gate" id="gate">
  <canvas class="gate-fluid" id="gate-fluid" aria-hidden="true"></canvas>
  <div class="bf-enter" id="bf-enter" aria-hidden="true"></div>
  <form class="gate-card" id="gate-form" method="post" autocomplete="off">
    <div class="gate-eyebrow"><?= tx('Bienvenido a', 'Welcome to') ?></div>
    <h1 class="gate-title"><?= title_html($bName, $short) ?></h1>
    <p class="gate-sub"><?= tx('Ingresa el código que te enviamos para ver tu guía de bienvenida.', 'Enter the code we sent you to view your welcome guide.') ?></p>
    <input type="text" name="name" id="gate-name" class="gate-input" maxlength="60" autocomplete="off" spellcheck="false" data-es-placeholder="Tu nombre" data-en-placeholder="Your name" placeholder="Tu nombre" aria-label="Tu nombre" value="<?= h($postedName) ?>">
    <input type="text" name="code" id="gate-input" class="gate-input" maxlength="16" required autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="••••••" aria-label="Código"<?= $error ? ' autofocus' : '' ?>>
    <button type="submit" class="gate-btn"><?= tx('Ingresar', 'Enter') ?></button>
    <p class="gate-error<?= $error ? ' show' : '' ?>" id="gate-error" role="alert"><?= tx('Código incorrecto, intenta de nuevo.', 'Incorrect code, please try again.') ?></p>
  </form>
</div>

<script src="<?= h(guide_asset('webgl-fluid.js')) ?>"></script>
<script src="<?= h(guide_asset('guide.js')) ?>"></script>
</body>
</html>
