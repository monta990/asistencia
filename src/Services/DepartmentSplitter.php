<?php
declare(strict_types=1);

namespace ZkTimeNet\Services;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use FilesystemIterator;
use ZipArchive;
use ZkTimeNet\Repositories\DepartmentRepository;

/**
 * Splitter OOXML nativo para reportes XLSX exportados por ZKTimeNet.
 *
 * No requiere Composer ni bibliotecas PHP de terceros. Conserva estilos, anchos,
 * imágenes, relaciones y celdas combinadas del paquete XLSX original. Solo deja
 * en cada archivo las filas de cabecera y el bloque del departamento solicitado.
 * La tabla sharedStrings también se reduce para no filtrar nombres de otros
 * departamentos dentro del ZIP de un fragmento.
 */
final class DepartmentSplitter
{
    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const NS_PACKAGE_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    private const NS_DRAWING = 'http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing';
    private const NS_DRAWING_MAIN = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    private const NS_CONTENT_TYPES = 'http://schemas.openxmlformats.org/package/2006/content-types';
    private const LABEL_COLUMN = 'A';
    private const NAME_COLUMN = 'D';
    private const START_DATE_COLUMN = 'D';
    private const END_DATE_COLUMN = 'J';
    private const HEADER_LAST_ROW = 4;
    private const MAX_ARCHIVE_ENTRIES = 1000;
    private const MAX_EXPANDED_BYTES = 200_000_000;

    public function __construct(private readonly DepartmentRepository $departments) {}

    /**
     * @param array{created_by?:string,generated_on?:string,logo?:array{path:string,mime:string}|null} $presentation
     * @return array{zip_path:string,zip_name:string,files:list<array>,weeks:list<string>,start_date:string,end_date:string,iso_year:string,logo_embedded_count:int,workspace:string}
     */
    public function split(string $inputFile, string $outputDirectory, array $presentation = []): array
    {
        $presentation = [
            'created_by' => trim((string) ($presentation['created_by'] ?? '')),
            'generated_on' => trim((string) ($presentation['generated_on'] ?? date('d/m/Y'))),
            'logo' => is_array($presentation['logo'] ?? null) ? $presentation['logo'] : null,
        ];
        if (!is_file($inputFile) || !is_readable($inputFile)) {
            throw new \RuntimeException('No se encuentra el reporte o el servidor no puede leerlo.');
        }
        if (strtolower(pathinfo($inputFile, PATHINFO_EXTENSION)) !== 'xlsx') {
            throw new \RuntimeException('El reporte debe estar en formato XLSX.');
        }
        if (!class_exists(ZipArchive::class) || !class_exists(DOMDocument::class)) {
            throw new \RuntimeException('PHP necesita las extensiones zip y dom para procesar archivos XLSX.');
        }
        if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0700, true) && !is_dir($outputDirectory)) {
            throw new \RuntimeException('No se pudo preparar el directorio privado de salida.');
        }

        $source = new ZipArchive();
        if ($source->open($inputFile, ZipArchive::RDONLY) !== true) {
            throw new \RuntimeException('El archivo no es un paquete XLSX válido o está dañado.');
        }
        $workspace = $outputDirectory . DIRECTORY_SEPARATOR . 'work-' . bin2hex(random_bytes(6));
        if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
            $source->close();
            throw new \RuntimeException('No se pudo crear el área temporal de procesamiento.');
        }

        $zip = null;
        try {
            $this->validateArchive($source);
            $sheetPath = $this->resolveWorksheetPath($source);
            $sharedStringsPath = $this->resolveSharedStringsPath($source);
            $sheetXml = $source->getFromName($sheetPath);
            if (!is_string($sheetXml) || $sheetXml === '') {
                throw new \RuntimeException('No se pudo leer la hoja de cálculo principal del reporte.');
            }
            $sheetDocument = self::loadXml($sheetXml, 'La hoja XLSX no contiene XML válido.');
            $sharedDocument = null;
            $sharedItems = [];
            if ($sharedStringsPath !== null) {
                $sharedXml = $source->getFromName($sharedStringsPath);
                if (is_string($sharedXml) && $sharedXml !== '') {
                    $sharedDocument = self::loadXml($sharedXml, 'El catálogo de textos del XLSX no contiene XML válido.');
                    $sharedItems = $this->readSharedStrings($sharedDocument);
                }
            }

            $sheetData = $this->sheetDataNode($sheetDocument);
            $rows = $this->indexRows($sheetData);
            $blocks = $this->findDepartmentBlocks($rows, $sharedItems);
            $lastDepartmentStart = $blocks[array_key_last($blocks)]['start'];
            $reportEndRow = $this->findReportEndRow($rows, $sharedItems, $lastDepartmentStart);

            $zipPath = $workspace . DIRECTORY_SEPARATOR . 'departamentos.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('No se pudo crear el ZIP de salida.');
            }

            $usedNames = [];
            $files = [];
            $logoEmbeddedCount = 0;
            $weeks = [];
            $startDates = [];
            $endDates = [];

            foreach ($blocks as $index => $block) {
                $startRow = $block['start'];
                $endRow = isset($blocks[$index + 1]) ? $blocks[$index + 1]['start'] - 1 : $reportEndRow;
                if ($endRow < $startRow) {
                    throw new \RuntimeException('El bloque de ' . $block['department']['display_name'] . ' tiene un rango de filas inválido.');
                }

                $week = $block['start_date']->format('W');
                $isoYear = $block['start_date']->format('o');
                $weeks[$week] = true;
                $startDates[] = $block['start_date'];
                $endDates[] = $block['end_date'];

                $baseName = 'Semana' . $week . '_' . $this->safeFilePart((string) $block['department']['display_name']) . '.xlsx';
                $fileName = $this->uniqueFileName($baseName, $usedNames);
                $xlsxPath = $workspace . DIRECTORY_SEPARATOR . $fileName;

                $departmentPresentation = [
                    'week' => $week,
                    'start_date' => $block['start_date']->format('d/m/Y'),
                    'end_date' => $block['end_date']->format('d/m/Y'),
                    'created_by' => $presentation['created_by'],
                    'generated_on' => $presentation['generated_on'],
                ];
                [$departmentSheetXml, $departmentSharedXml, $departmentStylesXml] = $this->buildDepartmentParts(
                    $sheetXml,
                    $sharedDocument,
                    $rows,
                    $sharedItems,
                    $startRow,
                    $endRow,
                    $departmentPresentation,
                    $source->getFromName('xl/styles.xml') ?: null
                );
                $this->writeDepartmentWorkbook(
                    $source,
                    $xlsxPath,
                    $sheetPath,
                    $departmentSheetXml,
                    $sharedStringsPath,
                    $departmentSharedXml,
                    $departmentStylesXml,
                    $fileName
                );
                if (is_array($presentation['logo']) && $this->embedBrandLogo($xlsxPath, $sheetPath, $presentation['logo'])) {
                    $logoEmbeddedCount++;
                }

                if (!$zip->addFile($xlsxPath, $fileName)) {
                    throw new \RuntimeException('No se pudo agregar ' . $fileName . ' al ZIP.');
                }
                $files[] = [
                    'department_id' => (int) $block['department']['id'],
                    'report_label' => (string) $block['department']['report_label'],
                    'department_name' => (string) $block['department']['display_name'],
                    'department_enabled' => (int) ($block['department']['enabled'] ?? 0) === 1,
                    'needs_configuration' => (int) ($block['department']['needs_configuration'] ?? 0) === 1,
                    'newly_discovered' => (bool) ($block['newly_discovered'] ?? false),
                    'file_name' => $fileName,
                    'absolute_path' => $xlsxPath,
                    'week' => $week,
                    'iso_year' => $isoYear,
                    'start_date' => $block['start_date']->format('Y-m-d'),
                    'end_date' => $block['end_date']->format('Y-m-d'),
                ];
            }

            if (!$zip->close()) {
                throw new \RuntimeException('No se pudo cerrar correctamente el ZIP.');
            }
            $zip = null;
            $source->close();

            $weekList = array_keys($weeks);
            sort($weekList, SORT_NUMERIC);
            $zipName = count($weekList) === 1
                ? 'Semana' . $weekList[0] . '_Departamentos.zip'
                : 'Semanas_' . implode('-', $weekList) . '_Departamentos.zip';
            $finalZip = $outputDirectory . DIRECTORY_SEPARATOR . $zipName;
            if (!rename($zipPath, $finalZip)) {
                throw new \RuntimeException('Los archivos se generaron, pero no se pudo finalizar el ZIP.');
            }
            foreach ($files as &$file) {
                $file['relative_path'] = str_replace(dirname($outputDirectory) . DIRECTORY_SEPARATOR, '', $file['absolute_path']);
            }
            unset($file);

            usort($startDates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
            usort($endDates, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
            return [
                'zip_path' => $finalZip,
                'zip_name' => $zipName,
                'files' => $files,
                'weeks' => $weekList,
                'start_date' => $startDates[0]->format('Y-m-d'),
                'end_date' => $endDates[array_key_last($endDates)]->format('Y-m-d'),
                'iso_year' => $startDates[0]->format('o'),
                'logo_embedded_count' => $logoEmbeddedCount,
                'workspace' => $workspace,
            ];
        } catch (\Throwable $exception) {
            if ($zip instanceof ZipArchive) {
                @$zip->close();
            }
            $source->close();
            self::removeDirectory($workspace);
            throw $exception;
        }
    }

    private function validateArchive(ZipArchive $zip): void
    {
        if ($zip->numFiles < 4 || $zip->numFiles > self::MAX_ARCHIVE_ENTRIES) {
            throw new \RuntimeException('El XLSX contiene una cantidad inesperada de componentes.');
        }
        $expanded = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!is_array($stat)) {
                throw new \RuntimeException('No se pudo inspeccionar un componente interno del XLSX.');
            }
            $name = (string) ($stat['name'] ?? '');
            if (str_contains($name, '../') || str_starts_with($name, '/') || str_contains($name, "\\")) {
                throw new \RuntimeException('El XLSX contiene una ruta interna no permitida.');
            }
            $expanded += (int) ($stat['size'] ?? 0);
            if ($expanded > self::MAX_EXPANDED_BYTES) {
                throw new \RuntimeException('El contenido expandido del XLSX supera el límite de seguridad.');
            }
        }
    }

    private function resolveWorksheetPath(ZipArchive $zip): string
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($workbookXml) || !is_string($relationsXml)) {
            throw new \RuntimeException('El archivo no tiene la estructura de un libro XLSX.');
        }
        $workbook = self::loadXml($workbookXml, 'No se pudo leer workbook.xml.');
        $relations = self::loadXml($relationsXml, 'No se pudo leer workbook.xml.rels.');
        $xpath = new DOMXPath($workbook);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $sheet = $xpath->query('/m:workbook/m:sheets/m:sheet[1]')->item(0);
        if (!$sheet instanceof DOMElement) {
            throw new \RuntimeException('El libro no contiene hojas de cálculo.');
        }
        $relationId = $sheet->getAttributeNS(self::NS_REL, 'id');
        $relXpath = new DOMXPath($relations);
        $relXpath->registerNamespace('p', self::NS_PACKAGE_REL);
        foreach ($relXpath->query('/p:Relationships/p:Relationship') ?: [] as $relation) {
            if ($relation instanceof DOMElement && $relation->getAttribute('Id') === $relationId) {
                $target = $relation->getAttribute('Target');
                if (!str_contains($relation->getAttribute('Type'), '/worksheet')) {
                    break;
                }
                return self::normalizeZipPath($target, 'xl');
            }
        }
        throw new \RuntimeException('No se pudo resolver la primera hoja del libro XLSX.');
    }

    private function resolveSharedStringsPath(ZipArchive $zip): ?string
    {
        $relationsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if (!is_string($relationsXml)) {
            return $zip->locateName('xl/sharedStrings.xml') !== false ? 'xl/sharedStrings.xml' : null;
        }
        $relations = self::loadXml($relationsXml, 'No se pudo leer la relación del catálogo de textos.');
        $xpath = new DOMXPath($relations);
        $xpath->registerNamespace('p', self::NS_PACKAGE_REL);
        foreach ($xpath->query('/p:Relationships/p:Relationship') ?: [] as $relation) {
            if ($relation instanceof DOMElement && str_contains($relation->getAttribute('Type'), '/sharedStrings')) {
                return self::normalizeZipPath($relation->getAttribute('Target'), 'xl');
            }
        }
        return $zip->locateName('xl/sharedStrings.xml') !== false ? 'xl/sharedStrings.xml' : null;
    }

    private static function normalizeZipPath(string $target, string $base): string
    {
        $path = str_starts_with($target, '/') ? ltrim($target, '/') : $base . '/' . $target;
        $parts = [];
        foreach (explode('/', str_replace('\\', '/', $path)) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts === []) {
                    throw new \RuntimeException('Relación XLSX fuera del paquete.');
                }
                array_pop($parts);
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }

    /** @return list<DOMElement> */
    private function readSharedStrings(DOMDocument $document): array
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $result = [];
        foreach ($xpath->query('/m:sst/m:si') ?: [] as $item) {
            if ($item instanceof DOMElement) {
                $result[] = $item;
            }
        }
        return $result;
    }

    /** @return array<int,DOMElement> */
    private function indexRows(DOMElement $sheetData): array
    {
        $rows = [];
        foreach ($sheetData->childNodes as $node) {
            if (!$node instanceof DOMElement || $node->localName !== 'row') {
                continue;
            }
            $number = filter_var($node->getAttribute('r'), FILTER_VALIDATE_INT);
            if (!is_int($number) || $number < 1 || isset($rows[$number])) {
                throw new \RuntimeException('La hoja contiene filas con numeración inválida o duplicada.');
            }
            $rows[$number] = $node;
        }
        ksort($rows, SORT_NUMERIC);
        return $rows;
    }

    private function sheetDataNode(DOMDocument $document): DOMElement
    {
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $node = $xpath->query('/m:worksheet/m:sheetData')->item(0);
        if (!$node instanceof DOMElement) {
            throw new \RuntimeException('La hoja no contiene sheetData.');
        }
        return $node;
    }

    private function findDepartmentBlocks(array $rows, array $sharedItems): array
    {
        $blocks = [];
        foreach ($rows as $rowNumber => $rowNode) {
            $label = self::normalize($this->cellText($rowNode, self::LABEL_COLUMN, $sharedItems));
            if ($label !== 'departamento') {
                continue;
            }
            $reportLabel = trim($this->cellText($rowNode, self::NAME_COLUMN, $sharedItems));
            if ($reportLabel === '') {
                throw new \RuntimeException('La fila ' . $rowNumber . ' contiene Departamento, pero el nombre está vacío.');
            }
            $discovery = $this->departments->ensureDiscovered($reportLabel);
            $department = $discovery['department'];
            $dateRow = $rows[$rowNumber + 1] ?? null;
            if (!$dateRow instanceof DOMElement) {
                throw new \RuntimeException('No se encontró la fila de fechas para ' . $reportLabel . '.');
            }
            $startDate = $this->parseDate($this->cellText($dateRow, self::START_DATE_COLUMN, $sharedItems), $rowNumber + 1);
            $endDate = $this->parseDate($this->cellText($dateRow, self::END_DATE_COLUMN, $sharedItems), $rowNumber + 1);
            if ($endDate < $startDate) {
                throw new \RuntimeException('La fecha final es anterior a la inicial para el departamento ' . $reportLabel . '.');
            }
            $blocks[] = ['start' => $rowNumber, 'department' => $department, 'newly_discovered' => (bool) $discovery['created'], 'start_date' => $startDate, 'end_date' => $endDate];
        }
        if ($blocks === []) {
            throw new \RuntimeException('No se encontraron secciones con la etiqueta Departamento en la columna A.');
        }
        return $blocks;
    }

    private function findReportEndRow(array $rows, array $sharedItems, int $lastDepartmentStart): int
    {
        $last = (int) array_key_last($rows);
        foreach ($rows as $rowNumber => $rowNode) {
            if ($rowNumber < $lastDepartmentStart) {
                continue;
            }
            $columnB = self::normalize($this->cellText($rowNode, 'B', $sharedItems));
            $columnK = self::normalize($this->cellText($rowNode, 'K', $sharedItems));
            if (str_starts_with($columnB, 'fecha / hora:') || preg_match('/^page\s*:/i', $columnK) === 1) {
                return max($lastDepartmentStart, $rowNumber - 1);
            }
        }
        return $last;
    }

    private function cellText(DOMElement $row, string $column, array $sharedItems): string
    {
        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }
            if (preg_match('/^([A-Z]+)\$?\d+$/i', $cell->getAttribute('r'), $match) !== 1 || strtoupper($match[1]) !== $column) {
                continue;
            }
            $type = $cell->getAttribute('t');
            if ($type === 'inlineStr') {
                $inline = $cell->getElementsByTagNameNS(self::NS_MAIN, 'is')->item(0);
                return $inline instanceof DOMNode ? $inline->textContent : '';
            }
            $valueNode = $cell->getElementsByTagNameNS(self::NS_MAIN, 'v')->item(0);
            if (!$valueNode instanceof DOMNode) {
                return '';
            }
            $value = trim($valueNode->textContent);
            if ($type === 's') {
                $index = filter_var($value, FILTER_VALIDATE_INT);
                $shared = is_int($index) ? ($sharedItems[$index] ?? null) : null;
                return $shared instanceof DOMElement ? $this->sharedStringText($shared) : '';
            }
            return $value;
        }
        return '';
    }

    private function sharedStringText(DOMElement $item): string
    {
        $text = '';
        foreach ($item->getElementsByTagNameNS(self::NS_MAIN, 't') as $node) {
            $text .= $node->textContent;
        }
        return $text;
    }

    private function parseDate(string $value, int $row): DateTimeImmutable
    {
        $text = trim($value);
        foreach (['!d/m/Y', '!d-m-Y', '!Y-m-d', '!m/d/Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $text);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date->setTime(0, 0);
            }
        }
        if (is_numeric($text) && (float) $text > 0) {
            $days = (int) floor((float) $text);
            return (new DateTimeImmutable('1899-12-30'))->modify('+' . $days . ' days')->setTime(0, 0);
        }
        throw new \RuntimeException('No se pudo interpretar la fecha de la fila ' . $row . ': ' . ($text !== '' ? $text : '(vacía)'));
    }

    /** @return array{0:string,1:?string,2:?string} */
    private function buildDepartmentParts(
        string $sourceSheetXml,
        ?DOMDocument $sourceSharedDocument,
        array $rows,
        array $sourceSharedItems,
        int $startRow,
        int $endRow,
        array $presentation,
        ?string $sourceStylesXml
    ): array
    {
        $sheet = self::loadXml($sourceSheetXml, 'No se pudo volver a cargar la hoja XLSX.');
        $sheetData = $this->sheetDataNode($sheet);
        $rowMap = [];
        for ($row = 1; $row <= self::HEADER_LAST_ROW; $row++) {
            $rowMap[$row] = $row;
        }
        $nextOutputRow = self::HEADER_LAST_ROW + 1;
        for ($row = $startRow; $row <= $endRow; $row++) {
            if (isset($rows[$row]) && !$this->isSourceFooterRow($rows[$row], $sourceSharedItems)) {
                $rowMap[$row] = $nextOutputRow++;
            }
        }

        $titleCellReference = $this->findReportTitleCell($rows, $sourceSharedItems);
        $hasFormula = false;
        $sharedMap = [];
        $sharedOrder = [];
        $sharedReferenceCount = 0;
        $highestColumn = 1;
        $maxTurnoCharacters = 0;
        $insideAttendanceTable = false;
        foreach (iterator_to_array($sheetData->childNodes) as $rowNode) {
            if (!$rowNode instanceof DOMElement || $rowNode->localName !== 'row') {
                continue;
            }
            $oldRow = (int) $rowNode->getAttribute('r');
            if (!isset($rowMap[$oldRow])) {
                $sheetData->removeChild($rowNode);
                continue;
            }
            $newRow = $rowMap[$oldRow];
            $rowNode->setAttribute('r', (string) $newRow);
            // La fila 3 se reserva para el subtítulo nuevo; se descartan sus celdas
            // antes de contar sharedStrings para no conservar texto que ya no se usa.
            if ($oldRow === 3) {
                foreach (iterator_to_array($rowNode->childNodes) as $sourceCell) {
                    if ($sourceCell instanceof DOMElement && $sourceCell->localName === 'c') {
                        $rowNode->removeChild($sourceCell);
                    }
                }
                continue;
            }
            // El reporte repite un encabezado ID / Fecha / Turno para cada persona.
            // Solo medimos filas dentro de esas tablas para no confundir el nombre del
            // empleado (también aparece en D) con el horario de la columna Turno.
            $rowLabel = self::normalize($this->cellText($rowNode, 'A', $sourceSharedItems));
            $columnDText = trim($this->cellText($rowNode, 'D', $sourceSharedItems));
            if ($rowLabel === 'nombre') {
                $insideAttendanceTable = false;
            } elseif ($rowLabel === 'id' && self::normalize($columnDText) === 'turno') {
                $insideAttendanceTable = true;
            } elseif ($rowLabel === 'horas totales') {
                $insideAttendanceTable = false;
            } elseif ($insideAttendanceTable && $columnDText !== '') {
                $maxTurnoCharacters = max($maxTurnoCharacters, strlen($columnDText));
            }
            foreach ($rowNode->childNodes as $cell) {
                if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }
                $reference = $cell->getAttribute('r');
                if (preg_match('/^(\$?[A-Z]{1,3}\$?)(\d+)$/i', $reference, $match) !== 1) {
                    throw new \RuntimeException('La hoja contiene una referencia de celda inválida: ' . $reference);
                }
                $column = strtoupper(str_replace('$', '', $match[1]));
                $highestColumn = max($highestColumn, self::columnToNumber($column));
                $cell->setAttribute('r', $match[1] . $newRow);

                if ($oldRow === 1 && $titleCellReference !== null && strtoupper($reference) === strtoupper($titleCellReference)) {
                    $this->setInlineStringCell($sheet, $rowNode, $reference, 'Reporte de asistencia');
                    continue;
                }

                if ($cell->getElementsByTagNameNS(self::NS_MAIN, 'f')->length > 0) {
                    $hasFormula = true;
                }
                if ($cell->getAttribute('t') === 's') {
                    $valueNode = $cell->getElementsByTagNameNS(self::NS_MAIN, 'v')->item(0);
                    if (!$valueNode instanceof DOMNode) {
                        throw new \RuntimeException('Se encontró una referencia a texto compartido sin índice.');
                    }
                    $oldIndex = filter_var(trim($valueNode->textContent), FILTER_VALIDATE_INT);
                    if (!is_int($oldIndex) || !isset($sourceSharedItems[$oldIndex])) {
                        throw new \RuntimeException('El XLSX contiene un índice de texto compartido fuera de rango.');
                    }
                    if (!isset($sharedMap[$oldIndex])) {
                        $sharedMap[$oldIndex] = count($sharedOrder);
                        $sharedOrder[] = $oldIndex;
                    }
                    $valueNode->textContent = (string) $sharedMap[$oldIndex];
                    $sharedReferenceCount++;
                }
            }
        }
        if ($hasFormula) {
            throw new \RuntimeException('Este reporte contiene fórmulas y no se modificará para evitar alterar sus referencias. Exporta el reporte de ZKTimeNet como valores.');
        }

        if ($maxTurnoCharacters > 0) {
            // Unidad de ancho OOXML aproximada a caracteres. Se limita para que
            // un turno excepcionalmente largo no desplace toda la hoja.
            $turnoWidth = min(32.0, max(13.71, ($maxTurnoCharacters * 1.12) + 1.5));
            $this->adjustColumnWidth($sheet, $sheetData, 4, $turnoWidth);
        }

        $xpath = new DOMXPath($sheet);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $mergeNodes = $xpath->query('/m:worksheet/m:mergeCells/m:mergeCell');
        if ($mergeNodes !== false) {
            foreach (iterator_to_array($mergeNodes) as $mergeNode) {
                if (!$mergeNode instanceof DOMElement) {
                    continue;
                }
                $ref = $mergeNode->getAttribute('ref');
                if (preg_match('/^([A-Z]{1,3})(\d+):([A-Z]{1,3})(\d+)$/i', $ref, $match) !== 1) {
                    $mergeNode->parentNode?->removeChild($mergeNode);
                    continue;
                }
                $firstRow = (int) $match[2];
                $lastRow = (int) $match[4];
                if (!isset($rowMap[$firstRow], $rowMap[$lastRow])) {
                    $mergeNode->parentNode?->removeChild($mergeNode);
                    continue;
                }
                $mergeNode->setAttribute('ref', strtoupper($match[1]) . $rowMap[$firstRow] . ':' . strtoupper($match[3]) . $rowMap[$lastRow]);
            }
            $mergeContainer = $xpath->query('/m:worksheet/m:mergeCells')->item(0);
            if ($mergeContainer instanceof DOMElement) {
                $remainingMerges = $xpath->query('/m:worksheet/m:mergeCells/m:mergeCell');
                $mergeContainer->setAttribute('count', (string) ($remainingMerges?->length ?? 0));
            }
        }

        foreach (['autoFilter', 'conditionalFormatting', 'dataValidations', 'hyperlinks', 'rowBreaks'] as $unsupported) {
            if ($xpath->query('/m:worksheet/m:' . $unsupported)->length > 0) {
                throw new \RuntimeException('El XLSX contiene una estructura de hoja no soportada de forma segura: ' . $unsupported . '.');
            }
        }

        $lastMappedRow = max($rowMap);
        [$stylesXml, $subtitleStyle, $footerStyle] = $this->buildPresentationStyles($sourceStylesXml);
        $subtitle = 'Semana ' . (string) ($presentation['week'] ?? '')
            . ' · ' . (string) ($presentation['start_date'] ?? '')
            . ' al ' . (string) ($presentation['end_date'] ?? '');
        $this->addReportSubtitle($sheet, $sheetData, $subtitle, $subtitleStyle);
        // Conserva una fila de separación entre el total del departamento y el pie.
        $footerRow = $lastMappedRow + 2;
        $this->addReportFooter(
            $sheet,
            $sheetData,
            $footerRow,
            (string) ($presentation['created_by'] ?? ''),
            (string) ($presentation['generated_on'] ?? date('d/m/Y')),
            $footerStyle
        );
        // El formato de reporte estándar llega hasta la columna N; las celdas pueden
        // estar vacías, pero las combinaciones A:N deben seguir dentro de la dimensión.
        $highestColumn = max($highestColumn, self::columnToNumber('N'));

        $dimension = $xpath->query('/m:worksheet/m:dimension')->item(0);
        if ($dimension instanceof DOMElement) {
            $dimension->setAttribute('ref', 'A1:' . self::numberToColumn($highestColumn) . $footerRow);
        }

        $sheetXml = $sheet->saveXML();
        if (!is_string($sheetXml)) {
            throw new \RuntimeException('No se pudo serializar la hoja de cálculo del departamento.');
        }

        $sharedXml = null;
        if ($sourceSharedDocument instanceof DOMDocument) {
            $newShared = $this->buildSharedStringsDocument($sourceSharedDocument, $sourceSharedItems, $sharedOrder, $sharedReferenceCount);
            $sharedXml = $newShared->saveXML();
            if (!is_string($sharedXml)) {
                throw new \RuntimeException('No se pudo serializar el catálogo de textos del departamento.');
            }
        }
        return [$sheetXml, $sharedXml, $stylesXml];
    }

    private function findReportTitleCell(array $rows, array $sharedItems): ?string
    {
        foreach ($rows as $rowNumber => $rowNode) {
            if ($rowNumber > self::HEADER_LAST_ROW) {
                break;
            }
            foreach ($rowNode->childNodes as $cell) {
                if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                    continue;
                }
                $reference = strtoupper($cell->getAttribute('r'));
                if (preg_match('/^([A-Z]{1,3})\d+$/', $reference, $match) !== 1) {
                    continue;
                }
                if (self::normalize($this->cellText($rowNode, $match[1], $sharedItems)) === 'reporte general') {
                    return $reference;
                }
            }
        }
        return null;
    }

    private function isSourceFooterRow(DOMElement $row, array $sharedItems): bool
    {
        foreach ($row->childNodes as $cell) {
            if (!$cell instanceof DOMElement || $cell->localName !== 'c') {
                continue;
            }
            $reference = strtoupper($cell->getAttribute('r'));
            if (preg_match('/^([A-Z]{1,3})\d+$/', $reference, $match) !== 1) {
                continue;
            }
            $value = self::normalize($this->cellText($row, $match[1], $sharedItems));
            if (str_starts_with($value, 'realizado por') || $value === 'generado:' || $value === 'generado') {
                return true;
            }
        }
        return false;
    }

    /**
     * Crea dos estilos pequeños y discretos para el subtítulo y el pie. Si el XLSX
     * no trae styles.xml se usa el estilo estándar, sin añadir una relación nueva.
     *
     * @return array{0:?string,1:int,2:int}
     */
    private function buildPresentationStyles(?string $sourceStylesXml): array
    {
        if (!is_string($sourceStylesXml) || $sourceStylesXml === '') {
            return [null, 0, 0];
        }
        $styles = self::loadXml($sourceStylesXml, 'No se pudieron preparar los estilos del reporte.');
        $xpath = new DOMXPath($styles);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $fonts = $xpath->query('/m:styleSheet/m:fonts')->item(0);
        $cellXfs = $xpath->query('/m:styleSheet/m:cellXfs')->item(0);
        if (!$fonts instanceof DOMElement || !$cellXfs instanceof DOMElement) {
            return [$sourceStylesXml, 0, 0];
        }

        $fontId = $fonts->childElementCount;
        $font = $styles->createElementNS(self::NS_MAIN, 'font');
        $font->appendChild($styles->createElementNS(self::NS_MAIN, 'sz'))->setAttribute('val', '10');
        $font->appendChild($styles->createElementNS(self::NS_MAIN, 'color'))->setAttribute('theme', '1');
        $font->appendChild($styles->createElementNS(self::NS_MAIN, 'name'))->setAttribute('val', 'Calibri');
        $font->appendChild($styles->createElementNS(self::NS_MAIN, 'family'))->setAttribute('val', '2');
        $fonts->appendChild($font);
        $fonts->setAttribute('count', (string) $fonts->childElementCount);

        $subtitleStyle = $cellXfs->childElementCount;
        $subtitleXf = $styles->createElementNS(self::NS_MAIN, 'xf');
        foreach (['numFmtId' => '0', 'fontId' => (string) $fontId, 'fillId' => '0', 'borderId' => '0', 'xfId' => '0', 'applyFont' => '1', 'applyAlignment' => '1'] as $name => $value) {
            $subtitleXf->setAttribute($name, $value);
        }
        $subtitleAlignment = $styles->createElementNS(self::NS_MAIN, 'alignment');
        $subtitleAlignment->setAttribute('horizontal', 'center');
        $subtitleAlignment->setAttribute('vertical', 'center');
        $subtitleAlignment->setAttribute('wrapText', '1');
        $subtitleXf->appendChild($subtitleAlignment);
        $cellXfs->appendChild($subtitleXf);

        $footerStyle = $cellXfs->childElementCount;
        $footerXf = $styles->createElementNS(self::NS_MAIN, 'xf');
        foreach (['numFmtId' => '0', 'fontId' => (string) $fontId, 'fillId' => '0', 'borderId' => '0', 'xfId' => '0', 'applyFont' => '1', 'applyAlignment' => '1'] as $name => $value) {
            $footerXf->setAttribute($name, $value);
        }
        $footerAlignment = $styles->createElementNS(self::NS_MAIN, 'alignment');
        $footerAlignment->setAttribute('horizontal', 'left');
        $footerAlignment->setAttribute('vertical', 'center');
        $footerXf->appendChild($footerAlignment);
        $cellXfs->appendChild($footerXf);
        $cellXfs->setAttribute('count', (string) $cellXfs->childElementCount);

        $xml = $styles->saveXML();
        return [is_string($xml) ? $xml : $sourceStylesXml, $subtitleStyle, $footerStyle];
    }

    private function setInlineStringCell(DOMDocument $document, DOMElement $row, string $reference, string $value, ?int $styleId = null): DOMElement
    {
        $cell = null;
        foreach ($row->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'c' && strtoupper($child->getAttribute('r')) === strtoupper($reference)) {
                $cell = $child;
                break;
            }
        }
        if (!$cell instanceof DOMElement) {
            $cell = $document->createElementNS(self::NS_MAIN, 'c');
            $cell->setAttribute('r', $reference);
            $row->appendChild($cell);
        }
        while ($cell->firstChild !== null) {
            $cell->removeChild($cell->firstChild);
        }
        $cell->setAttribute('t', 'inlineStr');
        if ($styleId !== null) {
            if ($styleId > 0) {
                $cell->setAttribute('s', (string) $styleId);
            } else {
                $cell->removeAttribute('s');
            }
        }
        $inline = $document->createElementNS(self::NS_MAIN, 'is');
        $text = $document->createElementNS(self::NS_MAIN, 't');
        $text->appendChild($document->createTextNode($value));
        $inline->appendChild($text);
        $cell->appendChild($inline);
        return $cell;
    }

    private function addReportSubtitle(DOMDocument $sheet, DOMElement $sheetData, string $subtitle, int $styleId): void
    {
        $row = null;
        foreach ($sheetData->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'row' && (int) $child->getAttribute('r') === 3) {
                $row = $child;
                break;
            }
        }
        if (!$row instanceof DOMElement) {
            $row = $sheet->createElementNS(self::NS_MAIN, 'row');
            $row->setAttribute('r', '3');
            $insertBefore = null;
            foreach ($sheetData->childNodes as $child) {
                if ($child instanceof DOMElement && $child->localName === 'row' && (int) $child->getAttribute('r') > 3) {
                    $insertBefore = $child;
                    break;
                }
            }
            $insertBefore instanceof DOMElement ? $sheetData->insertBefore($row, $insertBefore) : $sheetData->appendChild($row);
        } else {
            foreach (iterator_to_array($row->childNodes) as $child) {
                if ($child instanceof DOMElement && $child->localName === 'c') {
                    $row->removeChild($child);
                }
            }
        }
        $row->setAttribute('ht', '19');
        $row->setAttribute('customHeight', '1');
        $this->setInlineStringCell($sheet, $row, 'A3', $subtitle, $styleId);
        $this->ensureMergeCell($sheet, 'A3:N3');
    }

    private function addReportFooter(DOMDocument $sheet, DOMElement $sheetData, int $rowNumber, string $createdBy, string $generatedOn, int $styleId): void
    {
        $row = $sheet->createElementNS(self::NS_MAIN, 'row');
        $row->setAttribute('r', (string) $rowNumber);
        $row->setAttribute('ht', '19');
        $row->setAttribute('customHeight', '1');
        $this->setInlineStringCell($sheet, $row, 'A' . $rowNumber, 'Realizado por:', $styleId);
        $this->setInlineStringCell($sheet, $row, 'D' . $rowNumber, $createdBy !== '' ? $createdBy : 'Usuario no disponible', $styleId);
        $this->setInlineStringCell($sheet, $row, 'I' . $rowNumber, 'Generado:', $styleId);
        $this->setInlineStringCell($sheet, $row, 'L' . $rowNumber, $generatedOn !== '' ? $generatedOn : date('d/m/Y'), $styleId);
        $sheetData->appendChild($row);
    }

    private function ensureMergeCell(DOMDocument $sheet, string $reference): void
    {
        $xpath = new DOMXPath($sheet);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $container = $xpath->query('/m:worksheet/m:mergeCells')->item(0);
        if (!$container instanceof DOMElement) {
            $container = $sheet->createElementNS(self::NS_MAIN, 'mergeCells');
            $sheetData = $xpath->query('/m:worksheet/m:sheetData')->item(0);
            if ($sheetData instanceof DOMElement && $sheetData->nextSibling !== null) {
                $sheetData->parentNode?->insertBefore($container, $sheetData->nextSibling);
            } else {
                $sheet->documentElement?->appendChild($container);
            }
        }
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && $child->localName === 'mergeCell' && strtoupper($child->getAttribute('ref')) === strtoupper($reference)) {
                $container->setAttribute('count', (string) $container->childElementCount);
                return;
            }
        }
        $merge = $sheet->createElementNS(self::NS_MAIN, 'mergeCell');
        $merge->setAttribute('ref', $reference);
        $container->appendChild($merge);
        $container->setAttribute('count', (string) $container->childElementCount);
    }

    private static function replaceZipEntry(ZipArchive $zip, string $name, string $contents): void
    {
        if ($zip->locateName($name) !== false) {
            if (!$zip->deleteName($name)) {
                throw new \RuntimeException('No se pudo reemplazar el componente ' . $name . ' del XLSX.');
            }
        }
        if (!$zip->addFromString($name, $contents)) {
            throw new \RuntimeException('No se pudo escribir el componente ' . $name . ' del XLSX.');
        }
    }

    private function newRelationshipsDocument(): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $root = $document->createElementNS(self::NS_PACKAGE_REL, 'Relationships');
        $document->appendChild($root);
        return $document;
    }

    private function nextRelationshipId(DOMDocument $relationships): string
    {
        $xpath = new DOMXPath($relationships);
        $xpath->registerNamespace('p', self::NS_PACKAGE_REL);
        $used = [];
        foreach ($xpath->query('/p:Relationships/p:Relationship') ?: [] as $relation) {
            if ($relation instanceof DOMElement && preg_match('/^rId(\d+)$/', $relation->getAttribute('Id'), $match) === 1) {
                $used[(int) $match[1]] = true;
            }
        }
        $id = 1;
        while (isset($used[$id])) {
            $id++;
        }
        return 'rId' . $id;
    }

    private function addPackageRelationship(DOMDocument $document, string $id, string $type, string $target): void
    {
        $root = $document->documentElement;
        if (!$root instanceof DOMElement) {
            throw new \RuntimeException('El XLSX contiene relaciones sin elemento raíz.');
        }
        $relation = $document->createElementNS(self::NS_PACKAGE_REL, 'Relationship');
        $relation->setAttribute('Id', $id);
        $relation->setAttribute('Type', $type);
        $relation->setAttribute('Target', $target);
        $root->appendChild($relation);
    }

    private function addBrandPicture(DOMDocument $drawing, string $imageRelationshipId, string $imageDescription, int $widthEmu, int $heightEmu): void
    {
        $root = $drawing->documentElement;
        if (!$root instanceof DOMElement) {
            throw new \RuntimeException('No se pudo crear el dibujo de identidad visual.');
        }
        $xpath = new DOMXPath($drawing);
        $xpath->registerNamespace('xdr', self::NS_DRAWING);
        $pictureId = 1;
        foreach ($xpath->query('//xdr:cNvPr') ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $pictureId = max($pictureId, ((int) $node->getAttribute('id')) + 1);
            }
        }

        // A1 es la esquina superior izquierda del espacio reservado al logo.
        // Se usa oneCellAnchor con dimensiones fijas para conservar la relación de aspecto.
        $anchor = $drawing->createElementNS(self::NS_DRAWING, 'xdr:oneCellAnchor');
        $from = $drawing->createElementNS(self::NS_DRAWING, 'xdr:from');
        foreach (['col' => '0', 'colOff' => '38100', 'row' => '0', 'rowOff' => '38100'] as $name => $value) {
            $element = $drawing->createElementNS(self::NS_DRAWING, 'xdr:' . $name);
            $element->appendChild($drawing->createTextNode($value));
            $from->appendChild($element);
        }
        $anchor->appendChild($from);
        $anchorExtent = $drawing->createElementNS(self::NS_DRAWING, 'xdr:ext');
        $anchorExtent->setAttribute('cx', (string) $widthEmu);
        $anchorExtent->setAttribute('cy', (string) $heightEmu);
        $anchor->appendChild($anchorExtent);

        $picture = $drawing->createElementNS(self::NS_DRAWING, 'xdr:pic');
        $nonVisual = $drawing->createElementNS(self::NS_DRAWING, 'xdr:nvPicPr');
        $nonVisualProperties = $drawing->createElementNS(self::NS_DRAWING, 'xdr:cNvPr');
        $nonVisualProperties->setAttribute('id', (string) $pictureId);
        $nonVisualProperties->setAttribute('name', 'Logotipo de la empresa');
        $nonVisualProperties->setAttribute('descr', $imageDescription);
        $nonVisual->appendChild($nonVisualProperties);
        $nonVisualPictureProperties = $drawing->createElementNS(self::NS_DRAWING, 'xdr:cNvPicPr');
        $locks = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:picLocks');
        $locks->setAttribute('noChangeAspect', '1');
        $nonVisualPictureProperties->appendChild($locks);
        $nonVisual->appendChild($nonVisualPictureProperties);
        $picture->appendChild($nonVisual);

        $fill = $drawing->createElementNS(self::NS_DRAWING, 'xdr:blipFill');
        $blip = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:blip');
        $blip->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:embed', $imageRelationshipId);
        $fill->appendChild($blip);
        $stretch = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:stretch');
        $stretch->appendChild($drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:fillRect'));
        $fill->appendChild($stretch);
        $picture->appendChild($fill);

        $shapeProperties = $drawing->createElementNS(self::NS_DRAWING, 'xdr:spPr');
        $transform = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:xfrm');
        $offset = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:off');
        $offset->setAttribute('x', '0');
        $offset->setAttribute('y', '0');
        $transform->appendChild($offset);
        $extent = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:ext');
        $extent->setAttribute('cx', (string) $widthEmu);
        $extent->setAttribute('cy', (string) $heightEmu);
        $transform->appendChild($extent);
        $shapeProperties->appendChild($transform);
        $geometry = $drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:prstGeom');
        $geometry->setAttribute('prst', 'rect');
        $geometry->appendChild($drawing->createElementNS(self::NS_DRAWING_MAIN, 'a:avLst'));
        $shapeProperties->appendChild($geometry);
        $picture->appendChild($shapeProperties);
        $anchor->appendChild($picture);
        $anchor->appendChild($drawing->createElementNS(self::NS_DRAWING, 'xdr:clientData'));
        $root->appendChild($anchor);
    }

    private function embedBrandLogo(string $xlsxPath, string $sheetPath, array $logo): bool
    {
        $logoPath = (string) ($logo['path'] ?? '');
        $mime = strtolower((string) ($logo['mime'] ?? ''));
        if (!is_file($logoPath) || !is_readable($logoPath)) {
            throw new \RuntimeException('El logotipo configurado no está disponible para incrustarlo en los reportes.');
        }
        $logoBytes = @filesize($logoPath);
        if (!is_int($logoBytes) || $logoBytes < 1 || $logoBytes > 2_097_152) {
            throw new \RuntimeException('El logotipo supera el límite de 2 MB o no se puede leer.');
        }
        $imageInfo = @getimagesize($logoPath);
        if (!is_array($imageInfo) || strtolower((string) ($imageInfo['mime'] ?? '')) !== $mime
            || (int) ($imageInfo[0] ?? 0) < 1 || (int) ($imageInfo[1] ?? 0) < 1
            || (int) ($imageInfo[0] ?? 0) > 2400 || (int) ($imageInfo[1] ?? 0) > 1200
            || ((int) $imageInfo[0] * (int) $imageInfo[1]) > 2_500_000) {
            throw new \RuntimeException('El archivo de logotipo dejó de ser una imagen válida.');
        }

        $temporaryLogo = null;
        $extensionByMime = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif'];
        if ($mime === 'image/webp') {
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagepng')) {
                // WebP sigue siendo válido para la interfaz; se omite en Excel en lugar de abortar el procesamiento.
                return false;
            }
            $image = @imagecreatefromwebp($logoPath);
            if ($image === false) {
                throw new \RuntimeException('No se pudo convertir el logotipo WebP para el reporte Excel.');
            }
            $temporaryLogo = tempnam(sys_get_temp_dir(), 'zktime-logo-');
            if (!is_string($temporaryLogo) || !imagepng($image, $temporaryLogo)) {
                imagedestroy($image);
                throw new \RuntimeException('No se pudo preparar una versión PNG del logotipo.');
            }
            imagedestroy($image);
            $logoPath = $temporaryLogo;
            $mime = 'image/png';
        }
        if (!isset($extensionByMime[$mime])) {
            if (is_string($temporaryLogo)) {
                @unlink($temporaryLogo);
            }
            throw new \RuntimeException('Excel admite logotipos PNG, JPG o GIF. Cambia el formato de la imagen de identidad visual.');
        }

        $zip = new ZipArchive();
        // ZipArchive::open() without flags opens an existing archive for read/write.
        // PHP has no separate read/write flag constant; passing one causes an Error.
        if ($zip->open($xlsxPath) !== true) {
            if (is_string($temporaryLogo)) {
                @unlink($temporaryLogo);
            }
            throw new \RuntimeException('No se pudo abrir el reporte para incrustar el logotipo.');
        }
        try {
            $mediaName = 'xl/media/zktime-logo-' . bin2hex(random_bytes(8)) . '.' . $extensionByMime[$mime];
            $imageBytes = file_get_contents($logoPath);
            if (!is_string($imageBytes) || $imageBytes === '' || !$zip->addFromString($mediaName, $imageBytes)) {
                throw new \RuntimeException('No se pudo incrustar el archivo de logotipo en el Excel.');
            }

            $sheetDirectory = dirname($sheetPath);
            $sheetRelationsPath = $sheetDirectory . '/_rels/' . basename($sheetPath) . '.rels';
            $sheetRelationsXml = $zip->getFromName($sheetRelationsPath);
            $sheetRelations = is_string($sheetRelationsXml) && $sheetRelationsXml !== ''
                ? self::loadXml($sheetRelationsXml, 'No se pudieron leer las relaciones de la hoja XLSX.')
                : $this->newRelationshipsDocument();

            $sheetRelationshipId = null;
            $drawingPath = null;
            $sheetRelationsXpath = new DOMXPath($sheetRelations);
            $sheetRelationsXpath->registerNamespace('p', self::NS_PACKAGE_REL);
            foreach ($sheetRelationsXpath->query('/p:Relationships/p:Relationship') ?: [] as $relation) {
                if (!$relation instanceof DOMElement || !str_ends_with($relation->getAttribute('Type'), '/drawing')) {
                    continue;
                }
                $sheetRelationshipId = $relation->getAttribute('Id');
                $drawingPath = self::normalizeZipPath($relation->getAttribute('Target'), $sheetDirectory);
                break;
            }

            $sheetXml = $zip->getFromName($sheetPath);
            if (!is_string($sheetXml)) {
                throw new \RuntimeException('No se pudo recuperar la hoja del reporte para añadir el logotipo.');
            }
            $sheetDocument = self::loadXml($sheetXml, 'La hoja del reporte no contiene XML válido.');
            if ($drawingPath === null) {
                $number = 1;
                do {
                    $drawingPath = 'xl/drawings/drawing' . $number . '.xml';
                    $number++;
                } while ($zip->locateName($drawingPath) !== false);
                $sheetRelationshipId = $this->nextRelationshipId($sheetRelations);
                $this->addPackageRelationship(
                    $sheetRelations,
                    $sheetRelationshipId,
                    'http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing',
                    '../drawings/' . basename($drawingPath)
                );
                $sheetXpath = new DOMXPath($sheetDocument);
                $sheetXpath->registerNamespace('m', self::NS_MAIN);
                if ($sheetXpath->query('/m:worksheet/m:drawing')->length === 0) {
                    $drawingNode = $sheetDocument->createElementNS(self::NS_MAIN, 'drawing');
                    $drawingNode->setAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'r:id', $sheetRelationshipId);
                    $insertBefore = null;
                    foreach ($sheetDocument->documentElement?->childNodes ?? [] as $child) {
                        if ($child instanceof DOMElement && in_array($child->localName, ['legacyDrawing', 'legacyDrawingHF', 'picture', 'oleObjects', 'controls', 'webPublishItems', 'tableParts', 'extLst'], true)) {
                            $insertBefore = $child;
                            break;
                        }
                    }
                    $insertBefore instanceof DOMElement
                        ? $sheetDocument->documentElement?->insertBefore($drawingNode, $insertBefore)
                        : $sheetDocument->documentElement?->appendChild($drawingNode);
                }
            }

            $drawingXml = $zip->getFromName($drawingPath);
            if (is_string($drawingXml) && $drawingXml !== '') {
                $drawingDocument = self::loadXml($drawingXml, 'El dibujo existente del XLSX no contiene XML válido.');
            } else {
                $drawingDocument = new DOMDocument('1.0', 'UTF-8');
                $drawingRoot = $drawingDocument->createElementNS(self::NS_DRAWING, 'xdr:wsDr');
                $drawingRoot->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:a', self::NS_DRAWING_MAIN);
                $drawingRoot->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
                $drawingDocument->appendChild($drawingRoot);
            }

            $drawingRelationsPath = dirname($drawingPath) . '/_rels/' . basename($drawingPath) . '.rels';
            $drawingRelationsXml = $zip->getFromName($drawingRelationsPath);
            $drawingRelations = is_string($drawingRelationsXml) && $drawingRelationsXml !== ''
                ? self::loadXml($drawingRelationsXml, 'No se pudieron leer las relaciones de la imagen XLSX.')
                : $this->newRelationshipsDocument();
            $imageRelationshipId = $this->nextRelationshipId($drawingRelations);
            $this->addPackageRelationship(
                $drawingRelations,
                $imageRelationshipId,
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships/image',
                '../media/' . basename($mediaName)
            );
            $scale = min(160 / (int) $imageInfo[0], 44 / (int) $imageInfo[1], 1.0);
            $logoWidthEmu = max(1, (int) round((int) $imageInfo[0] * $scale * 9525));
            $logoHeightEmu = max(1, (int) round((int) $imageInfo[1] * $scale * 9525));
            $this->addBrandPicture(
                $drawingDocument,
                $imageRelationshipId,
                basename((string) ($logo['filename'] ?? 'logotipo')),
                $logoWidthEmu,
                $logoHeightEmu
            );

            $contentTypesXml = $zip->getFromName('[Content_Types].xml');
            if (!is_string($contentTypesXml)) {
                throw new \RuntimeException('El XLSX no contiene [Content_Types].xml para registrar el logotipo.');
            }
            $contentTypes = self::loadXml($contentTypesXml, 'No se pudieron leer los tipos de contenido del XLSX.');
            $contentXpath = new DOMXPath($contentTypes);
            $contentXpath->registerNamespace('ct', self::NS_CONTENT_TYPES);
            $extension = $extensionByMime[$mime];
            if ($contentXpath->query('/ct:Types/ct:Default[@Extension="' . $extension . '"]')->length === 0) {
                $default = $contentTypes->createElementNS(self::NS_CONTENT_TYPES, 'Default');
                $default->setAttribute('Extension', $extension);
                $default->setAttribute('ContentType', $mime);
                $contentTypes->documentElement?->appendChild($default);
            }
            if ($contentXpath->query('/ct:Types/ct:Override[@PartName="/' . $drawingPath . '"]')->length === 0) {
                $override = $contentTypes->createElementNS(self::NS_CONTENT_TYPES, 'Override');
                $override->setAttribute('PartName', '/' . $drawingPath);
                $override->setAttribute('ContentType', 'application/vnd.openxmlformats-officedocument.drawing+xml');
                $contentTypes->documentElement?->appendChild($override);
            }

            $sheetRelationsOutput = $sheetRelations->saveXML();
            $sheetOutput = $sheetDocument->saveXML();
            $drawingRelationsOutput = $drawingRelations->saveXML();
            $drawingOutput = $drawingDocument->saveXML();
            $contentTypesOutput = $contentTypes->saveXML();
            foreach ([$sheetRelationsOutput, $sheetOutput, $drawingRelationsOutput, $drawingOutput, $contentTypesOutput] as $xmlOutput) {
                if (!is_string($xmlOutput)) {
                    throw new \RuntimeException('No se pudieron serializar las relaciones del logotipo XLSX.');
                }
            }
            self::replaceZipEntry($zip, $sheetRelationsPath, $sheetRelationsOutput);
            self::replaceZipEntry($zip, $sheetPath, $sheetOutput);
            self::replaceZipEntry($zip, $drawingRelationsPath, $drawingRelationsOutput);
            self::replaceZipEntry($zip, $drawingPath, $drawingOutput);
            self::replaceZipEntry($zip, '[Content_Types].xml', $contentTypesOutput);
            if (!$zip->close()) {
                throw new \RuntimeException('No se pudo finalizar el logotipo dentro del reporte Excel.');
            }
            return true;
        } catch (\Throwable $exception) {
            @$zip->close();
            @unlink($xlsxPath);
            throw $exception;
        } finally {
            if (is_string($temporaryLogo)) {
                @unlink($temporaryLogo);
            }
        }
    }

    /** Ajusta una sola columna conservando cualquier rango de columnas vecino. */
    private function adjustColumnWidth(DOMDocument $sheet, DOMElement $sheetData, int $columnNumber, float $width): void
    {
        $xpath = new DOMXPath($sheet);
        $xpath->registerNamespace('m', self::NS_MAIN);
        $container = $xpath->query('/m:worksheet/m:cols')->item(0);
        if (!$container instanceof DOMElement) {
            $container = $sheet->createElementNS(self::NS_MAIN, 'cols');
            $sheet->documentElement?->insertBefore($container, $sheetData);
        }
        if (!$container instanceof DOMElement) {
            throw new \RuntimeException('No se pudo ajustar el ancho de la columna Turno.');
        }

        foreach (iterator_to_array($container->childNodes) as $columnNode) {
            if (!$columnNode instanceof DOMElement || $columnNode->localName !== 'col') {
                continue;
            }
            $min = filter_var($columnNode->getAttribute('min'), FILTER_VALIDATE_INT);
            $max = filter_var($columnNode->getAttribute('max'), FILTER_VALIDATE_INT);
            if (!is_int($min) || !is_int($max) || $columnNumber < $min || $columnNumber > $max) {
                continue;
            }

            $replacement = [];
            if ($min < $columnNumber) {
                $before = $columnNode->cloneNode(true);
                $before->setAttribute('max', (string) ($columnNumber - 1));
                $replacement[] = $before;
            }
            $target = $columnNode->cloneNode(true);
            $target->setAttribute('min', (string) $columnNumber);
            $target->setAttribute('max', (string) $columnNumber);
            $target->setAttribute('width', rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.'));
            $target->setAttribute('customWidth', '1');
            $target->removeAttribute('bestFit');
            $replacement[] = $target;
            if ($max > $columnNumber) {
                $after = $columnNode->cloneNode(true);
                $after->setAttribute('min', (string) ($columnNumber + 1));
                $replacement[] = $after;
            }
            foreach ($replacement as $node) {
                $container->insertBefore($node, $columnNode);
            }
            $container->removeChild($columnNode);
            return;
        }

        $column = $sheet->createElementNS(self::NS_MAIN, 'col');
        $column->setAttribute('min', (string) $columnNumber);
        $column->setAttribute('max', (string) $columnNumber);
        $column->setAttribute('width', rtrim(rtrim(number_format($width, 2, '.', ''), '0'), '.'));
        $column->setAttribute('customWidth', '1');
        $container->appendChild($column);
    }

    private function buildSharedStringsDocument(DOMDocument $source, array $items, array $order, int $referenceCount): DOMDocument
    {
        $new = new DOMDocument('1.0', 'UTF-8');
        $new->preserveWhiteSpace = true;
        $root = $new->createElementNS(self::NS_MAIN, 'sst');
        $root->setAttribute('count', (string) $referenceCount);
        $root->setAttribute('uniqueCount', (string) count($order));
        $new->appendChild($root);
        foreach ($order as $oldIndex) {
            $item = $items[$oldIndex] ?? null;
            if (!$item instanceof DOMElement) {
                throw new \RuntimeException('No se pudo recuperar una cadena del catálogo compartido.');
            }
            $root->appendChild($new->importNode($item, true));
        }
        return $new;
    }

    private function writeDepartmentWorkbook(
        ZipArchive $source,
        string $destinationPath,
        string $sheetPath,
        string $sheetXml,
        ?string $sharedStringsPath,
        ?string $sharedStringsXml,
        ?string $stylesXml,
        string $title
    ): void
    {
        $destination = new ZipArchive();
        if ($destination->open($destinationPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('No se pudo crear el Excel del departamento.');
        }
        $corePath = 'docProps/core.xml';
        $coreXml = $source->getFromName($corePath);
        $updatedCore = null;
        if (is_string($coreXml) && $coreXml !== '') {
            $core = self::loadXml($coreXml, 'No se pudieron leer las propiedades del Excel.');
            $coreXpath = new DOMXPath($core);
            $coreXpath->registerNamespace('core', 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties');
            $coreXpath->registerNamespace('dc', 'http://purl.org/dc/elements/1.1/');
            $titleNode = $coreXpath->query('/core:coreProperties/dc:title');
            if ($titleNode === false || $titleNode->length === 0) {
                $root = $core->documentElement;
                if ($root instanceof DOMElement) {
                    $titleElement = $core->createElementNS('http://purl.org/dc/elements/1.1/', 'dc:title');
                    $titleElement->appendChild($core->createTextNode(pathinfo($title, PATHINFO_FILENAME)));
                    $root->appendChild($titleElement);
                }
            } else {
                $titleNode->item(0)->textContent = pathinfo($title, PATHINFO_FILENAME);
            }
            $updatedCore = $core->saveXML();
        }
        try {
            for ($index = 0; $index < $source->numFiles; $index++) {
                $stat = $source->statIndex($index);
                if (!is_array($stat)) {
                    throw new \RuntimeException('No se pudo copiar un componente del XLSX original.');
                }
                $name = (string) $stat['name'];
                if ($name === $sheetPath
                    || ($sharedStringsPath !== null && $name === $sharedStringsPath)
                    || ($stylesXml !== null && $name === 'xl/styles.xml')
                    || ($updatedCore !== null && $name === $corePath)) {
                    continue;
                }
                $contents = $source->getFromIndex($index);
                if (!is_string($contents) || !$destination->addFromString($name, $contents)) {
                    throw new \RuntimeException('No se pudo conservar el componente ' . $name . ' en el Excel resultante.');
                }
            }
            if (!$destination->addFromString($sheetPath, $sheetXml)) {
                throw new \RuntimeException('No se pudo escribir la hoja recortada.');
            }
            if ($sharedStringsPath !== null && $sharedStringsXml !== null && !$destination->addFromString($sharedStringsPath, $sharedStringsXml)) {
                throw new \RuntimeException('No se pudo escribir el catálogo de textos recortado.');
            }
            if ($stylesXml !== null && !$destination->addFromString('xl/styles.xml', $stylesXml)) {
                throw new \RuntimeException('No se pudieron escribir los estilos del reporte.');
            }
            if (is_string($updatedCore) && !$destination->addFromString($corePath, $updatedCore)) {
                throw new \RuntimeException('No se pudieron actualizar las propiedades del Excel.');
            }
            if (!$destination->close()) {
                throw new \RuntimeException('No se pudo finalizar el Excel del departamento.');
            }
        } catch (\Throwable $exception) {
            @$destination->close();
            @unlink($destinationPath);
            throw $exception;
        }
    }

    private static function loadXml(string $xml, string $errorMessage): DOMDocument
    {
        if (strlen($xml) > self::MAX_EXPANDED_BYTES || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw new \RuntimeException($errorMessage);
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $previous = libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new \RuntimeException($errorMessage);
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        return $document;
    }

    private static function normalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/u', ' ', $value) ?? $value));
    }

    private function safeFilePart(string $name): string
    {
        $ascii = strtr(trim($name), ['Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N','á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
        $value = preg_replace('/[^A-Za-z0-9_-]+/', '_', $ascii) ?? '';
        return strtoupper(trim($value, '_-')) ?: 'DEPARTAMENTO';
    }

    private function uniqueFileName(string $base, array &$used): string
    {
        $candidate = $base;
        $suffix = 2;
        while (isset($used[strtolower($candidate)])) {
            $candidate = preg_replace('/\.xlsx$/i', '', $base) . '_' . $suffix . '.xlsx';
            $suffix++;
        }
        $used[strtolower($candidate)] = true;
        return $candidate;
    }

    private static function columnToNumber(string $column): int
    {
        $number = 0;
        foreach (str_split(strtoupper($column)) as $character) {
            $number = ($number * 26) + (ord($character) - 64);
        }
        return $number;
    }

    private static function numberToColumn(int $number): string
    {
        $result = '';
        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $result = chr(65 + $remainder) . $result;
            $number = intdiv($number - 1, 26);
        }
        return $result !== '' ? $result : 'A';
    }

    public static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (new FilesystemIterator($directory, FilesystemIterator::SKIP_DOTS) as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($directory);
    }
}
