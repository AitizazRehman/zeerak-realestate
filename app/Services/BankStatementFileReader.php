<?php

namespace App\Services;

use DOMDocument;
use DOMXPath;
use RuntimeException;
use ZipArchive;

class BankStatementFileReader
{
    const MAX_ROWS = 50000;

    public function preview($path, $extension, $headerRow = 1, $limit = 12)
    {
        $rows = $this->read($path, $extension);

        if (count($rows) < $headerRow) {
            throw new RuntimeException('The selected header row does not exist in this file.');
        }

        $headers = $this->normalizeHeaders($rows[$headerRow - 1]);
        $data = array_slice($rows, $headerRow, $limit);

        return [
            'headers' => $headers,
            'rows' => $this->padRows($data, count($headers)),
            'detected_rows' => max(count($rows) - $headerRow, 0),
        ];
    }

    public function dataRows($path, $extension, $headerRow = 1)
    {
        $rows = $this->read($path, $extension);

        if (count($rows) < $headerRow) {
            throw new RuntimeException('The selected header row does not exist in this file.');
        }

        $headers = $this->normalizeHeaders($rows[$headerRow - 1]);
        $data = array_slice($rows, $headerRow);

        return [
            'headers' => $headers,
            'rows' => $this->padRows($data, count($headers)),
        ];
    }

    private function read($path, $extension, $limit = null)
    {
        $extension = strtolower($extension);

        if ($extension === 'csv' || $extension === 'txt') {
            return $this->readCsv($path, $limit);
        }

        if ($extension === 'xlsx') {
            return $this->readXlsx($path, $limit);
        }

        throw new RuntimeException('Unsupported bank statement format. Use CSV or XLSX.');
    }

    private function readCsv($path, $limit = null)
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            throw new RuntimeException('Unable to read the uploaded bank statement.');
        }

        $sample = fgets($handle);
        rewind($handle);

        $delimiter = $this->detectDelimiter($sample ?: '');
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            $row = array_map([$this, 'cleanCell'], $row);

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS) {
                fclose($handle);
                throw new RuntimeException('Bank statement exceeds the 50,000 row safety limit.');
            }

            if ($limit && count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function detectDelimiter($line)
    {
        $candidates = ["," => 0, ";" => 0, "\t" => 0, "|" => 0];

        foreach ($candidates as $delimiter => $count) {
            $candidates[$delimiter] = substr_count($line, $delimiter);
        }

        arsort($candidates);
        $delimiter = key($candidates);

        return $candidates[$delimiter] > 0 ? $delimiter : ',';
    }

    private function readXlsx($path, $limit = null)
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('PHP ZipArchive is required to read XLSX bank statements.');
        }

        if (!class_exists(DOMDocument::class)) {
            throw new RuntimeException('PHP DOM/XML extension is required to read XLSX bank statements.');
        }

        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('The XLSX file could not be opened.');
        }

        try {
            $sharedStrings = $this->readSharedStrings($zip);
            $sheetPath = $this->firstWorksheetPath($zip);
            $sheetXml = $zip->getFromName($sheetPath);

            if ($sheetXml === false) {
                throw new RuntimeException('The first worksheet could not be read from the XLSX file.');
            }

            $dom = new DOMDocument();
            $dom->loadXML($sheetXml, LIBXML_NONET | LIBXML_COMPACT);
            $xpath = new DOMXPath($dom);
            $rowNodes = $xpath->query('//*[local-name()="sheetData"]/*[local-name()="row"]');
            $rows = [];

            foreach ($rowNodes as $rowNode) {
                $row = [];

                foreach ($xpath->query('./*[local-name()="c"]', $rowNode) as $cellNode) {
                    $reference = $cellNode->getAttribute('r');
                    $index = $this->columnIndex($reference);
                    $type = $cellNode->getAttribute('t');
                    $valueNode = $xpath->query('./*[local-name()="v"]', $cellNode)->item(0);
                    $value = $valueNode ? $valueNode->nodeValue : '';

                    if ($type === 's') {
                        $value = isset($sharedStrings[(int) $value]) ? $sharedStrings[(int) $value] : '';
                    } elseif ($type === 'inlineStr') {
                        $parts = [];
                        foreach ($xpath->query('.//*[local-name()="is"]//*[local-name()="t"]', $cellNode) as $textNode) {
                            $parts[] = $textNode->nodeValue;
                        }
                        $value = implode('', $parts);
                    } elseif ($type === 'b') {
                        $value = $value === '1' ? 'TRUE' : 'FALSE';
                    }

                    while (count($row) <= $index) {
                        $row[] = '';
                    }

                    $row[$index] = $this->cleanCell($value);
                }

                if ($this->rowIsEmpty($row)) {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) > self::MAX_ROWS) {
                    throw new RuntimeException('Bank statement exceeds the 50,000 row safety limit.');
                }

                if ($limit && count($rows) >= $limit) {
                    break;
                }
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function readSharedStrings(ZipArchive $zip)
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $dom = new DOMDocument();
        $dom->loadXML($xml, LIBXML_NONET | LIBXML_COMPACT);
        $xpath = new DOMXPath($dom);
        $strings = [];

        foreach ($xpath->query('//*[local-name()="si"]') as $item) {
            $parts = [];

            foreach ($xpath->query('.//*[local-name()="t"]', $item) as $textNode) {
                $parts[] = $textNode->nodeValue;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    private function firstWorksheetPath(ZipArchive $zip)
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml === false || $relsXml === false) {
            return 'xl/worksheets/sheet1.xml';
        }

        $workbook = new DOMDocument();
        $workbook->loadXML($workbookXml, LIBXML_NONET | LIBXML_COMPACT);
        $xpath = new DOMXPath($workbook);
        $sheet = $xpath->query('//*[local-name()="sheets"]/*[local-name()="sheet"]')->item(0);

        if (!$sheet) {
            throw new RuntimeException('The XLSX workbook has no worksheets.');
        }

        $relationshipId = '';

        foreach ($sheet->attributes as $attribute) {
            if ($attribute->localName === 'id') {
                $relationshipId = $attribute->nodeValue;
                break;
            }
        }

        if (!$relationshipId) {
            return 'xl/worksheets/sheet1.xml';
        }

        $rels = new DOMDocument();
        $rels->loadXML($relsXml, LIBXML_NONET | LIBXML_COMPACT);
        $relXpath = new DOMXPath($rels);

        foreach ($relXpath->query('//*[local-name()="Relationship"]') as $relationship) {
            if ($relationship->getAttribute('Id') !== $relationshipId) {
                continue;
            }

            $target = str_replace('\\', '/', $relationship->getAttribute('Target'));

            if (strpos($target, '/') === 0) {
                return ltrim($target, '/');
            }

            while (strpos($target, '../') === 0) {
                $target = substr($target, 3);
            }

            return 'xl/' . ltrim($target, '/');
        }

        return 'xl/worksheets/sheet1.xml';
    }

    private function columnIndex($reference)
    {
        if (!preg_match('/^([A-Z]+)/i', $reference, $matches)) {
            return 0;
        }

        $letters = strtoupper($matches[1]);
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
    }

    private function normalizeHeaders(array $headers)
    {
        $result = [];

        foreach ($headers as $index => $header) {
            $label = trim((string) $header);

            if ($label === '') {
                $label = 'Column ' . $this->columnLetters($index);
            }

            $result[] = [
                'index' => $index,
                'label' => $label,
            ];
        }

        return $result;
    }

    private function columnLetters($index)
    {
        $number = $index + 1;
        $letters = '';

        while ($number > 0) {
            $remainder = ($number - 1) % 26;
            $letters = chr(65 + $remainder) . $letters;
            $number = (int) (($number - 1) / 26);
        }

        return $letters;
    }

    private function padRows(array $rows, $width)
    {
        return array_map(function ($row) use ($width) {
            $row = array_values($row);

            while (count($row) < $width) {
                $row[] = '';
            }

            return array_slice($row, 0, $width);
        }, $rows);
    }

    public function cleanCell($value)
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', (string) $value);
        return trim($value);
    }

    private function rowIsEmpty(array $row)
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }
}
