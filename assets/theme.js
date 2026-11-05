/* ZKTimeNet theme manager: system preference + persistent user override. */
(function () {
  'use strict';

  var COOKIE_NAME = 'zk_personal_theme';
  var VALID = ['system', 'light', 'dark'];
  var media = null;
  var preference = readCookie();

  function readCookie() {
    var prefix = COOKIE_NAME + '=';
    var parts = document.cookie ? document.cookie.split(';') : [];
    for (var i = 0; i < parts.length; i += 1) {
      var part = parts[i].trim();
      if (part.indexOf(prefix) === 0) {
        try {
          var value = decodeURIComponent(part.substring(prefix.length));
          return VALID.indexOf(value) !== -1 ? value : 'system';
        } catch (_) {
          return 'system';
        }
      }
    }
    return 'system';
  }

  function systemTheme() {
    if (!media && window.matchMedia) media = window.matchMedia('(prefers-color-scheme: dark)');
    return media && media.matches ? 'dark' : 'light';
  }

  function resolvedTheme() {
    return preference === 'system' ? systemTheme() : preference;
  }

  function updateMeta(theme) {
    var meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', theme === 'dark' ? '#0B1220' : '#006ECA');
  }

  function updateButtons() {
    var active = resolvedTheme();
    var labels = { system: 'Sistema', light: 'Claro', dark: 'Oscuro' };
    var icons = { system: '◐', light: '☀', dark: '☾' };
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      var label = button.querySelector('[data-theme-label]');
      var icon = button.querySelector('[data-theme-icon]');
      if (label) label.textContent = labels[preference];
      if (icon) icon.textContent = icons[preference];
      button.setAttribute('aria-label', 'Tema actual: ' + labels[preference] + '. Cambiar tema');
      button.setAttribute('title', 'Tema: ' + labels[preference] + ' (apariencia ' + (active === 'dark' ? 'oscura' : 'clara') + ')');
    });
  }

  function apply() {
    var theme = resolvedTheme();
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.setAttribute('data-theme-preference', preference);
    updateMeta(theme);
    updateButtons();
  }

  function cookiePath() {
    // La aplicación puede estar publicada en un subdirectorio IIS, por ejemplo /nominas/.
    var pathname = (window.location && window.location.pathname) || '/';
    var slash = pathname.lastIndexOf('/');
    return slash >= 0 ? (pathname.slice(0, slash + 1) || '/') : '/';
  }

  function saveCookie() {
    var secure = window.location && window.location.protocol === 'https:' ? '; Secure' : '';
    var value = COOKIE_NAME + '=' + encodeURIComponent(preference) + '; Path=' + cookiePath() + '; Max-Age=31536000; SameSite=Lax' + secure;
    // Elimina una posible cookie antigua creada con Path=/ para evitar valores duplicados.
    document.cookie = COOKIE_NAME + '=; Path=/; Max-Age=0; SameSite=Lax' + secure;
    document.cookie = value;
  }

  function cycle() {
    var next = { system: 'light', light: 'dark', dark: 'system' };
    preference = next[preference] || 'light';
    saveCookie();
    apply();
    return preference;
  }

  // El listener vive en este archivo y usa delegación en document. Así no depende
  // de que app.js haya terminado de cargar ni de que el botón exista al inicializarse.
  document.addEventListener('click', function (event) {
    var target = event.target;
    var button = target && typeof target.closest === 'function'
      ? target.closest('[data-theme-toggle]')
      : null;
    if (!button) return;
    event.preventDefault();
    cycle();
  }, true);

  function onSystemChange() {
    if (preference === 'system') apply();
  }

  if (window.matchMedia) {
    media = window.matchMedia('(prefers-color-scheme: dark)');
    if (typeof media.addEventListener === 'function') media.addEventListener('change', onSystemChange);
    else if (typeof media.addListener === 'function') media.addListener(onSystemChange);
  }

  window.ZkTheme = {
    cycle: cycle,
    syncButtons: updateButtons,
    preference: function () { return preference; },
    resolved: resolvedTheme
  };

  // Se ejecuta antes de cargar las hojas de estilo para evitar el destello del tema incorrecto.
  apply();
}());
