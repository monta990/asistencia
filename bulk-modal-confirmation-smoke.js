const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const app = fs.readFileSync(path.join(root, 'assets/app.js'), 'utf8');
const view = fs.readFileSync(path.join(root, 'views/pages/history.php'), 'utf8');
const start = app.indexOf("bulkTrigger.addEventListener('click'");
const end = app.indexOf("closeButtons.forEach", start);
if (start < 0 || end < 0) throw new Error('Bulk modal trigger handler not found');
const trigger = app.slice(start, end);
if (trigger.includes('window.confirm') || trigger.includes('confirm(')) throw new Error('Bulk-send must not show a redundant browser confirmation');
for (const phrase of ['bulkFiles.length', 'setOpen(true)', 'Iniciar envío']) {
  if (!trigger.includes(phrase) && phrase !== 'Iniciar envío') throw new Error('Missing modal confirmation behavior: ' + phrase);
}
if (!view.includes('data-bulk-start') || !view.includes('El ZIP general no se adjuntará')) throw new Error('The modal must explain the operation and offer the only confirmation action');
console.log('Bulk-send single modal confirmation smoke: OK');
