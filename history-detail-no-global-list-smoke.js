const fs = require('node:fs');
const path = require('node:path');
const file = path.join(__dirname, '..', 'views', 'pages', 'history.php');
const source = fs.readFileSync(file, 'utf8');
const listStart = source.indexOf('<h3>Todos los reportes</h3>');
const conditionStart = source.lastIndexOf('<?php if (!$selectedRun): ?>', listStart);
const conditionEnd = source.indexOf('<?php endif; ?>', listStart);
if (listStart < 0 || conditionStart < 0 || conditionEnd < 0 || conditionEnd < listStart) {
  throw new Error('La lista general debe estar condicionada para mostrarse solo sin un reporte seleccionado.');
}
const detailStart = source.indexOf('<section class="panel run-detail-panel">');
if (detailStart < 0 || conditionStart <= detailStart) {
  throw new Error('La lista general no debe quedar dentro del detalle de ejecución.');
}
if (!source.includes('href="index.php?page=history"')) {
  throw new Error('Debe conservarse el enlace para volver al historial general.');
}
console.log('OK: el detalle de una ejecución no repite Todos los reportes; el historial general permanece disponible.');
