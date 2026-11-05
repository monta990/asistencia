'use strict';
const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const assert = require('node:assert/strict');

const version = read('src/Core/Version.php');
assert(version.includes("VERSION = '1.2.31'") && version.includes('SCHEMA_TARGET = 6'), 'application/schema target must match RBAC release');

const migration = read('database/migrations/003_user_accounts_and_roles.php');
assert(migration.includes("ADD COLUMN name TEXT NOT NULL") && migration.includes('ADD COLUMN role TEXT NOT NULL') && migration.includes('ADD COLUMN is_active'), 'migration must add account fields without replacing existing tables');
assert(migration.includes("'administrador'") && migration.includes("'admin'"), 'migration must seed the existing administrator account');
assert(migration.includes('UPDATE report_runs SET created_by'), 'legacy executions must be attributed to the existing sole administrator');

const login = read('login.php');
assert(login.includes('name="username"') && login.includes('name="password"'), 'login must require username and password');
assert(!/name="username"[^>]*placeholder="administrador"/i.test(login), 'login must not suggest a default administrator username');
assert(!/name="username"[^>]*value="administrador"/i.test(login), 'login username must render empty');
assert(login.includes("Auth::attempt($pdo"), 'login must authenticate through the user store');
assert(login.includes('administrador'), 'legacy migration instructions must identify the administrator username');

const auth = read('src/Core/Auth.php');
assert(auth.includes('public static function canAccessPage'), 'central page authorization must exist');
assert(auth.includes("['upload', 'dashboard', 'history', 'departments', 'account']"), 'nominista may access operation, departments and own account only');
assert(auth.includes("$user['role'] ?? '') === 'admin'"), 'admin role must receive full access');
assert(auth.includes('is_active'), 'deactivated accounts must be rejected on later requests');

assert(auth.includes('change_own_password') === false, 'password change is handled by the controller and not by page authorization');

const index = read('index.php');
assert(index.includes("'save_user', 'set_user_active'"), 'user management writes must be admin-only');
assert(index.includes("case 'change_own_password'") && index.includes('changeOwnPassword'), 'authenticated users must be able to change their own password');
assert(index.includes("'rename_run_original_filename'"), 'history source filename edit action must be available');
assert(index.includes("'created_by' => (int) (Auth::user()['id'] ?? 0) ?: null"), 'new report executions must store the authenticated user id');
assert(index.includes('Auth::upgradeLegacySession($pdo)'), 'legacy admin session must be upgraded after migration');

const layout = read('views/layout.php');
assert(layout.includes("if (Auth::isAdmin())") && layout.includes("$nav['users']"), 'user-management navigation must only be rendered for admin');
assert(layout.includes('href="index.php?page=account"'), 'profile chip must link to the signed-in user account');
assert(layout.includes("$user['name']") && layout.includes('$roleLabel'), 'header must show the person name and role');

const usersPage = read('views/pages/users.php');
for (const name of ['person_name', 'username', 'email', 'password', 'role', 'set_user_active']) assert(usersPage.includes(name), `user manager missing ${name}`);
assert(usersPage.includes('Deshabilitar') && usersPage.includes('Habilitar'), 'user manager must allow explicit user disable/enable actions');
assert(usersPage.includes("(int) $row['id'] !== $currentUserId"), 'current signed-in user must not receive an enable/disable action');
assert(usersPage.includes('table-actions'), 'user row actions must use the shared aligned action wrapper');
assert(read('src/Repositories/UserRepository.php').includes('$id === $currentUserId'), 'repository must reject toggling the current signed-in account');

const history = read('views/pages/history.php');
assert(history.includes('GENERADO DEL ARCHIVO') && history.includes('Realizado por:'), 'history must show file source label and creator');
assert(history.includes('data-filename-edit-toggle') && history.includes('filename_base') && history.includes('.xlsx'), 'history must offer a filename editor while preserving xlsx extension');

const repo = read('src/Repositories/ReportRepository.php');
assert(repo.includes('renameRunOriginalFilename') && repo.includes("$newName = $baseName . '.xlsx'"), 'repository must preserve the original file extension during rename');

const accountPage = read('views/pages/account.php');
for (const field of ['current_password', 'new_password', 'confirm_password', 'change_own_password', 'save_own_email', 'name="email"']) assert(accountPage.includes(field), `self-service account screen missing ${field}`);
assert(accountPage.includes('minlength="8"'), 'own password change must enforce a minimum of eight characters');
assert(index.includes('saveOwnEmail') && index.includes("case 'save_own_email'"), 'each user must be able to manage their own notification email');
assert(read('database/migrations/001_initial.php').includes('email TEXT NOT NULL'), 'the initial schema already contains the user email column');
assert(!read('database/migrations/006_bulk_mail_batches.php').includes('ADD COLUMN email'), 'the batch migration must not duplicate the existing user email column');
console.log('OK: RBAC, user administration, own password change, creator attribution and safe source filename edit.');
