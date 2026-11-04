'use strict';
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const version = read('src/Core/Version.php');
const migration = read('database/migrations/005_account_lockout_and_sessions.php');
const auth = read('src/Core/Auth.php');
const users = read('src/Repositories/UserRepository.php');
const index = read('index.php');
const view = read('views/pages/users.php');

assert(version.includes("VERSION = '1.2.20'") && version.includes('SCHEMA_TARGET = 5') && version.includes('LOCKOUT_SCHEMA = 5'), 'release must target lockout schema 5');
for (const column of ['failed_login_attempts', 'locked_until', 'session_version']) assert(migration.includes(`ADD COLUMN ${column}`), `${column} migration missing`);
assert(migration.includes('CREATE INDEX idx_users_locked_until'), 'locked account lookup index missing');
assert(auth.includes('DEFAULT_MAX_ATTEMPTS = 5') && auth.includes('DEFAULT_LOCKOUT_MINUTES = 10'), 'defaults must be five attempts and ten minutes');
assert(auth.includes("$pdo->exec('BEGIN IMMEDIATE')") && auth.includes('$pdo->commit()'), 'failed login updates must serialize through an immediate SQLite transaction');
assert(auth.includes('if ($nextCount >= $maxAttempts)') && auth.includes('locked_until = :locked_until'), 'the threshold must set a finite account lock expiration');
assert(auth.includes('session_version = session_version + 1 WHERE id = :id AND is_active = 1'), 'locking an account must revoke its active sessions');
assert(auth.includes("SELECT setting_value FROM settings WHERE setting_key = 'security'"), 'lockout policy must be read from persisted settings');
assert(auth.includes('knownSessionVersion !== $sessionVersion') && auth.includes('locked_until'), 'each authenticated request must verify lock state and session version');
assert(auth.includes('AND session_version = :session_version') && auth.includes('AND (locked_until IS NULL OR locked_until <= :now)'), 'successful login must not race past a concurrent account lock or account change');
assert(auth.includes('AND session_version = :session_version') && auth.includes('AND (locked_until IS NULL OR locked_until <= :now)'), 'successful login must not race past a concurrent account lock or account change');
assert(users.includes('public function unlock(int $id): void'), 'administrator unlock operation missing');
assert(users.includes('session_version = session_version + 1 WHERE id = :id'), 'unlock must invalidate old user sessions');
assert(!users.includes("WHERE id = :id AND role = 'nominista'"), 'unlock must not exclude administrator accounts');
assert(index.includes("'save_login_policy', 'unlock_user'"), 'user security actions must be admin-only');
assert(index.includes("case 'save_login_policy':") && index.includes("case 'unlock_user':"), 'user security handlers missing');
assert(view.includes('name="max_login_attempts"') && view.includes('value="<?= $maxAttempts ?>"'), 'attempt policy field missing');
assert(view.includes('name="lockout_minutes"') && view.includes('value="<?= $lockoutMinutes ?>"'), 'duration policy field missing');
assert(view.includes('Desbloquear') && view.includes("$row['role'] === 'admin'"), 'user list must expose locked administrators to unlock');
console.log('OK: persistent account lockout, configurable policy, admin unlock and session revocation coverage.');

assert(auth.includes('$nextCount = (int) $currentCount + 1') && auth.includes('failed_login_attempts = :attempts'), 'locked users must retain the computed threshold count for the admin UI');
assert(auth.includes("$pdo->exec('BEGIN IMMEDIATE')") && auth.includes('if ($nextCount >= $maxAttempts)'), 'lockout must serialize attempts and enforce the configured threshold');
