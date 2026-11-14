/* Guide and gate behaviour, from apartasuite-613t2/index.html.
   The theme is on <html>: data-palette (colors, read from CSS variables) and
   data-motion (which creatures float around, or none). The gate code is checked on
   the server; this file only remembers the guest's name for the greeting. */
(function () {
  'use strict';

  var root = document.documentElement;
  var reduced = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var NAME_KEY = 'unitGuestName';
  var LANG_KEY = 'guideLang';

  function store(op, key, value) {
    try {
      if (op === 'get') { return window.sessionStorage.getItem(key); }
      window.sessionStorage.setItem(key, value);
    } catch (e) { /* storage blocked: the page still works */ }
    return null;
  }

  /* ───── Creatures: 40x40 SVGs colored by the palette (.cr-1/.cr-2 fill, .cr-s/.cr-o stroke) ───── */
  function svg(body) { return '<svg viewBox="0 0 40 40" aria-hidden="true" focusable="false">' + body + '</svg>'; }
  var manta = svg('<path class="cr-1" d="M20 10 L4 22 Q2 24 4 25 L18 21 L20 30 L22 21 L36 25 Q38 24 36 22 L20 10 Z" opacity=".85"/><line class="cr-s" x1="20" y1="30" x2="20" y2="37" stroke-width="1.6"/>');
  var fish = svg('<path class="cr-1" d="M6 20 C6 13 14 9 22 13 C26 15 28 18 28 20 C28 22 26 25 22 27 C14 31 6 27 6 20 Z" opacity=".85"/><path class="cr-2" d="M28 20 L37 13 L34 20 L37 27 Z" opacity=".85"/>');
  var turtle = svg('<path class="cr-2" d="M13 14 Q4 9 3 15 Q8 18 13 18 Z M27 14 Q36 9 37 15 Q32 18 27 18 Z M14 27 Q8 32 10 35 Q14 33 16 29 Z M26 27 Q32 32 30 35 Q26 33 24 29 Z" opacity=".85"/><circle class="cr-2" cx="20" cy="8" r="3.6" opacity=".85"/><ellipse class="cr-1" cx="20" cy="21" rx="9" ry="11" opacity=".9"/><path class="cr-s" d="M20 12 V30 M12 21 H28" stroke-width="1" opacity=".7"/>');
  var bubble = svg('<circle class="cr-o" cx="20" cy="20" r="12" stroke-width="2" opacity=".85"/><path class="cr-s" d="M13 17 Q14 12 19 11" stroke-width="2" stroke-linecap="round" opacity=".8"/>');
  var bubbleSmall = svg('<circle class="cr-o" cx="20" cy="20" r="7" stroke-width="2" opacity=".85"/><circle class="cr-2" cx="17" cy="17" r="1.6" opacity=".8"/>');
  var butterfly = svg('<path class="cr-1" d="M20 20 C10 6 0 10 6 20 C0 30 10 34 20 20 C30 34 40 30 34 20 C40 10 30 6 20 20Z" opacity=".85"/><line class="cr-s" x1="20" y1="14" x2="20" y2="27" stroke-width="1.6"/>');
  var hummingbird = svg('<path class="cr-1" d="M8 25 C12 19 20 17 26 19 C29 20 30 22 29 24 C26 27 18 28 12 27 L3 31 Z" opacity=".85"/><path class="cr-s" d="M29 21 L38 18" stroke-width="1.4" stroke-linecap="round"/><path class="cr-2" d="M17 19 C15 10 19 5 26 3 C24 9 23 14 21 19 Z" opacity=".85"/>');
  var bird = svg('<path class="cr-o" d="M4 23 Q12 12 20 21 Q28 12 36 23" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" opacity=".9"/>');
  var palm = svg('<path class="cr-s" d="M6 35 Q17 23 34 6" stroke-width="1.6" stroke-linecap="round"/><path class="cr-1" d="M10 31 Q5 23 11 18 Q14 24 13 29 Z M15 26 Q12 17 19 13 Q20 20 18 24 Z M20 21 Q19 12 26 9 Q26 15 23 19 Z M14 31 Q22 31 25 25 Q19 25 16 28 Z M19 26 Q27 26 30 20 Q24 20 21 23 Z M24 21 Q31 20 33 14 Q28 15 26 18 Z" opacity=".85"/>');
  var petals = svg('<g opacity=".85"><path class="cr-1" d="M20 20 C14 12 16 5 20 8 C24 5 26 12 20 20Z"/><path class="cr-1" d="M20 20 C14 12 16 5 20 8 C24 5 26 12 20 20Z" transform="rotate(120 20 20)"/><path class="cr-1" d="M20 20 C14 12 16 5 20 8 C24 5 26 12 20 20Z" transform="rotate(240 20 20)"/></g><circle class="cr-2" cx="20" cy="20" r="2.2"/>');
  var petal = svg('<path class="cr-1" d="M20 33 C10 23 12 8 20 12 C28 8 30 23 20 33Z" opacity=".85"/><path class="cr-s" d="M20 14 V30" stroke-width="1" opacity=".6"/>');
  var firefly = svg('<circle class="cr-2" cx="20" cy="20" r="12" opacity=".22"/><circle class="cr-2" cx="20" cy="20" r="7" opacity=".4"/><circle class="cr-1" cx="20" cy="20" r="3.2"/>');

  /* kind picks the CSS animation: float (613T2), rise, fall or glow */
  var CREATURES = {
    marino: { kind: 'float', svgs: [manta, fish] },
    tortugas: { kind: 'float', svgs: [turtle] },
    burbujas: { kind: 'rise', svgs: [bubble, bubbleSmall] },
    mariposas: { kind: 'float', svgs: [butterfly] },
    colibries: { kind: 'float', svgs: [hummingbird] },
    aves: { kind: 'float', svgs: [bird] },
    palmas: { kind: 'fall', svgs: [palm] },
    flores: { kind: 'fall', svgs: [petals, petal] },
    luciernagas: { kind: 'glow', svgs: [firefly] }
  };
  var motion = CREATURES[root.getAttribute('data-motion') || 'marino'] || null;

  function rnd(a, b) { return a + Math.random() * (b - a); }
  function pick(list) { return list[Math.floor(Math.random() * list.length)]; }

  /* Floating creatures in a container: n items, size range, vertical spread, durations. */
  function populate(id, n, sMin, sMax, topMin, topMax, delayMax, durMin, durMax) {
    var wrap = document.getElementById(id);
    if (!wrap || !motion) { return; }
    for (var i = 0; i < n; i++) {
      var d = document.createElement('div');
      d.className = 'bf' + (motion.kind !== 'float' ? ' m-' + motion.kind : '');
      var s = rnd(sMin, sMax);
      d.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + rnd(0, 94) + '%;top:' + rnd(topMin, topMax) + '%;' +
        'animation-delay:-' + rnd(0, delayMax) + 's;animation-duration:' + rnd(durMin, durMax) + 's;';
      d.innerHTML = pick(motion.svgs);
      wrap.appendChild(d);
    }
  }

  /* ───── Gate ───── */
  var gateForm = document.getElementById('gate-form');
  if (gateForm) {
    // Entrance burst, as in 613T2.
    var burst = document.getElementById('bf-enter');
    if (burst && motion && !reduced) {
      for (var i = 0; i < 24; i++) {
        var f = document.createElement('div');
        f.className = 'bf-fly';
        var s = rnd(16, 38);
        f.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + rnd(0, 94) + '%;animation-delay:' + rnd(0, 2.5) + 's;animation-duration:' + rnd(4.5, 7.5) + 's;';
        f.innerHTML = pick(motion.svgs);
        burst.appendChild(f);
        f.addEventListener('animationend', function () { this.remove(); });
      }
    }
    // Liquid fluid in the palette's colors.
    var canvas = document.getElementById('gate-fluid');
    if (canvas && typeof window.WebGLFluid === 'function' && !reduced) {
      var cs = getComputedStyle(root);
      var colors = ['--creature', '--accent', '--creature-2', '--brand'].map(function (v) { return hexToRgb(cs.getPropertyValue(v)); })
        .filter(function (c) { return c !== null; });
      var splat = colors.length ? { r: colors[0].r, g: colors[0].g, b: colors[0].b } : undefined;
      try {
        window.WebGLFluid(canvas, {
          TRIGGER: 'hover', IMMEDIATE: true, AUTO: true, INTERVAL: 3500,
          DENSITY_DISSIPATION: 3.2, VELOCITY_DISSIPATION: 1.8, CURL: 22,
          SPLAT_RADIUS: 0.4, SPLAT_FORCE: 5000, SPLAT_COUNT: 4,
          TRANSPARENT: true, SHADING: true, COLORFUL: true, COLOR_UPDATE_SPEED: 6,
          BLOOM: false, SUNRAYS: false, SPLAT_COLOR: splat
        });
        // The library reads SPLAT_COLOR on every splat; cycle it through the palette.
        if (splat && colors.length > 1) {
          var ci = 0;
          setInterval(function () {
            ci = (ci + 1) % colors.length;
            splat.r = colors[ci].r; splat.g = colors[ci].g; splat.b = colors[ci].b;
          }, 900);
        }
      } catch (e) { /* no WebGL: the gradient alone is fine */ }
    }
    // The server checks the code; the name only greets the guest on the guide.
    gateForm.addEventListener('submit', function () {
      var nm = document.getElementById('gate-name');
      if (nm && nm.value.trim()) { store('set', NAME_KEY, nm.value.trim().slice(0, 60)); }
    });
    applyLang(store('get', LANG_KEY) === 'en' ? 'en' : 'es');
    return;
  }

  /* palette color "#4AADA8" as fluid dye (0..1 scaled down, the library's intensity) */
  function hexToRgb(v) {
    var m = /^\s*#([0-9a-f]{6})\s*$/i.exec(v || '');
    if (!m) { return null; }
    var n = parseInt(m[1], 16), k = 0.3 / 255;
    return { r: ((n >> 16) & 255) * k, g: ((n >> 8) & 255) * k, b: (n & 255) * k };
  }

  /* ───── Language ───── */
  var lang = 'es';
  function applyLang(l) {
    lang = l;
    var es = document.getElementById('btn-es'), en = document.getElementById('btn-en');
    if (es) { es.classList.toggle('active', l === 'es'); }
    if (en) { en.classList.toggle('active', l === 'en'); }
    document.querySelectorAll('[data-es]').forEach(function (el) { el.textContent = el.getAttribute('data-' + l); });
    document.querySelectorAll('[data-es-placeholder]').forEach(function (el) { el.placeholder = el.getAttribute('data-' + l + '-placeholder'); });
    document.querySelectorAll('img[data-alt-es]').forEach(function (el) { el.alt = el.getAttribute('data-alt-' + l) || ''; });
    root.lang = l;
    renderWeather();
  }
  document.querySelectorAll('[data-lang]').forEach(function (b) {
    b.addEventListener('click', function () {
      var l = b.getAttribute('data-lang') === 'en' ? 'en' : 'es';
      store('set', LANG_KEY, l);
      applyLang(l);
    });
  });

  /* ───── Greeting ───── */
  var heroName = document.getElementById('hero-name');
  var guest = store('get', NAME_KEY);
  if (heroName && guest) { heroName.textContent = ', ' + guest; }

  /* ───── Clock ───── */
  var clock = document.getElementById('local-clock');
  function tick() {
    if (!clock) { return; }
    try {
      clock.textContent = new Date().toLocaleTimeString('en-US', { timeZone: clock.getAttribute('data-tz') || 'America/Bogota', hour: '2-digit', minute: '2-digit' });
    } catch (e) { clock.textContent = new Date().toLocaleTimeString('en-US', { hour: '2-digit', minute: '2-digit' }); }
  }
  tick(); setInterval(tick, 30000);

  /* ───── Creatures ───── */
  populate('butterflies', 20, 14, 36, 15, 80, 14, 11, 19);
  populate('bf-page', 14, 10, 28, 0, 95, 18, 12, 22);

  /* ───── Lightbox: any element with data-lb (image or video URL) ───── */
  var lb = document.getElementById('lightbox');
  var lbImg = document.getElementById('lb-img');
  var lbStack = document.getElementById('lb-stack');
  var lbVid = document.getElementById('lb-video');
  function openLB(src) {
    if (!lb) { return; }
    lbVid.pause(); lbVid.style.display = 'none'; lbVid.removeAttribute('src');
    lbStack.style.display = 'none'; lbStack.innerHTML = '';
    if (/\.(mp4|webm|mov|m4v)(\?|$)/i.test(src)) {
      lbImg.style.display = 'none';
      lbVid.src = src; lbVid.style.display = 'block';
    } else {
      lbImg.style.display = 'block'; lbImg.src = src;
    }
    lb.classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeLB() {
    if (!lb) { return; }
    lbVid.pause(); lbVid.removeAttribute('src');
    lb.classList.remove('open');
    document.body.style.overflow = '';
  }
  document.addEventListener('click', function (e) {
    var t = e.target.closest ? e.target.closest('[data-lb]') : null;
    if (t) { e.preventDefault(); openLB(t.getAttribute('data-lb')); return; }
    if (lb && lb.classList.contains('open') && e.target !== lbVid) { closeLB(); }
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') { closeLB(); return; }
    if ((e.key === 'Enter' || e.key === ' ') && e.target.getAttribute && e.target.getAttribute('role') === 'button' && e.target.hasAttribute('data-lb')) {
      e.preventDefault(); openLB(e.target.getAttribute('data-lb'));
    }
  });

  /* ───── WiFi QR code (WIFI: format, special characters escaped) ───── */
  var qrBox = document.getElementById('wifi-qr');
  if (qrBox && typeof window.qrcode === 'function') {
    var esc = function (s) { return String(s).replace(/([\\;,:"])/g, '\\$1'); };
    var pass = qrBox.getAttribute('data-pass') || '';
    var qr = window.qrcode(0, 'M');
    qr.addData('WIFI:S:' + esc(qrBox.getAttribute('data-ssid') || '') + ';T:' + (pass ? 'WPA' : 'nopass') + ';P:' + esc(pass) + ';;');
    qr.make();
    qrBox.innerHTML = qr.createImgTag(4, 0);
    var qrImg = qrBox.querySelector('img');
    if (qrImg) { qrImg.alt = 'WiFi QR'; }
  }

  /* ───── Weather (Open-Meteo, no key needed) ───── */
  var wx = document.getElementById('weather');
  var weatherData = null;
  function wmoIcon(c) {
    if (c === 0) { return '☀️'; } if (c <= 2) { return '🌤'; } if (c === 3) { return '☁️'; }
    if (c <= 48) { return '🌫'; } if (c <= 57) { return '🌦'; } if (c <= 67) { return '🌧'; }
    if (c <= 77) { return '🌨'; } if (c <= 82) { return '🌧'; } if (c <= 86) { return '🌨'; } return '⛈';
  }
  var wmoText = {
    es: { 0: 'Despejado', 1: 'Mayormente despejado', 2: 'Parcialmente nublado', 3: 'Nublado', 45: 'Neblina', 61: 'Lluvia ligera', 63: 'Lluvia', 65: 'Lluvia fuerte', 80: 'Chubascos', 95: 'Tormenta' },
    en: { 0: 'Clear', 1: 'Mostly clear', 2: 'Partly cloudy', 3: 'Cloudy', 45: 'Fog', 61: 'Light rain', 63: 'Rain', 65: 'Heavy rain', 80: 'Showers', 95: 'Thunderstorm' }
  };
  function escHtml(s) { return String(s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
  function renderWeather() {
    if (!wx || !weatherData) { return; }
    var d = weatherData, city = escHtml(wx.getAttribute('data-city') || '');
    var code = d.current.weather_code;
    var desc = wmoText[lang][code] || (lang === 'es' ? 'Caribe en su punto' : 'Caribbean vibes');
    var names = lang === 'es' ? ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'] : ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    var days = '';
    for (var i = 1; i <= 3 && i < d.daily.time.length; i++) {
      var dt = new Date(d.daily.time[i] + 'T12:00:00');
      days += '<div class="wday"><span>' + wmoIcon(d.daily.weather_code[i]) + '</span><b>' + Math.round(d.daily.temperature_2m_max[i]) + '°</b>' + names[dt.getDay()] + '</div>';
    }
    wx.innerHTML = '<div class="weather-now"><div class="weather-icon">' + wmoIcon(code) + '</div><div>' +
      '<div class="weather-temp">' + Math.round(d.current.temperature_2m) + '°C</div>' +
      '<div class="weather-desc">' + escHtml(desc) + (city ? ' · ' + city : '') + '</div></div></div>' +
      '<div class="weather-days">' + days + '</div>';
  }
  if (wx) {
    var url = 'https://api.open-meteo.com/v1/forecast?latitude=' + encodeURIComponent(wx.getAttribute('data-lat')) +
      '&longitude=' + encodeURIComponent(wx.getAttribute('data-lon')) +
      '&current=temperature_2m,weather_code&daily=weather_code,temperature_2m_max&timezone=' +
      encodeURIComponent(wx.getAttribute('data-tz') || 'America/Bogota') + '&forecast_days=4';
    fetch(url).then(function (r) { return r.json(); }).then(function (d) {
      if (d && d.current && d.daily) { weatherData = d; renderWeather(); }
    }).catch(function () {
      wx.innerHTML = '<div class="weather-now"><div class="weather-icon">🌴</div><div><div class="weather-temp">~30°C</div><div class="weather-desc">' +
        (lang === 'es' ? 'Sol caribeño casi garantizado' : 'Caribbean sun, almost guaranteed') + '</div></div></div>';
    });
  }

  var saved = store('get', LANG_KEY);
  if (saved === 'en') { applyLang('en'); }
})();
