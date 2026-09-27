<?php

namespace Tests\Unit;

use App\Services\BankStatementFileReader;
use PHPUnit\Framework\TestCase;
use ZipArchive;

class BankStatementFileReaderTest extends TestCase
{
    public function test_csv_preview_detects_delimiter_header_row_and_full_row_count()
    {
        $path = tempnam(sys_get_temp_dir(), 'bankcsv_');
        file_put_contents($path, "Statement export\nDate;Credit;Debit;Reference\n2026-09-01;1500;;DEP-1\n2026-09-02;;250;ATM-1\n");

        try {
            $preview = (new BankStatementFileReader)->preview($path, 'csv', 2, 1);

            $this->assertSame(['Date', 'Credit', 'Debit', 'Reference'], array_column($preview['headers'], 'label'));
            $this->assertSame(2, $preview['detected_rows']);
            $this->assertCount(1, $preview['rows']);
            $this->assertSame(['2026-09-01', '1500', '', 'DEP-1'], $preview['rows'][0]);
        } finally {
            @unlink($path);
        }
    }

    public function test_xlsx_preview_reads_shared_strings_and_numeric_cells()
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ZipArchive is not installed.');
        }

        $path = tempnam(sys_get_temp_dir(), 'bankxlsx_');
        @unlink($path);
        $path .= '.xlsx';

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($path, ZipArchive::CREATE) === true);

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            .'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Statement" sheetId="1" r:id="rId1"/></sheets></workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'</Relationships>');

        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="6" uniqueCount="6">'
            .'<si><t>Date</t></si><si><t>Credit</t></si><si><t>Debit</t></si><si><t>Reference</t></si>'
            .'<si><t>2026-09-01</t></si><si><t>DEP-1</t></si></sst>');

        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>4</v></c><c r="B2"><v>1500.25</v></c><c r="D2" t="s"><v>5</v></c></row>'
            .'</sheetData></worksheet>');

        $zip->close();

        try {
            $preview = (new BankStatementFileReader)->preview($path, 'xlsx', 1, 5);

            $this->assertSame(['Date', 'Credit', 'Debit', 'Reference'], array_column($preview['headers'], 'label'));
            $this->assertSame(1, $preview['detected_rows']);
            $this->assertSame(['2026-09-01', '1500.25', '', 'DEP-1'], $preview['rows'][0]);
        } finally {
            @unlink($path);
        }
    }
}
