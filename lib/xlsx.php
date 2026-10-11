<?php
/* ────────────────────────────────────────────────────────────────
   A very small .xlsx writer.

   An xlsx file is a zip containing a handful of XML files. PHP ships with
   ZipArchive, so the whole format is reachable without Composer, without
   PhpSpreadsheet, and — the part that actually matters here — without
   anything being downloaded. The hall has no internet; a library fetched at
   runtime would simply not arrive.

   It writes what this project needs and nothing more: several sheets, a bold
   frozen header row, set column widths, and three cell types — text, number
   and percentage. Phone numbers go in as TEXT on purpose: left to guess,
   Excel reads 9876543210 as a number and quietly eats a leading zero or
   mangles a +91, which would put wrong phone numbers in an official record.

   Nothing else in the project uses this file, and this file uses nothing
   else in the project. It cannot break anything that already works.
   ──────────────────────────────────────────────────────────────── */

class Xlsx {

    private $sheets = [];

    /**
     * $title   sheet tab name
     * $cols    [['Header', width, 'text'|'num'|'pct'], ...]
     * $rows    list of rows; each row a list of values in column order.
     *          A row may also be the string '' to leave a blank line, or
     *          ['__note' => 'text'] to write one merged-looking note line.
     */
    public function sheet($title, array $cols, array $rows) {
        $this->sheets[] = ['title' => $this->tabName($title), 'cols' => $cols, 'rows' => $rows];
    }

    /* Excel refuses : \ / ? * [ ] in a tab name, and caps it at 31 characters. */
    private function tabName($t) {
        $t = str_replace([':', '\\', '/', '?', '*', '[', ']'], '-', (string) $t);
        $t = trim($t);
        if ($t === '') $t = 'Sheet';
        return mb_substr($t, 0, 31);
    }

    private function esc($s) {
        /* Control characters are illegal in XML 1.0 and would make Excel
           declare the whole file corrupt, so they go before escaping. */
        $s = preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/u', '', (string) $s);
        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function colLetter($i) {           // 0 -> A, 26 -> AA
        $s = '';
        $i++;
        while ($i > 0) { $m = ($i - 1) % 26; $s = chr(65 + $m) . $s; $i = (int) (($i - 1) / 26); }
        return $s;
    }

    private function sheetXml($sheet) {
        $cols = $sheet['cols'];
        $x  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $x .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        /* freeze the header so a 60-row list still shows its column names */
        $x .= '<sheetViews><sheetView workbookViewId="0">'
            . '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>'
            . '</sheetView></sheetViews>';

        $x .= '<cols>';
        foreach ($cols as $i => $c) {
            $w = isset($c[1]) ? (float) $c[1] : 14;
            $x .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols><sheetData>';

        /* header */
        $x .= '<row r="1" ht="20" customHeight="1">';
        foreach ($cols as $i => $c) {
            $x .= '<c r="' . $this->colLetter($i) . '1" s="1" t="inlineStr"><is><t>'
                . $this->esc($c[0]) . '</t></is></c>';
        }
        $x .= '</row>';

        $rowNo = 1;
        foreach ($sheet['rows'] as $row) {
            $rowNo++;

            if ($row === '' || $row === null) { $x .= '<row r="' . $rowNo . '"/>'; continue; }

            if (is_array($row) && isset($row['__note'])) {
                $x .= '<row r="' . $rowNo . '"><c r="A' . $rowNo . '" s="3" t="inlineStr"><is><t>'
                    . $this->esc($row['__note']) . '</t></is></c></row>';
                continue;
            }

            $x .= '<row r="' . $rowNo . '">';
            foreach (array_values($row) as $i => $v) {
                $ref  = $this->colLetter($i) . $rowNo;
                $type = isset($cols[$i][2]) ? $cols[$i][2] : 'text';

                if ($v === null || $v === '') { $x .= '<c r="' . $ref . '" s="0"/>'; continue; }

                if ($type === 'num' && is_numeric($v)) {
                    $x .= '<c r="' . $ref . '" s="0"><v>' . (0 + $v) . '</v></c>';
                } elseif ($type === 'pct' && is_numeric($v)) {
                    /* stored as a fraction with a % format, so Excel can sort
                       and average it like the number it is */
                    $x .= '<c r="' . $ref . '" s="2"><v>' . round($v / 100, 6) . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" s="0" t="inlineStr"><is><t xml:space="preserve">'
                        . $this->esc($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }

        $x .= '</sheetData></worksheet>';
        return $x;
    }

    /* s="0" plain · s="1" bold header · s="2" percentage · s="3" grey note */
    private function stylesXml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
          . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.00%"/></numFmts>'
          . '<fonts count="3">'
          .   '<font><sz val="11"/><name val="Calibri"/></font>'
          .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
          .   '<font><i/><sz val="10"/><color rgb="FF808080"/><name val="Calibri"/></font>'
          . '</fonts>'
          . '<fills count="3">'
          .   '<fill><patternFill patternType="none"/></fill>'
          .   '<fill><patternFill patternType="gray125"/></fill>'
          .   '<fill><patternFill patternType="solid"><fgColor rgb="FF4A2FA8"/>'
          .     '<bgColor indexed="64"/></patternFill></fill>'
          . '</fills>'
          . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
          . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
          . '<cellXfs count="4">'
          .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
          .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1">'
          .     '<alignment vertical="center"/></xf>'
          .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
          .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
          . '</cellXfs>'
          . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
          . '</styleSheet>';
    }

    /* Writes the workbook to $path. Returns [ok, message]. */
    public function save($path) {
        if (!class_exists('ZipArchive')) {
            return [false, 'PHP has no ZipArchive — enable extension=zip in php.ini.'];
        }
        if (!$this->sheets) return [false, 'nothing to write'];

        @unlink($path);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return [false, 'could not create ' . basename($path)];
        }

        $n = count($this->sheets);

        $zip->addFromString('[Content_Types].xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
          . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
          . '<Default Extension="xml" ContentType="application/xml"/>'
          . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
          . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
          . implode('', array_map(function ($i) {
                return '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" '
                     . 'ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            }, range(0, $n - 1)))
          . '</Types>');

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . '<Relationship Id="rId1" Target="xl/workbook.xml" '
          . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument"/>'
          . '</Relationships>');

        $sheetTags = ''; $relTags = '';
        foreach ($this->sheets as $i => $s) {
            $sheetTags .= '<sheet name="' . $this->esc($s['title']) . '" sheetId="' . ($i + 1)
                        . '" r:id="rId' . ($i + 1) . '"/>';
            $relTags   .= '<Relationship Id="rId' . ($i + 1) . '" Target="worksheets/sheet' . ($i + 1) . '.xml" '
                        . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"/>';
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $this->sheetXml($s));
        }

        $zip->addFromString('xl/workbook.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
          . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
          . '<sheets>' . $sheetTags . '</sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
          . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
          . $relTags
          . '<Relationship Id="rId' . ($n + 1) . '" Target="styles.xml" '
          . 'Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles"/>'
          . '</Relationships>');

        $zip->addFromString('xl/styles.xml', $this->stylesXml());

        if (!$zip->close()) return [false, 'could not finish writing the workbook'];
        return [true, ''];
    }
}
