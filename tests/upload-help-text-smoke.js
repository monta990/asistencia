const fs = require('fs');
const path = require('path');
const root = path.resolve(__dirname, '..');
const view = fs.readFileSync(path.join(root, 'views/pages/upload.php'), 'utf8');
const expected = 'Al terminar, abre el historial para descargar los archivos de Excel por departamento, un ZIP con todos o enviarlos a los responsables de departamento, uno por uno o de manera masiva.';
if (!view.includes(expected)) throw new Error('La ayuda de descarga y distribución no contiene el texto actualizado.');
console.log('upload-help-text-smoke: OK');
