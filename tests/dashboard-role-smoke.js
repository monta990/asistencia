'use strict';
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '..');
const read = (file) => fs.readFileSync(path.join(root, file), 'utf8');
const dashboard = read('views/pages/dashboard.php');
const auth = read('src/Core/Auth.php');

assert(dashboard.includes('use ZkTimeNet\\Core\\Auth;'), 'dashboard must use centralized role authorization');
assert(dashboard.includes('$isAdmin = Auth::isAdmin();'), 'dashboard must derive administrator state from authenticated role');
assert(dashboard.includes('<?php if ($isAdmin): ?>'), 'restricted setup links must be rendered only for administrators');
const adminOnlyBlock = dashboard.split('<?php if ($isAdmin): ?>')[1]?.split('<?php endif; ?>')[0] ?? '';
assert(adminOnlyBlock.includes('Conexión SMTP') && adminOnlyBlock.includes('Base de datos local'), 'SMTP and database setup must be inside the administrator-only block');
assert(dashboard.indexOf('Conexión SMTP') > dashboard.indexOf('<?php if ($isAdmin): ?>'), 'SMTP details must not render before the administrator check');
assert(dashboard.indexOf('Base de datos local') > dashboard.indexOf('<?php if ($isAdmin): ?>'), 'database details must not render before the administrator check');
assert(dashboard.includes('Destinatarios por departamento'), 'department recipient setup must remain visible to noministas');
assert(auth.includes('public static function isAdmin()'), 'role check must use the central Auth service');

const layout = read('views/layout.php');
assert(layout.includes("if (Auth::isAdmin())"), 'administrative navigation must remain role-gated');
assert(layout.includes("$nav['mail']") && layout.includes("$nav['updates']"), 'SMTP and updates navigation remain admin-only');
console.log('OK: dashboard hides SMTP/database setup for noministas while preserving department configuration.');
