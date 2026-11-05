'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

const view = read('views/pages/departments.php');
const script = read('assets/app.js');
const css = read('assets/app.css');
const index = read('index.php');
const version = read('src/Core/Version.php');
const layout = read('views/layout.php');

assert.match(version, /VERSION\s*=\s*'1\.2\.31'/, 'application version must be 1.2.31');
for (const asset of ['theme.js', 'app.css', 'theme.css', 'app.js']) {
  assert.ok(layout.includes(`assets/${asset}?v=1.2.31`), `${asset} cache key must be bumped`);
}
assert.match(view, /data-department-create aria-haspopup="dialog" aria-controls="department-modal"/, 'department creation must use an accessible modal trigger');
assert.match(view, /data-department-edit aria-haspopup="dialog" aria-controls="department-modal"/, 'department editing must use an accessible table action');
assert.match(view, /id="department-modal"[^>]*data-open-on-load="<\?= \$editing \? 'true' : 'false' \?>"/, 'legacy edit links must still open the modal after page load');
assert.match(view, /role="dialog" aria-modal="true" aria-labelledby="department-modal-title"/, 'department modal must expose accessible dialog semantics');
assert.match(view, /name="action" value="save_department"/, 'modal must reuse the existing save_department endpoint');
assert.match(view, /data-operation-title="Guardando departamento"/, 'saving a department must show the existing operation overlay');
for (const field of ['report_label', 'display_name', 'responsible_email', 'recipients', 'position', 'enabled']) {
  assert.match(view, new RegExp(`name="${field}"`), `modal must retain ${field}`);
}
for (const field of ['data-department-report-label', 'data-department-display-name', 'data-department-responsible-email', 'data-department-recipients', 'data-department-position', 'data-department-enabled']) {
  assert.ok(view.includes(field), `modal is missing ${field}`);
}
assert.match(view, /data-department-needs-config/, 'automatically discovered department warning state must be carried to edit action');
assert.match(view, /Html::escape\(\$department\['recipient_emails_json'\] \?\? '\[\]'\)/, 'recipient edit payload must be safely JSON-encoded and escaped for the HTML attribute');
assert.match(index, /recipientsByDepartmentIds\(array_map\(/, 'all stored recipient rows must be loaded in one batch for edit prefill');
assert.match(index, /JSON_HEX_TAG \| JSON_HEX_APOS \| JSON_HEX_QUOT \| JSON_HEX_AMP/, 'recipient edit payload must use JSON HTML-safe flags');
assert.match(read('src/Repositories/DepartmentRepository.php'), /function recipientsByDepartmentIds\(array \$departmentIds\)/, 'repository must batch-load all stored recipients, including disabled ones');
assert.doesNotMatch(view, /department-form-panel|content-grid department-grid/, 'inline side form must be removed so the catalog uses the full width');
assert.match(view, /department-list-panel-wide/, 'department catalog must use the full content width');
assert.match(script, /document\.querySelectorAll\('\[data-department-edit\]'\)/, 'department edit actions must be wired');
for (const field of ['departmentId', 'departmentReportLabel', 'departmentDisplayName', 'departmentResponsibleEmail', 'departmentPosition', 'departmentEnabled', 'departmentNeedsConfig']) {
  assert.ok(script.includes(`button.dataset.${field}`), `edit dialog must preload ${field}`);
}
assert.match(script, /JSON\.parse\(button\.dataset\.departmentRecipients \|\| '\[\]'\)/, 'edit dialog must parse the safely encoded recipient list');
assert.match(script, /enabled\.checked = !isEditing \|\| String\(department\.enabled \?\? '1'\) === '1'/, 'enabled must be checked by default and restored when editing');
assert.match(script, /event\.key === 'Escape'[\s\S]*closeDepartmentModal/, 'Escape must close department modal');
assert.match(script, /event\.target === departmentModal[\s\S]*closeDepartmentModal/, 'clicking the backdrop must close department modal');
assert.match(script, /focusable[\s\S]*event\.shiftKey[\s\S]*last\.focus\(\)/, 'department modal must trap keyboard focus');
assert.match(css, /\.department-list-panel-wide\{width:100%;min-width:0\}/, 'department list must use full content width');
assert.match(css, /\.department-modal\{width:min\(900px,100%\)\}/, 'department modal must have a bounded responsive width');
assert.match(css, /@media\(max-width:560px\)[\s\S]*?\.department-modal-fields\{[^}]*grid-template-columns:minmax\(0,1fr\)/, 'department modal fields must stack on narrow screens');
console.log('department-modal-smoke: OK (full-width catalog, shared create/edit dialog, recipient preservation, accessibility and responsive layout)');
