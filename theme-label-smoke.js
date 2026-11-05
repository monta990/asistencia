const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const layout = fs.readFileSync(path.join(root, 'views/layout.php'), 'utf8');
const login = fs.readFileSync(path.join(root, 'login.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/theme.css'), 'utf8');
const theme = fs.readFileSync(path.join(root, 'assets/theme.js'), 'utf8');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

for (const [name, source] of [['layout', layout], ['login', login]]) {
  assert(/class="theme-control[^"]*"/.test(source), `${name}: missing theme control wrapper`);
  assert(/<span class="theme-control-label">Tema<\/span>/.test(source), `${name}: missing visible Tema label`);
  assert(/data-theme-toggle/.test(source), `${name}: missing theme toggle`);
}
assert(/\.theme-control-label\s*\{[^}]*font-size:[^;}]+/s.test(css), 'theme label has no readable typography');
assert(/\.theme-control-label\s*\{[^}]*display:\s*none/s.test(css) === false, 'theme label must remain visible');
assert(/system:\s*'light',\s*light:\s*'dark',\s*dark:\s*'system'/.test(theme), 'theme cycle changed unexpectedly');
assert(/zk_personal_theme/.test(theme), 'theme cookie behavior missing');
console.log('theme-label-smoke: passed');
