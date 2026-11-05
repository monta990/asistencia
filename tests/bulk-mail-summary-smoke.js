const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');

const version = read('src/Core/Version.php');
assert.match(version, /VERSION\s*=\s*'1\.2\.31'/);
assert.match(version, /SCHEMA_TARGET\s*=\s*6/);

const migration = read('database/migrations/006_bulk_mail_batches.php');
assert.match(migration, /CREATE TABLE bulk_mail_batches/);
assert.match(migration, /ALTER TABLE mail_events ADD COLUMN batch_id TEXT NULL REFERENCES bulk_mail_batches/);
assert.match(migration, /FOREIGN KEY|REFERENCES report_runs/);

const users = read('src/Repositories/UserRepository.php');
assert.match(users, /SELECT id, name, username, email/);
assert.match(users, /function saveOwnEmail\(int \$id, string \$email\)/);
assert.match(users, /filter_var\(\$email, FILTER_VALIDATE_EMAIL\)/);
const usersView = read('views/pages/users.php');
assert.match(usersView, /name="email"/);
assert.match(usersView, /<th>CORREO<\/th>/);
const account = read('views/pages/account.php');
assert.match(account, /save_own_email/);

const repo = read('src/Repositories/ReportRepository.php');
assert.match(repo, /function createBulkMailBatch/);
assert.match(repo, /function claimBulkMailSummary/);
assert.match(repo, /function bulkMailEvents/);
assert.match(repo, /batch_id/);
const renderer = read('src/Services/BulkMailSummaryRenderer.php');
for (const label of ['Correos procesados', 'Aceptados por SMTP', 'Correos fallidos', 'Detalle por departamento']) {
  assert.ok(renderer.includes(label), `receipt missing: ${label}`);
}
assert.match(renderer, /htmlspecialchars/);
assert.match(renderer, /no confirma su lectura/);
const mailer = read('src/Services/MailerService.php');
assert.match(mailer, /function sendBulkSummary/);

const index = read('index.php');
for (const action of ['bulk_send_start', 'bulk_send_one', 'bulk_send_summary']) assert.ok(index.includes(action));
assert.match(index, /findBulkMailBatch\(\$batchId, \$authenticatedUserId, \$expectedRunId\)/);
assert.match(index, /saveOwnEmail/);
const app = read('assets/app.js');
assert.match(app, /action: 'bulk_send_summary'/);
assert.match(app, /client_issues/);

const theme = read('assets/theme.css');
assert.match(theme, /:root\[data-theme="light"\] \.btn-quiet/);
assert.match(theme, /background: #E2E9F1/);
assert.match(theme, /background: #D3DEE9/);
console.log('Bulk-mail receipt, account email, schema 6 and light-theme contrast smoke: OK');
