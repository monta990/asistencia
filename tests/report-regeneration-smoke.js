'use strict';

const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');
const assert = (condition, message) => { if (!condition) throw new Error(message); };

const splitter = read('src/Services/DepartmentSplitter.php');
const controller = read('index.php');
const repository = read('src/Repositories/ReportRepository.php');
const history = read('views/pages/history.php');
const backup = read('src/Services/ConfigurationBackup.php');
const resolver = read('src/Services/RunSourceResolver.php');
const cleanup = read('bin/zknet');
const version = read('src/Core/Version.php');

assert(version.includes("VERSION = '1.2.31'") && version.includes('SCHEMA_TARGET = 6'), 'release must be 1.2.31 and use SQLite schema 6');
assert(splitter.includes("'Reporte de asistencia'") && splitter.includes('addReportSubtitle') && splitter.includes('A3') && splitter.includes('Semana '), 'department workbook title and calculated ISO-period subtitle are missing');
assert(splitter.includes('addReportFooter') && splitter.includes('Realizado por:') && splitter.includes('Generado:'), 'department workbook author/date footer is missing');
assert(splitter.includes('embedBrandLogo') && splitter.includes('oneCellAnchor') && splitter.includes('160 / (int) $imageInfo[0]') && splitter.includes('zktime-logo-'), 'OOXML logo drawing support is missing');
assert(splitter.includes('$zip->open($xlsxPath)') && !splitter.includes('ZipArchive::RDWR'), 'logo embedding must open an existing XLSX in read/write mode without the undefined ZipArchive::RDWR constant');
assert(splitter.includes('logo_embedded_count'), 'splitter must report whether the logo was embedded');
assert(controller.includes("'regenerate_run_reports'") && controller.includes('copy($uploadedPath, $sourcePath)'), 'regeneration action and permanent private source copy are required');
assert(controller.includes('RunSourceResolver::resolve') && resolver.includes("$runId . '.xlsx'") && resolver.includes("'storage' => 'uploads'"), 'regeneration must resolve legacy uploads/<run-id>.xlsx as well as the new run-local source.xlsx');
assert(controller.includes('.source-copy-') && controller.includes('hash_equals($originalHash, $copyHash)') && controller.includes('rename($sourceCopyTemporary, $canonicalSource)'), 'a recovered legacy source must be copied, SHA-256 verified and atomically promoted to source.xlsx');
assert(cleanup.includes('SELECT id FROM report_runs') && cleanup.includes('$referencedRunUploads') && cleanup.includes('Never expire a file while its run remains in report_runs'), 'cleanup must protect original XLSX files associated with historical runs');
assert(controller.includes('$reports->replaceRunOutputs') && controller.includes('$generationPersisted = true'), 'new output paths must be committed before previous files are removed');
assert(controller.includes('.regeneration.lock') && controller.includes('LOCK_EX | LOCK_NB'), 'concurrent regeneration requests for the same run must be serialized');
assert(controller.includes('DepartmentSplitter::removeDirectory($generationDirectory)'), 'failed candidate generation must be cleaned without deleting current outputs');
assert(controller.includes("$run['created_by_name']") && controller.includes("$run['created_at']"), 'regeneration must have a safe author/date fallback from the original execution');
assert(controller.includes("'presentation.json'") && controller.includes("'created_by' => $presentation['created_by']") && controller.includes("'generated_on' => $presentation['generated_on']"), 'new runs must preserve the original presentation metadata for future regeneration');
assert(controller.includes("$storedPresentation['created_by']") && controller.includes("$storedPresentation['generated_on']"), 'regeneration must prefer the immutable original presentation metadata');
assert(history.includes('Regenerar reporte') && history.includes('data-confirm') && history.includes('name="_token"'), 'history must display a CSRF-protected regeneration action with confirmation');
assert(history.indexOf('Regenerar reporte') < history.indexOf('Enviar correo a todos') && history.indexOf('Enviar correo a todos') < history.indexOf('Descargar ZIP'), 'regeneration button must occupy the reserved action slot before bulk send and ZIP download');
assert(history.includes('$selectedRunSourceAvailable') && history.includes('Auth::isAdmin()') && history.includes('disabled title="Esta ejecución anterior no conserva el XLSX original'), 'regeneration must be restricted to administrators and unavailable runs must explain why the control is disabled');
assert(repository.includes('function replaceRunOutputs') && repository.includes('UPDATE report_department_files') && repository.includes('mail_attempt_count'), 'repository must replace file paths while retaining department-file IDs and mail audit');
assert(repository.includes("'not_requested'"), 'regenerated outputs must not be auto-sent');
assert(backup.includes('copyDirectory($sourceExports'), 'full backups must include exports and retained XLSX sources');

console.log('OK: report title/footer/logo, retained source, admin regeneration, atomic path switch and mail-audit preservation.');
