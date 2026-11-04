'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const css = read('assets/app.css');
assert(!/\.actions-cell\s*\{[^}]*display\s*:\s*flex/i.test(css), 'table action cells must not become flex containers');
assert(/td\.actions-cell\s*\{[^}]*display\s*:\s*table-cell\s*!important/i.test(css), 'action cells must preserve native table-cell layout');
assert(/\.table-actions\s*\{[^}]*display\s*:\s*flex/i.test(css), 'flex layout must be applied to the inner action wrapper');

for (const file of ['views/pages/users.php', 'views/pages/departments.php', 'views/pages/history.php']) {
  const source = read(file);
  const actionCells = (source.match(/class="actions-cell"/g) || []).length;
  const actionWrappers = (source.match(/class="table-actions"/g) || []).length;
  assert.equal(actionWrappers, actionCells, `${file} must wrap every actions-cell in the shared action layout`);
}

const userPage = read('views/pages/users.php');
assert(userPage.includes('Deshabilitar') && userPage.includes('Habilitar'), 'user rows must label both account states explicitly');
assert(userPage.includes("(int) $row['id'] !== $currentUserId"), 'the current user must not see its own enable/disable action');
const repository = read('src/Repositories/UserRepository.php');
assert(repository.includes('$id === $currentUserId'), 'repository must also protect the current user from status changes');
console.log('OK: all table action cells preserve row borders and user toggles exclude the signed-in account.');
