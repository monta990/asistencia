'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };
const index = read('index.php');
const layout = read('views/layout.php');
const login = read('login.php');
const page = read('views/pages/branding.php');
const config = read('config/app.php');
const renderer = read('src/Services/TemplateRenderer.php');
const css = read('assets/app.css');
const version = read('src/Core/Version.php');
const updates = read('views/pages/updates.php');

assert(index.includes("'branding' => 'Identidad visual'"), 'branding page title missing');
assert(index.includes("'branding', 'history'"), 'branding page not allowlisted');
assert(index.includes("case 'save_branding':"), 'branding save handler missing');
assert(index.includes("$settings->set('branding', $newBranding)"), 'branding settings not persisted');
for (const field of ['company_name', 'brand_mark', 'brand_tagline', 'platform_label', 'module_label', 'application_name', 'footer_text']) {
  assert(page.includes(`name="${field}"`), `branding form field ${field} missing`);
}
assert(layout.includes("$branding['company_name']") && layout.includes("$branding['platform_label']"), 'layout does not consume branding');
assert(login.includes("$branding['company_name']") && login.includes("$branding['platform_label']"), 'login does not consume branding');
assert(config.includes("'branding_defaults'"), 'branding defaults missing');
for (const token of ['{{aplicacion}}', '{{plataforma}}', '{{lema}}']) assert(renderer.includes(token), `template variable ${token} missing`);
assert(css.includes('body{font-size:16px;line-height:1.55}'), 'global readability override missing');
assert(version.includes("VERSION = '1.2.19'"), 'version not bumped');
assert(updates.includes('Html::escape(PHP_VERSION)') && updates.includes('Version::VERSION'), 'technical runtime details must appear in Updates');
assert(!layout.includes('PHP_VERSION') && !layout.includes('Version::VERSION') && !login.includes('PHP_VERSION') && !login.includes('Version::VERSION'), 'runtime/version details must not appear in normal chrome or login');
console.log('OK: identidad editable, campos validados, SQLite settings, vistas/login, variables y escala tipográfica.');
