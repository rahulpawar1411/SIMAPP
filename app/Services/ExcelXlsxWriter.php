<?php
namespace App\Services;

use ZipArchive;
use Exception;

/**
 * Minimal .xlsx writer (no PhpSpreadsheet) — header fills + plain data rows.
 */
class ExcelXlsxWriter {

    /** @var list<string> */
    private array $columns = [];

    /** @var list<array<string, scalar|null>> */
    private array $rows = [];

    /** @var callable(string):string */
    private $sectionOf;

    private string $sheetName = 'Hub Template';

    public function __construct(callable $sectionOf) {
        $this->sectionOf = $sectionOf;
    }

    public function setSheetName(string $name): self {
        $clean = preg_replace('/[\\\\\/\?\*\[\]]/', '', $name) ?? 'Sheet1';
        $this->sheetName = mb_substr($clean, 0, 31) ?: 'Sheet1';
        return $this;
    }

    public function setColumns(array $columns): self {
        $this->columns = array_values($columns);
        return $this;
    }

    /** @param list<array<string, scalar|null>> $rows */
    public function setRows(array $rows): self {
        $this->rows = $rows;
        return $this;
    }

    public function stream(string $filename): void {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx_');
        if ($tmp === false) {
            throw new Exception('Unable to create temp file for Excel export.');
        }
        $xlsxPath = $tmp . '.xlsx';
        @unlink($tmp);

        try {
            $this->buildFile($xlsxPath);
            header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . (string)filesize($xlsxPath));
            header('Pragma: no-cache');
            header('Expires: 0');
            readfile($xlsxPath);
        } finally {
            @unlink($xlsxPath);
        }
    }

    private function buildFile(string $path): void {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('Unable to create Excel (.xlsx) file.');
        }

        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->sheet());
        $zip->close();
    }

    private static function colLetter(int $index0): string {
        $n = $index0 + 1;
        $s = '';
        while ($n > 0) {
            $n--;
            $s = chr(65 + ($n % 26)) . $s;
            $n = intdiv($n, 26);
        }
        return $s;
    }

    private static function xml(string $s): string {
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function styleIndexForSection(string $section): int {
        return match ($section) {
            'store' => 1,
            'freezer' => 2,
            'iot' => 3,
            default => 4, // notes / other
        };
    }

    private function sheet(): string {
        $sectionOf = $this->sectionOf;
        $cols = $this->columns;
        $colCount = count($cols);
        $lastCol = self::colLetter(max(0, $colCount - 1));
        $lastRow = 1 + count($this->rows);
        $dim = 'A1:' . $lastCol . $lastRow;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="' . $dim . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="15"/>'
            . '<cols>';

        for ($i = 0; $i < $colCount; $i++) {
            $width = max(12, min(28, strlen($cols[$i]) + 4));
            $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $width . '" customWidth="1"/>';
        }
        $xml .= '</cols><sheetData>';

        // Header row
        $xml .= '<row r="1" ht="20" customHeight="1">';
        foreach ($cols as $i => $col) {
            $ref = self::colLetter($i) . '1';
            $style = $this->styleIndexForSection($sectionOf($col));
            $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t>'
                . self::xml($col) . '</t></is></c>';
        }
        $xml .= '</row>';

        // Data rows
        $r = 2;
        foreach ($this->rows as $row) {
            $xml .= '<row r="' . $r . '">';
            foreach ($cols as $i => $col) {
                $ref = self::colLetter($i) . $r;
                $val = (string)($row[$col] ?? '');
                $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>'
                    . self::xml($val) . '</t></is></c>';
            }
            $xml .= '</row>';
            $r++;
        }

        $xml .= '</sheetData>'
            . '<autoFilter ref="' . $dim . '"/>'
            . '</worksheet>';
        return $xml;
    }

    private function styles(): string {
        // fills: 0 none, 1 gray125 (required), 2 store blue, 3 freezer amber, 4 iot green, 5 notes gray
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="5">'
            . '<font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF1E3A8A"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF78350F"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF14532D"/><name val="Calibri"/><family val="2"/></font>'
            . '<font><b/><sz val="11"/><color rgb="FF334155"/><name val="Calibri"/><family val="2"/></font>'
            . '</fonts>'
            . '<fills count="6">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF93C5FD"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFFCD34D"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FF86EFAC"/></patternFill></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE2E8F0"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border>'
            . '<left style="thin"><color rgb="FF94A3B8"/></left>'
            . '<right style="thin"><color rgb="FF94A3B8"/></right>'
            . '<top style="thin"><color rgb="FF94A3B8"/></top>'
            . '<bottom style="thin"><color rgb="FF94A3B8"/></bottom>'
            . '<diagonal/>'
            . '</border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="5">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '<xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>'
            . '</cellXfs>'
            . '</styleSheet>';
    }

    private function workbook(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . self::xml($this->sheetName) . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>';
    }

    private function workbookRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
            . ' Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"'
            . ' Target="styles.xml"/>'
            . '</Relationships>';
    }

    private function rootRels(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"'
            . ' Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function contentTypes(): string {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>';
    }
}
