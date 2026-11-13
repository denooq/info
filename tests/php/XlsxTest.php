<?php
declare(strict_types=1);

use Customs\Documents\XlsxReader;

test('actual spreadsheet is read as cells without duplicating totals or drawings', function (): void {
    $path = dirname(__DIR__, 2) . '/reference/source-documents/example2/2601011274.xlsx';
    $book = (new XlsxReader())->read($path);
    $cells = $book['sheets'][0]['cells'];
    same('3506910000', $cells['G4']['value']);
    same('2975', $cells['I4']['value']);
    same('1200', $cells['L4']['value']);
    same('1248', $cells['M4']['value']);
    same('2844', $cells['Q4']['value']);
    same('Лист1', $cells['L4']['source']['sheet']);
    same('L5', $cells['L5']['source']['cell']);
    truth(!array_key_exists('goods', $book));
    errorCode('XLSX_LIMIT', fn() => (new XlsxReader(100))->read($path));
});

test('spreadsheet formulas without cached values are unknown and macros never execute', function (): void {
    $path=tempnam(sys_get_temp_dir(),'customs-xlsx-');
    try {
        $zip=new ZipArchive(); $zip->open($path,ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml','<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Test" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/></Relationships>');
        $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><f>1+1</f></c><c r="B1"><f>2+2</f><v>4</v></c></row></sheetData></worksheet>');
        $zip->close();
        $result=(new XlsxReader())->read($path)['sheets'][0]['cells'];
        same(null,$result['A1']['value']); same(false,$result['A1']['hasCachedValue']); same('4',$result['B1']['value']);
        $zip->open($path); $zip->addFromString('xl/vbaProject.bin','test macro placeholder'); $zip->close();
        errorCode('XLSX_MACROS',fn()=>(new XlsxReader())->read($path));
    } finally { unlink($path); }
});
