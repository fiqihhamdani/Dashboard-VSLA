<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class ExcelReport
{
    public const LABELS = ['opening' => 'Saldo awal periode', 'balance' => 'Saldo kas akhir', 'savings' => 'Simpanan bersih', 'loans' => 'Pokok pinjaman dicairkan', 'payments' => 'Angsuran diterima', 'overdue' => 'Tunggakan jatuh tempo', 'active_members' => 'Anggota aktif saat ini'];

    public function create(array $dashboard, iterable $ledger, string $group): string
    {
        $summary = [['Laporan Administrasi VSLA'], ['Kelompok', $group], ['Mulai', $dashboard['filter']['start']], ['Akhir', $dashboard['filter']['end']], ['Ringkasan', 'Nilai']];
        foreach (self::LABELS as $key => $label) {
            $summary[] = [$label, $dashboard['summary'][$key]];
        }
        $monthly = [['Bulan', 'Kas masuk', 'Kas keluar', 'Saldo akhir', 'Setoran simpanan', 'Pencairan pinjaman', 'Angsuran diterima']];
        foreach ($dashboard['months'] as $month) {
            $monthly[] = [$month['label'], $month['in'], $month['out'], $month['balance'], $month['deposits'], $month['loans'], $month['payments']];
        }
        $cash = [['Tanggal', 'Kelompok', 'Uraian', 'Kas masuk', 'Kas keluar', 'Saldo'], ['Saldo awal', '', '', 0, 0, $dashboard['summary']['opening']]];
        foreach ($ledger as $row) {
            $cash[] = array_values($row);
        }
        $path = tempnam(storage_path('app/private'), 'vsla-');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Tidak dapat membuat laporan Excel.');
        }
        try {
            $sheets = ['Ringkasan' => $summary, 'Rekap Bulanan' => $monthly, 'Buku Kas' => $cash];
            $types = '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
            $workbook = '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $i = 0;
            foreach ($sheets as $name => $rows) {
                $i++;
                $types .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook .= '<sheet name="'.$name.'" sheetId="'.$i.'" r:id="rId'.$i.'"/>';
                $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
                $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="7" width="25" customWidth="1"/></cols><sheetData>';
                foreach ($rows as $index => $row) {
                    $xml .= '<row r="'.($index + 1).'">';
                    foreach ($row as $column => $value) {
                        $ref = chr(65 + $column).($index + 1);
                        $style = ($name === 'Ringkasan' && ($row[0] ?? '') === 'Anggota aktif saat ini') ? 0 : 1;
                        $xml .= is_int($value) ? '<c r="'.$ref.'" s="'.$style.'"><v>'.$value.'</v></c>' : '<c r="'.$ref.'" t="inlineStr"><is><t xml:space="preserve">'.htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', (string) $value), ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</t></is></c>';
                    }
                    $xml .= '</row>';
                }
                $zip->addFromString('xl/worksheets/sheet'.$i.'.xml', $xml.'</sheetData></worksheet>');
            }
            $types .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
            $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="&quot;Rp&quot; #,##0;[Red]-&quot;Rp&quot; #,##0"/></numFmts><fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            $zip->addFromString('[Content_Types].xml', $types.'</Types>');
            $zip->addFromString('xl/workbook.xml', $workbook.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels', $rels.'</Relationships>');
            $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        } finally {
            $zip->close();
        }

        return $path;
    }
}
