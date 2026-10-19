<?php

namespace App\Support\Export;

use RuntimeException;
use ZipArchive;

/**
 * A small .xlsx writer for the results download (live walkthrough L16).
 *
 * It writes what the download needs and nothing more: several sheets, text
 * and number cells, a bold heading row, a frozen first column and heading
 * row, column widths, and four number formats (money, units, percent,
 * ratio). Numbers stay numbers, so faculty can sort and chart them. It needs
 * PHP's zip extension (ZipArchive); without it the download says so.
 */
final class XlsxWriter
{
    /** Cell styles, by the index styles.xml gives them. */
    public const TEXT = 0;

    public const BOLD = 1;

    public const MONEY = 2;

    public const UNITS = 3;

    public const PERCENT = 4;

    public const RATIO = 5;

    /** @var list<array{name: string, rows: list<list<array{0: string|float|int|null, 1: int}>>, widths: list<float>}> */
    private array $sheets = [];

    /**
     * @param  list<list<array{0: string|float|int|null, 1: int}>>  $rows  each cell is [value, style]
     * @param  list<float>  $widths  column widths in characters
     */
    public function addSheet(string $name, array $rows, array $widths = []): void
    {
        $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name));
        $name = mb_substr($name !== '' ? $name : 'Sheet', 0, 31);
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths];
    }

    public function save(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The zip extension is needed to write spreadsheets.');
        }
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The spreadsheet could not be written.');
        }

        $count = count($this->sheets);
        $overrides = '';
        $sheetEntries = '';
        $relEntries = '';
        foreach ($this->sheets as $i => $sheet) {
            $n = $i + 1;
            $overrides .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $sheetEntries .= '<sheet name="'.self::esc($sheet['name']).'" sheetId="'.$n.'" r:id="rId'.$n.'"/>';
            $relEntries .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', $this->sheetXml($sheet['rows'], $sheet['widths']));
        }
        $relEntries .= '<Relationship Id="rId'.($count + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$overrides.'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheetEntries.'</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$relEntries.'</Relationships>');
        $zip->addFromString('xl/styles.xml', self::stylesXml());
        $zip->close();
    }

    /**
     * @param  list<list<array{0: string|float|int|null, 1: int}>>  $rows
     * @param  list<float>  $widths
     */
    private function sheetXml(array $rows, array $widths): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetViews><sheetView workbookViewId="0"><pane xSplit="1" ySplit="1" topLeftCell="B2" activePane="bottomRight" state="frozen"/></sheetView></sheetViews>';
        if ($widths !== []) {
            $xml .= '<cols>';
            foreach ($widths as $i => $width) {
                $xml .= '<col min="'.($i + 1).'" max="'.($i + 1).'" width="'.round($width, 1).'" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }
        $xml .= '<sheetData>';
        foreach ($rows as $r => $cells) {
            $xml .= '<row r="'.($r + 1).'">';
            foreach ($cells as $c => [$value, $style]) {
                if ($value === null || $value === '') {
                    continue;
                }
                $ref = self::column($c).($r + 1);
                $xml .= is_int($value) || is_float($value)
                    ? '<c r="'.$ref.'" s="'.$style.'"><v>'.self::number($value).'</v></c>'
                    : '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::esc((string) $value).'</t></is></c>';
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function stylesXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="3"><numFmt numFmtId="164" formatCode="&quot;$&quot;#,##0.00;(&quot;$&quot;#,##0.00)"/>'
            .'<numFmt numFmtId="165" formatCode="0.0%"/><numFmt numFmtId="166" formatCode="0.00&quot;x&quot;"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="6">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="166" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    }

    /** 0 => A, 25 => Z, 26 => AA. */
    private static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private static function number(int|float $value): string
    {
        if (is_int($value)) {
            return (string) $value;
        }

        return is_finite($value) ? rtrim(rtrim(sprintf('%.10F', $value), '0'), '.') : '0';
    }

    private static function esc(string $text): string
    {
        // Control characters other than tab and newline aren't allowed in XML.
        return htmlspecialchars((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $text), ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
