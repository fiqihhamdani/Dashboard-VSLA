<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use SimpleXMLElement;
use ZipArchive;

class ExcelWorkbook
{
    public function read(string $path, array $headers): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('File bukan workbook XLSX yang valid.');
        }
        try {
            if ($zip->numFiles > 100) {
                $this->fail('Workbook terlalu kompleks; maksimal 100 bagian arsip.');
            }
            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += $stat['size'];
                if ($total > 20 * 1024 * 1024 || preg_match('~vbaProject|externalLinks/|embeddings/~i', $stat['name'])) {
                    $this->fail('Ukuran terurai maksimal 20 MB; macro, objek, dan tautan eksternal tidak didukung.');
                }
                if (str_ends_with($stat['name'], '.rels')) {
                    $rels = $this->xml($zip->getFromIndex($i));
                    foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                        if (strtolower((string) $rel['TargetMode']) === 'external' && ! str_ends_with((string) $rel['Type'], '/hyperlink')) {
                            $this->fail('Workbook dengan sumber atau tautan eksternal tidak didukung.');
                        }
                    }
                }
                if (preg_match('~^xl/worksheets/[^/]+\.xml$~', $stat['name'])) {
                    $sheet = $this->xml($zip->getFromIndex($i));
                    if ($sheet->xpath('//*[local-name()="f"]')) {
                        $this->fail('Formula Excel tidak diperbolehkan; tempel sebagai nilai terlebih dahulu.');
                    }
                }
            }
            $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
            $sheets = $workbook->xpath('//*[local-name()="sheet"]');
            if (count($sheets) < 1 || count($sheets) > 2 || (string) $sheets[0]['name'] !== 'Data' || (isset($sheets[1]) && (string) $sheets[1]['name'] !== 'Petunjuk')) {
                $this->fail('Gunakan sheet Data dan, bila ada, sheet Petunjuk dari template.');
            }
            $id = (string) $sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rels = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $target = null;
            foreach ($rels->xpath('//*[local-name()="Relationship"]') as $rel) {
                if ((string) $rel['Id'] === $id) {
                    $target = (string) $rel['Target'];
                }
            }
            if (! is_string($target) || ! preg_match('~^(?:/xl/)?worksheets/[A-Za-z0-9_-]+\.xml$~D', $target)) {
                $this->fail('Referensi sheet Data tidak valid.');
            }
            $strings = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $shared = $this->xml($zip->getFromName('xl/sharedStrings.xml'));
                foreach ($shared->xpath('//*[local-name()="si"]') as $item) {
                    $strings[] = implode('', array_map(strval(...), $item->xpath('.//*[local-name()="t"]')));
                }
            }
            $sheet = $this->xml($zip->getFromName(str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target));
            $rows = [];
            $seen = [];
            foreach ($sheet->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') as $index => $row) {
                $number = (int) $row['r'];
                if ($number < 1 || $number > 501 || isset($seen[$number]) || count($rows) > 500) {
                    $this->fail('Maksimal 500 baris data dengan nomor baris unik dan berurutan.');
                }
                $seen[$number] = true;
                $values = array_fill(0, count($headers), '');
                $columns = [];
                foreach ($row->xpath('./*[local-name()="c"]') as $cell) {
                    if (! preg_match('/^([A-Z]+)([1-9][0-9]*)$/D', (string) $cell['r'], $ref) || (int) $ref[2] !== $number) {
                        $this->fail('Referensi sel tidak valid.');
                    }
                    $column = 0;
                    foreach (str_split($ref[1]) as $letter) {
                        $column = $column * 26 + ord($letter) - 64;
                    }
                    if ($column > count($headers) || isset($columns[$column])) {
                        $this->fail('Jumlah kolom tidak sesuai template atau referensi sel berulang.');
                    }
                    $columns[$column] = true;
                    $type = (string) $cell['t'];
                    $v = $cell->xpath('./*[local-name()="v"]');
                    $value = (string) ($v[0] ?? '');
                    if ($type === 's') {
                        if (! ctype_digit($value) || ! array_key_exists((int) $value, $strings)) {
                            $this->fail('Referensi teks bersama tidak valid.');
                        }
                        $value = $strings[(int) $value];
                    } elseif ($type === 'inlineStr') {
                        $value = implode('', array_map(strval(...), $cell->xpath('.//*[local-name()="t"]')));
                    } elseif (! in_array($type, ['', 'n', 'b', 'str'], true)) {
                        $this->fail('Tipe sel tidak didukung. Gunakan teks atau angka biasa.');
                    }
                    if (mb_strlen($value) > 4096) {
                        $this->fail('Isi sel terlalu panjang (maksimal 4096 karakter).');
                    }
                    $values[$column - 1] = trim($value);
                }
                if ($index === 0) {
                    if ($number !== 1 || $values !== $headers) {
                        $this->fail('Nama dan urutan kolom harus sama persis dengan template.');
                    }
                } elseif (implode('', $values) !== '') {
                    $rows[] = ['number' => $number, 'data' => array_combine($headers, $values)];
                }
            }
            if (! $rows) {
                $this->fail('Sheet Data tidak memiliki baris data.');
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function xml(string|false $content): SimpleXMLElement
    {
        if ($content === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $content)) {
            $this->fail('Struktur XML workbook tidak valid atau memuat deklarasi terlarang.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
            if ($xml === false) {
                $this->fail('XML workbook rusak.');
            }

            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
