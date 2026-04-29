<?php

namespace App\Domain\DataSources\Excel;

use App\Domain\DataSources\Exceptions\DataSourceImportException;
use SimpleXMLElement;
use ZipArchive;

class NativeXlsxParser
{
    /**
     * @return array<string, array<int, array<int, string>>>
     */
    public function parse(string $path): array
    {
        $archive = new ZipArchive();

        if ($archive->open($path) !== true) {
            throw new DataSourceImportException('The uploaded Excel file could not be opened as a valid .xlsx workbook.');
        }

        try {
            $sharedStrings = $this->loadSharedStrings($archive);
            $sheetMap = $this->loadSheetMap($archive);
            $sheets = [];

            foreach ($sheetMap as $sheetName => $sheetPath) {
                $worksheetXml = $archive->getFromName($sheetPath);

                if (! is_string($worksheetXml)) {
                    continue;
                }

                $sheets[$sheetName] = $this->parseWorksheet($worksheetXml, $sharedStrings);
            }

            return $sheets;
        } finally {
            $archive->close();
        }
    }

    /**
     * @return array<int, string>
     */
    protected function loadSharedStrings(ZipArchive $archive): array
    {
        $xml = $archive->getFromName('xl/sharedStrings.xml');

        if (! is_string($xml)) {
            return [];
        }

        $root = $this->parseXml($xml, 'The workbook shared strings could not be parsed.');
        $strings = [];

        foreach ($root->si as $item) {
            $value = '';

            if (isset($item->t)) {
                $value = (string) $item->t;
            } elseif (isset($item->r)) {
                foreach ($item->r as $run) {
                    $value .= (string) $run->t;
                }
            }

            $strings[] = $value;
        }

        return $strings;
    }

    /**
     * @return array<string, string>
     */
    protected function loadSheetMap(ZipArchive $archive): array
    {
        $workbookXml = $archive->getFromName('xl/workbook.xml');
        $relationshipsXml = $archive->getFromName('xl/_rels/workbook.xml.rels');

        if (! is_string($workbookXml) || ! is_string($relationshipsXml)) {
            throw new DataSourceImportException('The workbook is missing required relationship metadata.');
        }

        $workbook = $this->parseXml($workbookXml, 'The workbook manifest could not be parsed.');
        $relationships = $this->parseXml($relationshipsXml, 'The workbook relationships could not be parsed.');
        $relationships->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/package/2006/relationships');
        $workbook->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $workbook->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        $targetsByRelationId = [];

        foreach ($relationships->xpath('//r:Relationship') ?: [] as $relationship) {
            $id = (string) $relationship['Id'];
            $target = (string) $relationship['Target'];

            if ($id !== '' && $target !== '') {
                $targetsByRelationId[$id] = 'xl/'.ltrim($target, '/');
            }
        }

        $sheetMap = [];

        foreach ($workbook->xpath('//main:sheets/main:sheet') ?: [] as $sheet) {
            $relationId = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $name = trim((string) $sheet['name']);
            $target = $targetsByRelationId[$relationId] ?? null;

            if ($name !== '' && is_string($target)) {
                $sheetMap[$name] = $target;
            }
        }

        if ($sheetMap === []) {
            throw new DataSourceImportException('The workbook does not contain readable worksheets.');
        }

        return $sheetMap;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     * @return array<int, array<int, string>>
     */
    protected function parseWorksheet(string $xml, array $sharedStrings): array
    {
        $worksheet = $this->parseXml($xml, 'A worksheet inside the workbook could not be parsed.');
        $worksheet->registerXPathNamespace('main', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $rows = [];

        foreach ($worksheet->xpath('//main:sheetData/main:row') ?: [] as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->columnIndexFromReference($reference);
                $cells[$columnIndex] = $this->cellValue($cell, $sharedStrings);
            }

            if ($cells !== []) {
                ksort($cells);
                $rows[] = array_values($cells);
            }
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    protected function cellValue(SimpleXMLElement $cell, array $sharedStrings): string
    {
        $type = (string) $cell['t'];

        return match ($type) {
            's' => $sharedStrings[(int) ($cell->v ?? 0)] ?? '',
            'inlineStr' => trim((string) ($cell->is->t ?? '')),
            'b' => ((string) ($cell->v ?? '0')) === '1' ? '1' : '0',
            default => trim((string) ($cell->v ?? '')),
        };
    }

    protected function columnIndexFromReference(string $reference): int
    {
        if ($reference === '') {
            return 0;
        }

        preg_match('/^[A-Z]+/i', $reference, $matches);
        $letters = strtoupper($matches[0] ?? 'A');
        $index = 0;

        foreach (str_split($letters) as $letter) {
            $index = ($index * 26) + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    protected function parseXml(string $xml, string $errorMessage): SimpleXMLElement
    {
        $parsed = simplexml_load_string($xml);

        if (! $parsed instanceof SimpleXMLElement) {
            throw new DataSourceImportException($errorMessage);
        }

        return $parsed;
    }
}
