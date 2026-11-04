'use strict';
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const files = [
  'login.php', 'views/layout.php',
  ...fs.readdirSync(path.join(root, 'views/pages')).filter(name => name.endsWith('.php')).map(name => path.join('views/pages', name)),
];
const misses = [];
for (const relative of files) {
  const source = fs.readFileSync(path.join(root, relative), 'utf8');
  for (const match of source.matchAll(/<button\b[^>]*>([\s\S]*?)<\/button>/gi)) {
    if (!/Html::icon\s*\(/.test(match[1])) misses.push(`${relative}: button without Html::icon()`);
  }
  for (const match of source.matchAll(/<a\b[^>]*class="[^"]*\bbtn\b[^"]*"[^>]*>([\s\S]*?)<\/a>/gi)) {
    if (!/Html::icon\s*\(/.test(match[1])) misses.push(`${relative}: action link without Html::icon()`);
  }
}
const helper = fs.readFileSync(path.join(root, 'src/Core/Html.php'), 'utf8');
if (!helper.includes("public static function icon(string $name): string")) misses.push('Html::icon helper missing');
if (!fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8').includes('.ui-icon{')) misses.push('SVG icon styles missing');
if (!/Html::icon\(\$icon\)/.test(fs.readFileSync(path.join(root, 'views/layout.php'), 'utf8'))) misses.push('Navigation items must use the shared SVG icon helper');
const appJs = fs.readFileSync(path.join(root, 'assets/app.js'), 'utf8');
if (!appJs.includes('const setButtonText =')) misses.push('Dynamic button labels must use the icon-preserving helper');
if (/button\.textContent\s*=|startButton\.textContent\s*=/.test(appJs)) misses.push('Dynamic button label update may remove its icon');
if (misses.length) {
  console.error(misses.join('\n'));
  process.exit(1);
}
console.log(`Button icon smoke: ${files.length} PHP views checked; all buttons and action links have an icon.`);
