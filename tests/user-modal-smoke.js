'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

const view = read('views/pages/users.php');
const script = read('assets/app.js');
const css = read('assets/app.css');
const version = read('src/Core/Version.php');
const layout = read('views/layout.php');

assert.match(version, /VERSION\s*=\s*'1\.2\.31'/, 'application version must be 1.2.31');
assert.match(layout, /assets\/app\.js\?v=1\.2\.31/, 'app.js cache key must be bumped');
assert.match(layout, /assets\/app\.css\?v=1\.2\.31/, 'app.css cache key must be bumped');
assert.match(view, /data-user-create aria-haspopup="dialog" aria-controls="user-modal"/, 'user creation must be launched from an accessible modal trigger');
assert.match(view, /data-user-edit aria-haspopup="dialog" aria-controls="user-modal"/, 'user editing must be launched from accessible table-row modal actions');
assert.match(view, /id="user-modal"[^>]*aria-hidden="true"/, 'user form must live in an initially hidden modal backdrop');
assert.match(view, /role="dialog" aria-modal="true"/, 'user modal must expose accessible dialog semantics');
assert.match(view, /data-user-id-field/, 'shared modal form must preserve the edited user id');
for (const field of ['data-user-person-name', 'data-user-username', 'data-user-email', 'data-user-password', 'data-user-role']) {
  assert.ok(view.includes(field), `modal is missing ${field}`);
}
assert.match(view, /name="action" value="save_user"/, 'modal must reuse the existing save_user endpoint');
assert.match(view, /data-operation-title="Guardando usuario"/, 'saving a user must show the processing overlay');
assert.doesNotMatch(view, /users-form-panel|users-layout/, 'the inline user form/grid must be removed so the list can use full width');
assert.match(view, /users-list-panel-wide/, 'user list panel must use the full-width layout');
assert.match(script, /document\.querySelectorAll\('\[data-user-edit\]'\)/, 'edit buttons outside the modal must be wired');
for (const field of ['userId', 'userName', 'userUsername', 'userEmail', 'userRole']) {
  assert.ok(script.includes(`button.dataset.${field}`), `edit dialog must preload ${field}`);
}
assert.match(script, /password\.required = !isEditing/, 'password must be required only when creating a user');
assert.match(script, /event\.key === 'Escape'[\s\S]*closeUserModal/, 'Escape must close the user modal');
assert.match(script, /event\.target === userModal[\s\S]*closeUserModal/, 'clicking the backdrop must close the user modal');
assert.match(script, /focusable[\s\S]*event\.shiftKey[\s\S]*last\.focus\(\)/, 'modal must trap keyboard focus');
assert.match(css, /\.users-list-panel\{width:100%;min-width:0\}/, 'user list must use the available content width');
assert.match(css, /\.user-modal\{width:min\(760px,100%\)/, 'user modal must have a bounded responsive width');
assert.match(css, /\.user-modal-fields\{display:grid;grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/, 'user modal fields must use two columns on desktop');
assert.match(css, /\.user-modal-fields\{grid-template-columns:minmax\(0,1fr\)/, 'user modal fields must stack on mobile');
assert.match(css, /\.login-policy-form\{display:grid;grid-template-columns:repeat\(3,minmax\(0,1fr\)\)/, 'session policy must use three balanced columns');
assert.match(css, /\.login-policy-form \.btn\{grid-column:1\/-1;justify-self:end/, 'session policy save button must occupy its own aligned row');
assert.match(css, /\.login-policy-form\{grid-template-columns:repeat\(2,minmax\(0,1fr\)\)/, 'session policy must adapt to tablet width');
assert.match(css, /\.login-policy-form\{grid-template-columns:minmax\(0,1fr\)\}/, 'session policy must adapt to narrow screens');
console.log('user-modal-smoke: OK (full-width list, shared create/edit dialog, keyboard access and balanced session policy layout)');
