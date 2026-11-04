'use strict';
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const upload = fs.readFileSync(path.join(root, 'views/pages/upload.php'), 'utf8');
const css = fs.readFileSync(path.join(root, 'assets/app.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'assets/app.js'), 'utf8');
const version = fs.readFileSync(path.join(root, 'src/Core/Version.php'), 'utf8');
const layout = fs.readFileSync(path.join(root, 'views/layout.php'), 'utf8');
function assert(ok, message) { if (!ok) throw new Error(message); }
assert(version.includes("VERSION = '1.2.20'"), 'version not bumped');
assert(upload.includes('Procesar reporte'), 'primary action label missing');
assert(!upload.includes('name="send_emails"'), 'automatic email option must be removed');
assert(!upload.includes('Distribución'), 'distribution section must be removed from upload screen');
assert(upload.includes('Abre el historial'), 'manual email guidance missing');
assert(css.includes('[hidden]{display:none!important}'), 'hidden attribute must override button display rules');
assert(js.includes('finally {') && js.includes('startButton.hidden = true') && js.includes('refreshButton.hidden = false'), 'bulk modal must restore controls after completion');
assert(layout.includes('assets/app.js?v=1.2.20'), 'app.js cache bust missing');
assert(layout.includes('assets/app.css?v=1.2.20'), 'app.css cache bust missing');
console.log('Upload UI simplification and bulk modal completion smoke: OK');

assert(upload.includes('missingResponsibleCount'), 'warning prioritizing department responsible email must be present');
assert(upload.includes('missingDeliveryCount'), 'missing delivery recipients warning must be present');
assert(upload.includes('Revisar departamentos') && upload.includes('index.php?page=departments'), 'main page warning must link to department settings');
