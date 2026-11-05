'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const layout = fs.readFileSync(path.join(root, 'views/layout.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8');
const version = fs.readFileSync(path.join(root, 'src/Core/Version.php'), 'utf8');
const pagesDir = path.join(root, 'views/pages');
const pages = fs.readdirSync(pagesDir).filter((name) => name.endsWith('.php'));

assert.match(version, /VERSION\s*=\s*'1\.2\.19'/, 'application version must be 1.2.19');
assert.match(layout, /<section class=\"page-content\">/, 'authenticated page wrapper must be shared');
assert.doesNotMatch(layout, /page-content\?\s*<\?=|page-content-wide/, 'full-width behavior must not depend on the selected page');
assert.match(css, /\.page-content\{width:100%;max-width:none;flex:1;margin:0;/, 'shared content wrapper must span the main area without a max-width cap');
assert.doesNotMatch(css, /\.page-content\{[^}]*max-width\s*:\s*(?:[0-9]+px|[0-9]+rem)/, 'shared content wrapper must not have a fixed max-width');
for (const page of ['upload.php', 'dashboard.php', 'history.php', 'departments.php', 'mail.php', 'branding.php', 'users.php', 'account.php', 'updates.php']) {
  assert.ok(pages.includes(page), `expected authenticated page template ${page}`);
}
assert.match(css, /@media\(max-width:1120px\)[\s\S]*\.page-content\{padding-right:22px;padding-left:22px\}/, 'tablet gutter must remain responsive');
assert.match(css, /@media\(max-width:560px\)[\s\S]*\.page-content\{padding:18px 13px 28px\}/, 'mobile gutter must remain compact');
console.log(`all-pages-full-width-smoke: OK (${pages.length} page templates use shared full-width layout)`);
