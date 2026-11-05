'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const css = fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'views/layout.php'), 'utf8');
const dashboard = fs.readFileSync(path.join(root, 'views/pages/dashboard.php'), 'utf8');
const version = fs.readFileSync(path.join(root, 'src/Core/Version.php'), 'utf8');

assert.match(version, /VERSION\s*=\s*'1\.2\.31'/, 'application version must be 1.2.31');
const navStart = layout.indexOf('$nav = [');
const navEnd = layout.indexOf('];', navStart);
assert.ok(navStart >= 0 && navEnd > navStart, 'navigation array must exist');
const nav = layout.slice(navStart, navEnd);
const uploadIndex = nav.indexOf("'upload'");
const historyIndex = nav.indexOf("'history'");
const dashboardIndex = nav.indexOf("'dashboard'");
assert.ok(uploadIndex >= 0 && historyIndex > uploadIndex && dashboardIndex > historyIndex,
  'sidebar order must be Cargar reporte, Historial, Resumen');

assert.match(css, /\.content-grid,\s*\.updates-grid,\s*\.restore-guidance-grid,\s*\.update-guidance-grid,\s*\.technical-grid,\s*\.users-permission-help,\s*\.run-summary,\s*\.metric-grid\s*\{\s*align-items:\s*stretch;/,
  'shared card grids must stretch their items to the same row height');
assert.match(css, /\.content-grid\s*>\s*\.panel,\s*\.updates-grid\s*>\s*\.panel,\s*\.metric-grid\s*>\s*\.metric-card\s*\{\s*align-self:\s*stretch;/,
  'card panels and metric cards must stretch within their grid row');
assert.match(css, /\.metric-card\s*\{\s*min-height:\s*116px;\s*padding:\s*13px 15px;/,
  'dashboard metric cards must use compact dimensions');
assert.match(css, /\.dashboard-grid\s*>\s*\.panel\s*\{\s*padding:\s*18px 19px;/,
  'dashboard content panels must use compact spacing');
assert.match(dashboard, /class="metric-grid"/, 'dashboard metrics must remain present');
assert.match(dashboard, /Reportes recientes/, 'dashboard recent reports section must remain present');
console.log('card-grid-consistency-smoke: OK (equal-height card grids, compact dashboard and sidebar order)');
