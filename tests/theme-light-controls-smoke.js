'use strict';

// Regression audit for actionable controls in the light theme.
// Run with: node tests/theme-light-controls-smoke.js
const fs = require('fs');
const path = require('path');
const assert = require('assert');

const root = path.resolve(__dirname, '..');
const theme = fs.readFileSync(path.join(root, 'assets/theme.css'), 'utf8');
const app = fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8');
const version = fs.readFileSync(path.join(root, 'src/Core/Version.php'), 'utf8');
const lightStart = theme.indexOf('/* Tema claro: controles interactivos visibles y coherentes en todas las pantallas. */');
assert(lightStart >= 0, 'global light-control override block is missing');
const lightBlock = theme.slice(lightStart);
assert(!/:root\[data-theme="dark"\]/.test(lightBlock), 'light-control block must not alter dark theme');

function ruleExists(selector, requiredDeclaration) {
  const rules = [...lightBlock.matchAll(/([^{}]+)\{([^{}]*)\}/g)];
  const rule = rules.find((match) => match[1].replace(/\/\*[\s\S]*?\*\//g, '').split(',').map((item) => item.trim()).includes(selector));
  assert(rule, `missing light-theme rule: ${selector}`);
  if (requiredDeclaration) assert(rule[2].includes(requiredDeclaration), `${selector} must include ${requiredDeclaration}`);
}

[
  ['.btn-outline', 'background: var(--light-control-bg)'],
  ['.btn-quiet', 'background: #E2E9F1'],
  ['.btn-danger-quiet', 'background: #FCEBED'],
  ['.btn-white', 'background: #EAF1F8'],
  ['.user-chip', 'background: #F1F5F9'],
  ['.filename-edit-button', 'background: #E6EDF5'],
  ['.help-modal-close', 'background: #E6EDF5'],
  ['.alert-close', 'border: 1px solid'],
  ['.mobile-menu', 'background: #E6EDF5'],
  ['.variable-item', 'background: #F1F5F9'],
  ['.check-card', 'background: #F6F9FC'],
  ['.check-visual', 'border-color: #879CB1'],
  ['.dropzone', 'background: #F5F9FE'],
  ['.branding-logo-input', 'border-color: #91AFCB'],
  ['.branding-logo-input::file-selector-button', 'background: #006ECA'],
].forEach(([selector, declaration]) => ruleExists(`:root[data-theme="light"] ${selector}`, declaration));

for (const selector of [
  '.btn:focus-visible',
  '.filename-edit-button:focus-visible',
  '.bulk-modal-x:focus-visible',
  '.help-modal-close:focus-visible',
  '.alert-close:focus-visible',
  '.mobile-menu:focus-visible',
  '.variable-item:focus-visible',
  '.dropzone:focus-visible',
  '.user-chip:focus-visible',
]) {
  ruleExists(`:root[data-theme="light"] ${selector}`, 'outline: 3px solid');
}

// Check WCAG AA contrast for button labels in the light palette (normal text).
function luminance(hex) {
  const values = [1, 3, 5].map((offset) => parseInt(hex.slice(offset, offset + 2), 16) / 255);
  const linear = values.map((value) => (value <= 0.04045 ? value / 12.92 : ((value + 0.055) / 1.055) ** 2.4));
  return 0.2126 * linear[0] + 0.7152 * linear[1] + 0.0722 * linear[2];
}
function contrast(foreground, background) {
  const values = [luminance(foreground), luminance(background)].sort((a, b) => b - a);
  return (values[0] + 0.05) / (values[1] + 0.05);
}
for (const [foreground, background, name] of [
  ['#29415C', '#E6EDF5', 'outline buttons'],
  ['#29415C', '#E2E9F1', 'quiet buttons'],
  ['#9F2632', '#FCEBED', 'quiet destructive buttons'],
  ['#005299', '#EAF1F8', 'white buttons'],
  ['#29415C', '#F1F5F9', 'account chip'],
  ['#52657A', '#F1F5F9', 'variable descriptions'],
  ['#FFFFFF', '#006ECA', 'primary buttons'],
  ['#FFFFFF', '#E20613', 'destructive buttons'],
  ['#FFFFFF', '#16835D', 'bulk-send button'],
]) {
  assert(contrast(foreground, background) >= 4.5, `${name} text contrast is below WCAG AA`);
}

// The primary/destructive/bulk actions already have strong filled states and remain so.
assert.match(app, /\.btn-primary\s*\{[^}]*color:\s*#fff[^}]*background:\s*var\(--blue\)/s);
assert.match(app, /\.btn-danger\s*\{[^}]*color:\s*#fff[^}]*background:\s*var\(--red\)/s);
assert.match(app, /\.btn-bulk-send\s*\{[^}]*color:\s*#fff[^}]*background:\s*#16835D/s);
assert.match(app, /\.bulk-modal-x\s*\{[^}]*background:\s*var\(--red\)[^}]*color:\s*#fff/s);
assert.match(app, /\.sidebar-help button\s*\{[^}]*border:1px solid rgba\(201,220,240,.18\)[^}]*color:#D7E3F2[^}]*background:rgba\(255,255,255,.045\)/s);
assert.match(app, /\.nav-link\s*\{[^}]*color:#BDD0E4/);
assert.match(app, /\.check-inline input\s*\{[^}]*accent-color:var\(--blue\)/);

// Ensure the application still exposes all classes audited above in its actual views.
const files = [
  'views/layout.php', 'views/pages/departments.php', 'views/pages/mail.php',
  'views/pages/history.php', 'views/pages/users.php', 'views/pages/upload.php',
  'views/pages/branding.php', 'login.php',
].map((file) => path.join(root, file)).filter((file) => fs.existsSync(file));
const markup = files.map((file) => fs.readFileSync(file, 'utf8')).join('\n');
for (const className of [
  'btn-outline', 'btn-quiet', 'btn-danger-quiet', 'btn-primary', 'btn-danger',
  'btn-bulk-send', 'theme-toggle', 'filename-edit-button', 'bulk-modal-x',
  'help-modal-close', 'alert-close', 'mobile-menu', 'user-chip', 'variable-item',
  'check-card', 'dropzone', 'branding-logo-input',
]) {
  assert(markup.includes(className) || app.includes(`.${className}`), `control class was not found in views/styles: ${className}`);
}

assert.match(version, /VERSION\s*=\s*'1\.2\.31'/, 'application version must be 1.2.31');
for (const file of ['views/layout.php', 'login.php']) {
  const html = fs.readFileSync(path.join(root, file), 'utf8');
  assert(html.includes('assets/theme.css?v=1.2.31'), `${file}: light/dark stylesheet cache key is stale`);
  assert(html.includes('assets/app.css?v=1.2.31'), `${file}: application stylesheet cache key is stale`);
}

console.log('OK: all light-theme button/control families have visible fills, borders, hover/focus states; dark-theme rules remain isolated.');
