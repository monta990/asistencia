'use strict';

// Development-only test. Run with: node tests/theme-smoke.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'assets/theme.js'), 'utf8');
const cookies = new Map();
const rootAttributes = {};
const buttonAttributes = {};
const metaAttributes = {};
let clickHandler = null;
let mediaHandler = null;

const label = { textContent: '' };
const icon = { textContent: '' };
const button = {
  querySelector(selector) {
    if (selector === '[data-theme-label]') return label;
    if (selector === '[data-theme-icon]') return icon;
    return null;
  },
  setAttribute(name, value) { buttonAttributes[name] = value; },
  closest(selector) { return selector === '[data-theme-toggle]' ? this : null; },
};
const meta = { setAttribute(name, value) { metaAttributes[name] = value; } };
const document = {
  get cookie() {
    return [...cookies.values()].map((cookie) => `${cookie.name}=${cookie.value}`).join('; ');
  },
  set cookie(serialized) {
    const [pair, ...attributes] = serialized.split(';').map((item) => item.trim());
    const separator = pair.indexOf('=');
    const name = pair.slice(0, separator);
    const value = pair.slice(separator + 1);
    const pathAttribute = attributes.find((item) => /^Path=/i.test(item));
    const cookiePath = pathAttribute ? pathAttribute.slice(5) : '/';
    const maxAge = attributes.find((item) => /^Max-Age=/i.test(item));
    const key = `${name}|${cookiePath}`;
    if (maxAge && maxAge.toLowerCase() === 'max-age=0') cookies.delete(key);
    else cookies.set(key, { name, value, path: cookiePath });
  },
  documentElement: {
    setAttribute(name, value) { rootAttributes[name] = value; },
  },
  querySelector(selector) {
    return selector === 'meta[name="theme-color"]' ? meta : null;
  },
  querySelectorAll(selector) {
    return selector === '[data-theme-toggle]' ? [button] : [];
  },
  addEventListener(type, handler, capture) {
    if (type === 'click' && capture === true) clickHandler = handler;
  },
};
const media = {
  matches: true,
  addEventListener(type, handler) {
    if (type === 'change') mediaHandler = handler;
  },
};
const window = {
  location: { pathname: '/nominas/index.php', protocol: 'http:' },
  matchMedia() { return media; },
};

vm.runInNewContext(source, {
  window,
  document,
  encodeURIComponent,
  decodeURIComponent,
});

assert.strictEqual(window.ZkTheme.preference(), 'system');
assert.strictEqual(label.textContent, 'Sistema');

function clickTheme() {
  let prevented = false;
  clickHandler({
    target: button,
    preventDefault() { prevented = true; },
  });
  assert.strictEqual(prevented, true);
}

clickTheme();
assert.strictEqual(window.ZkTheme.preference(), 'light');
assert.strictEqual(rootAttributes['data-theme'], 'light');
assert.strictEqual(label.textContent, 'Claro');
assert.strictEqual(icon.textContent, '☀');
assert.strictEqual(document.cookie, 'zk_personal_theme=light');
assert(cookies.has('zk_personal_theme|/nominas/'));

clickTheme();
assert.strictEqual(window.ZkTheme.preference(), 'dark');
assert.strictEqual(rootAttributes['data-theme'], 'dark');
assert.strictEqual(label.textContent, 'Oscuro');

clickTheme();
assert.strictEqual(window.ZkTheme.preference(), 'system');
assert.strictEqual(rootAttributes['data-theme'], 'dark');
assert.strictEqual(label.textContent, 'Sistema');

media.matches = false;
mediaHandler();
assert.strictEqual(rootAttributes['data-theme'], 'light');
assert.strictEqual(metaAttributes.content, '#006ECA');

for (const file of ['views/layout.php', 'login.php']) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');
  assert(html.includes('assets/theme.js?v=1.2.19'));
  assert(html.includes('assets/app.js?v=1.2.19'));
}

console.log('OK: Sistema -> Claro -> Oscuro -> Sistema; cookie en /nominas/; detección del sistema y caché versionada.');
