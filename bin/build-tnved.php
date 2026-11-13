<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/src/bootstrap.php';

$root = dirname(__DIR__);
$source = $root . '/reference/TNVED/tnved.json';
$raw = file_get_contents($source);
$sourceHash = hash('sha256', $raw);
$data = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
unset($raw);
$directory = $root . '/resources/tnved';
if (!is_dir($directory)) mkdir($directory, 0755);
$tariffDirectory = $directory . '/tariffs';
if (!is_dir($tariffDirectory)) mkdir($tariffDirectory, 0755);
$all = []; $leaves = []; $sources = [];
foreach ($data['items'] as $item) {
    $code = $item['code'];
    if (!is_string($code) || !preg_match('/^[0-9]{2,10}$/D', $code) || isset($all[$code]) || trim($item['name']) === '' || strlen($code) !== $item['code_length']) throw new RuntimeException('Invalid or duplicate TN VED entry');
    $all[$code] = true;
}
foreach ($data['items'] as $item) {
    if ($item['parent_code'] !== null && !isset($all[$item['parent_code']])) throw new RuntimeException('Unknown TN VED parent');
    if (!$item['is_leaf']) continue;
    if (!preg_match('/^[0-9]{10}$/D', $item['code']) || !is_int($item['source_page']) || $item['source_page'] < 1 || !str_starts_with($item['source_url'], 'https://eec.eaeunion.org/')) throw new RuntimeException('Invalid terminal code or source');
    $sourceId = array_search($item['source_url'], $sources, true);
    if ($sourceId === false) { $sourceId = count($sources); $sources[] = $item['source_url']; }
    $leaves[] = [$item['code'], $item['name'], $item['full_name'], $sourceId, $item['source_page']];
}
if (count($all) !== $data['metadata']['record_count'] || count($leaves) !== $data['metadata']['leaf_count']) throw new RuntimeException('TN VED counts differ from metadata');
// Keep tariffs in small group files: a web request must not decode the whole source book.
$groups = [];
$unitCodes = array_fill_keys(array_column($data['unit_dictionary'], 'code'), true);
$noteIds = array_fill_keys(array_column($data['tariff_notes'], 'id'), true);
foreach ($data['items'] as $index => $item) if ($item['is_leaf']) $groups[$item['group_code']][] = $index;
foreach ($groups as $group => $indexes) {
    $tariffs = [];
    foreach ($indexes as $index) {
        $item = $data['items'][$index];
        if (!isset($item['units'], $item['import_duty']) || $item['import_duty']['base_rule'] === null || count($item['units']['additional_unit_codes']) > 2) throw new RuntimeException('Missing or unsupported tariff/unit data');
        if (\Customs\Domain\TariffEngine::rule(\Customs\Domain\TariffEngine::parameters($item['import_duty']['base_rule'])) != $item['import_duty']['base_rule']) throw new RuntimeException('Tariff expression cannot be represented without loss');
        foreach ($item['units']['additional_unit_codes'] as $unit) if (!isset($unitCodes[$unit])) throw new RuntimeException('Unknown additional unit');
        foreach ($item['import_duty']['note_ids'] as $noteId) if (!isset($noteIds[$noteId])) throw new RuntimeException('Unknown tariff note');
        $tariffs[$item['code']] = ['units' => $item['units'], 'duty' => $item['import_duty'], 'classificationContext' => $item['classification_context'] ?? [], 'sourceDocumentId' => $item['source_document_id'] ?? null, 'sourceUrl' => $item['source_url'], 'sourcePage' => $item['source_page']];
    }
    $groupPath = $tariffDirectory . '/' . $group . '.json';
    $groupJson = json_encode(['source_sha256' => $sourceHash, 'items' => $tariffs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (file_put_contents($groupPath . '.tmp', $groupJson) !== strlen($groupJson) || !rename($groupPath . '.tmp', $groupPath)) throw new RuntimeException('Cannot save tariff group');
}
$documents = [];
foreach ($data['source_documents'] ?? [] as $document) $documents[$document['id']] = array_intersect_key($document, array_flip(['id','title','url','sha256']));
$tariffMeta = ['source_sha256' => $sourceHash, 'coverage' => $data['metadata']['coverage'] ?? [], 'units' => $data['unit_dictionary'] ?? [], 'notes' => $data['tariff_notes'] ?? [], 'documents' => $documents];
$tariffMetaJson = json_encode($tariffMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/tariff-context.v1.json.tmp', $tariffMetaJson) !== strlen($tariffMetaJson) || !rename($directory . '/tariff-context.v1.json.tmp', $directory . '/tariff-context.v1.json')) throw new RuntimeException('Cannot save tariff context');
$metadata = array_intersect_key($data['metadata'], array_flip(['schema_version','title','language','retrieved_at','source_url','record_count','leaf_count','group_count','notes']));
$output = ['metadata' => [...$metadata, 'source_sha256' => $sourceHash, 'columns' => ['code','name','fullName','sourceIndex','page']], 'sources' => $sources, 'items' => $leaves];
$path = $directory . '/catalog.v1.json';
$bytes = file_put_contents($path . '.tmp', json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
if ($bytes === false || !rename($path . '.tmp', $path)) throw new RuntimeException('Cannot save TN VED catalog');
$codes = json_encode(['source_sha256' => $sourceHash, 'codes' => array_column($leaves, 0)], JSON_THROW_ON_ERROR);
if (file_put_contents($directory . '/codes.v1.json.tmp', $codes) !== strlen($codes) || !rename($directory . '/codes.v1.json.tmp', $directory . '/codes.v1.json')) throw new RuntimeException('Cannot save TN VED code index');
$catalogPath = $root . '/resources/registry/catalogs.v1.json';
$catalogs = json_decode(file_get_contents($catalogPath), true, 32, JSON_THROW_ON_ERROR);
$catalogs['lists']['nomenclatureUnits'] = array_map(fn($unit) => ['code'=>$unit['code'], 'label'=>$unit['name'].' ('.$unit['symbol'].')', 'source'=>'reference/TNVED/tnved.json', 'status'=>'supplied-reference'], $data['unit_dictionary']);
foreach (['goods.additionalUnit','goods.secondaryAdditionalUnit','goods.dutyUnitCode'] as $field) $catalogs['fieldCatalogs'][$field] = 'nomenclatureUnits';
$catalogJson = json_encode($catalogs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR) . PHP_EOL;
$catalogJson = preg_replace_callback('/^( +)/m', fn($match) => str_repeat(' ', intdiv(strlen($match[0]), 2)), $catalogJson);
if (file_put_contents($catalogPath . '.tmp', $catalogJson) !== strlen($catalogJson) || !rename($catalogPath . '.tmp', $catalogPath)) throw new RuntimeException('Cannot save tariff unit list');
echo json_encode(['records' => count($all), 'terminalCodes' => count($leaves), 'bytes' => $bytes, 'peakBytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR) . PHP_EOL;
