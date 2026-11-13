<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$start = hrtime(true);
$table = (new Customs\Documents\XlsxReader())->read(dirname(__DIR__) . '/reference/source-documents/example2/2601011274.xlsx');
$cells = $table['sheets'][0]['cells'];
foreach (['G4' => '3506910000','I4' => '2975','L4' => '1200','M4' => '1248','Q4' => '2844'] as $address => $expected) {
    if ($cells[$address]['value'] !== $expected) throw new RuntimeException('Cell mismatch: ' . $address);
}
$config = Customs\Config::load();
$pdf = new Mpdf\Mpdf(['tempDir' => $config->get('runtime_dir') . '/mpdf-probe', 'format' => 'A4', 'default_font' => 'dejavusans']);
$pdf->WriteHTML('<h1>Декларация ИМ 40 — Қазақстан</h1><p>Вес брутто: 27 860 кг. Құжаттарды тексеру.</p>');
$bytes = $pdf->Output('', Mpdf\Output\Destination::STRING_RETURN);
if (!str_starts_with($bytes, '%PDF-')) throw new RuntimeException('PDF failed');

// Engine capability probe only. This is not an official declaration schema.
$schema = '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema"><xs:element name="probe" type="xs:decimal"/></xs:schema>';
$doc = new DOMDocument();
$doc->loadXML('<probe>123.45</probe>', LIBXML_NONET);
if (!$doc->schemaValidateSource($schema)) throw new RuntimeException('XSD engine failed');
echo json_encode([
    'scope' => 'local-XS-memory-probe-not-hosting-acceptance',
    'memory_limit' => ini_get('memory_limit'),
    'peak_bytes' => memory_get_peak_usage(true),
    'elapsed_ms' => round((hrtime(true) - $start) / 1e6),
    'xlsx_cell_count' => $table['cellCount'],
    'xlsx_unpacked_bytes' => $table['unpackedBytes'],
    'decorative_drawings_loaded' => false,
    'unicode_pdf_bytes' => strlen($bytes),
    'xsd_engine_probe' => true,
    'official_declaration_xsd' => 'unavailable',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
