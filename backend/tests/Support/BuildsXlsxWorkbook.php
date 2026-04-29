<?php

namespace Tests\Support;

use RuntimeException;
use ZipArchive;

trait BuildsXlsxWorkbook
{
    /**
     * @param  array<string, array<int, array<int, scalar|null>>>  $sheets
     */
    protected function buildXlsxWorkbook(array $sheets): string
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx-test-');

        if ($tempPath === false) {
            throw new RuntimeException('Unable to allocate a temporary file for the Excel fixture.');
        }

        $archive = new ZipArchive();

        if ($archive->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create the Excel fixture archive.');
        }

        $sharedStrings = $this->sharedStringsIndex($sheets);

        $archive->addFromString('[Content_Types].xml', $this->contentTypesXml(count($sheets)));
        $archive->addFromString('_rels/.rels', $this->rootRelationshipsXml());
        $archive->addFromString('xl/workbook.xml', $this->workbookXml(array_keys($sheets)));
        $archive->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRelationshipsXml(count($sheets)));
        $archive->addFromString('xl/sharedStrings.xml', $this->sharedStringsXml(array_keys($sharedStrings)));

        $index = 1;

        foreach ($sheets as $rows) {
            $archive->addFromString(
                sprintf('xl/worksheets/sheet%d.xml', $index),
                $this->worksheetXml($rows, $sharedStrings),
            );

            $index++;
        }

        $archive->close();

        $content = file_get_contents($tempPath);
        @unlink($tempPath);

        if (! is_string($content)) {
            throw new RuntimeException('Unable to read the generated Excel fixture.');
        }

        return $content;
    }

    /**
     * @param  array<string, array<int, array<int, scalar|null>>>  $sheets
     * @return array<string, int>
     */
    protected function sharedStringsIndex(array $sheets): array
    {
        $index = [];

        foreach ($sheets as $rows) {
            foreach ($rows as $row) {
                foreach ($row as $value) {
                    $string = $value === null ? '' : (string) $value;

                    if (! array_key_exists($string, $index)) {
                        $index[$string] = count($index);
                    }
                }
            }
        }

        return $index;
    }

    protected function contentTypesXml(int $sheetCount): string
    {
        $worksheetOverrides = '';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $worksheetOverrides .= sprintf(
                '<Override PartName="/xl/worksheets/sheet%d.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>',
                $index,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .$worksheetOverrides
            .'</Types>';
    }

    protected function rootRelationshipsXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    /**
     * @param  array<int, string>  $sheetNames
     */
    protected function workbookXml(array $sheetNames): string
    {
        $sheetsXml = '';

        foreach ($sheetNames as $index => $sheetName) {
            $sheetsXml .= sprintf(
                '<sheet name="%s" sheetId="%d" r:id="rId%d"/>',
                $this->xmlEscape($sheetName),
                $index + 1,
                $index + 1,
            );
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetsXml.'</sheets>'
            .'</workbook>';
    }

    protected function workbookRelationshipsXml(int $sheetCount): string
    {
        $relationshipsXml = '';

        for ($index = 1; $index <= $sheetCount; $index++) {
            $relationshipsXml .= sprintf(
                '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet%d.xml"/>',
                $index,
                $index,
            );
        }

        $relationshipsXml .= sprintf(
            '<Relationship Id="rId%d" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>',
            $sheetCount + 1,
        );

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .$relationshipsXml
            .'</Relationships>';
    }

    /**
     * @param  array<int, string>  $sharedStrings
     */
    protected function sharedStringsXml(array $sharedStrings): string
    {
        $itemsXml = '';

        foreach ($sharedStrings as $string) {
            $itemsXml .= '<si><t>'.$this->xmlEscape($string).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .sprintf(
                '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="%d" uniqueCount="%d">%s</sst>',
                count($sharedStrings),
                count($sharedStrings),
                $itemsXml,
            );
    }

    /**
     * @param  array<int, array<int, scalar|null>>  $rows
     * @param  array<string, int>  $sharedStrings
     */
    protected function worksheetXml(array $rows, array $sharedStrings): string
    {
        $rowsXml = '';

        foreach ($rows as $rowIndex => $row) {
            $cellsXml = '';

            foreach ($row as $columnIndex => $value) {
                $sharedIndex = $sharedStrings[$value === null ? '' : (string) $value];
                $cellsXml .= sprintf(
                    '<c r="%s%d" t="s"><v>%d</v></c>',
                    $this->columnLetters($columnIndex),
                    $rowIndex + 1,
                    $sharedIndex,
                );
            }

            $rowsXml .= sprintf('<row r="%d">%s</row>', $rowIndex + 1, $cellsXml);
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$rowsXml.'</sheetData>'
            .'</worksheet>';
    }

    protected function columnLetters(int $index): string
    {
        $letters = '';
        $index++;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letters = chr(65 + $remainder).$letters;
            $index = intdiv($index - 1, 26);
        }

        return $letters;
    }

    protected function xmlEscape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
