<?php
declare(strict_types=1);

use Customs\AI\CandidateMerger;
use Customs\Domain\Draft;
use Customs\Domain\Registry;
use Customs\Domain\TnvedCatalogue;
use Customs\Domain\TnvedSuggestions;

test('empty unmatched and existing TN VED values always retain complete source metadata', function (): void {
    $service=new TnvedSuggestions();
    foreach([''=>'missing_name','неттакоготовара xyz123'=>'not_found'] as $description=>$expected) {
        $state=sessionFixture()->create()['state'];$state['draft']['goods'][0]['description']=$description;
        $state=$service->apply($state);$address='goods/goods-1/commodityCode';$meta=$state['draft']['fieldStates'][$address];
        same($expected,$meta['tnved']['status']);same([],$meta['evidenceIds']??null);same('unknown',$meta['status']??null);same(false,$meta['conflict']??null);
        // Legacy sessions may have cached the lookup before source metadata was populated.
        unset($state['draft']['fieldStates'][$address]['evidenceIds'],$state['draft']['fieldStates'][$address]['status']);
        $state=$service->apply($state);same([],$state['draft']['fieldStates'][$address]['evidenceIds']??null);
        same('unknown',$state['draft']['fieldStates'][$address]['status']??null);same(null,$state['draft']['goods'][0]['commodityCode']);same([],$state['candidates']);
    }
    $state=sessionFixture()->create()['state'];$state['draft']['goods'][0]['description']='Этилацетат';$state['draft']['goods'][0]['commodityCode']='2915310000';
    $state=$service->apply($state);same('2915310000',$state['draft']['goods'][0]['commodityCode']);same([],$state['draft']['fieldStates']['goods/goods-1/commodityCode']['evidenceIds']??null);
});

test('TN VED catalogue preserves terminal codes, leading zeros, source metadata and the supplied source hash', function (): void {
    $catalogue = new TnvedCatalogue();
    same(18014, $catalogue->metadata()['record_count']); same(13294, $catalogue->metadata()['leaf_count']);
    same(hash_file('sha256', dirname(__DIR__, 2) . '/reference/TNVED/tnved.json'), $catalogue->metadata()['source_sha256']);
    same('0101210000', $catalogue->entry('0101210000')['code']);
    same(null, $catalogue->entry('0101')); same(null, $catalogue->entry('29153100')); same(null, $catalogue->entry('9999999999'));
    $entry = $catalogue->entry('2915310000');
    same('этилацетат', $entry['name']); same(20, $entry['page']); truth(str_starts_with($entry['sourceUrl'], 'https://eec.eaeunion.org/'));
});

test('name search auto-selects an exact chemical but never a top-ranked generic glue or unknown item', function (): void {
    $catalogue = new TnvedCatalogue();
    same('2915310000', $catalogue->search('ЭТИЛАЦЕТАТ')['exact']);
    $ambiguous = $catalogue->search('клей', null, 1);
    same(null, $ambiguous['exact']); truth($ambiguous['total'] > 1); same(1, count($ambiguous['matches']));
    $glue = $catalogue->search('клей');
    truth(in_array('3506910000', array_column($glue['matches'], 'code'), true));
    truth(!in_array('2852900003', array_column($glue['matches'], 'code'), true));
    foreach ($glue['matches'] as $match) same(10, strlen($match['code']));
    same([], $catalogue->search('неттакоготовара xyz123')['matches']);
    same(null, $catalogue->search('менее 800 МПа')['exact']);
    $field = (new Registry())->field('goods.commodityCode');
    same('0101210000', (new Customs\Domain\Normalizer())->value($field, '0101 21 000 0', null, ''));
});

test('reference matching is per item, idempotent, updates after edits and preserves manually selected codes', function (): void {
    $state = sessionFixture()->create()['state']; $model = new Draft(new Registry());
    $state['draft']['goods'][0]['description'] = 'Этилацетат';
    $state['draft']['goods'][] = ['id' => 'goods-2', ...$model->row('goods'), 'description' => 'Клей'];
    $service = new TnvedSuggestions(); $state = $service->apply($state);
    same('2915310000', $state['draft']['goods'][0]['commodityCode']);
    same('reference', $state['draft']['fieldStates']['goods/goods-1/commodityCode']['status']);
    same(null, $state['draft']['goods'][1]['commodityCode']);
    same(true, $state['draft']['fieldStates']['goods/goods-2/commodityCode']['conflict']);
    $count = count($state['candidates']); $state = $service->apply($state); same($count, count($state['candidates']));
    $state = (new Customs\Domain\GraphEdits())->edit($state, '31', 'goods-1', 'Клей'); $state = $service->apply($state, ['goods-1']);
    same(null, $state['draft']['goods'][0]['commodityCode']);
    $state = $model->edit($state, 'goods.commodityCode', 'goods-1', '3506910000');
    $state = (new Customs\Domain\GraphEdits())->edit($state, '31', 'goods-1', 'Этилацетат'); $state = $service->apply($state, ['goods-1']);
    same('3506910000', $state['draft']['goods'][0]['commodityCode']);
    same('manual', $state['draft']['fieldStates']['goods/goods-1/commodityCode']['status']);
    same(null, $state['draft']['goods'][1]['commodityCode']);
});

test('translated search terms must cite a real matching product name and cannot supply an invented code', function (): void {
    $state = sessionFixture()->create()['state']; $id = str_repeat('a', 32);
    $source = ['documentId' => $id, 'name' => 'invoice.pdf', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice ETHYL ACETATE']]]]];
    $citation = ['documentId' => $id, 'page' => 1, 'blockId' => 'p1-b0', 'sheet' => null, 'cell' => null, 'quote' => 'ETHYL ACETATE'];
    $payload = ['entities' => [['scope' => 'goods', 'key' => 'ethyl', 'role' => null, 'source' => $citation]], 'candidates' => [['fieldId' => 'goods.description', 'entityKey' => 'ethyl', 'rawValue' => 'ETHYL ACETATE', 'unit' => null, 'source' => $citation]], 'classificationQueries' => [['entityKey' => 'ethyl', 'name' => 'ETHYL ACETATE', 'queryRu' => 'этилацетат', 'source' => $citation]], 'warnings' => []];
    $merger = new CandidateMerger(new Registry());
    $matched = (new TnvedSuggestions())->apply($merger->merge($state, $payload, [$source]));
    same('2915310000', $matched['draft']['goods'][0]['commodityCode']);
    $candidate = array_values(array_filter($matched['candidates'], fn($c) => ($c['origin'] ?? '') === 'tnved'))[0];
    same([$id], $candidate['documentIds']);
    truth(count($candidate['evidenceIds']) >= 2);
    $payload['classificationQueries'][0]['source']['page'] = 99;
    $rejected = (new TnvedSuggestions())->apply($merger->merge($state, $payload, [$source]));
    same(null, $rejected['draft']['goods'][0]['commodityCode']);
    truth(in_array('EVIDENCE_INVALID', array_column($rejected['mappingIssues'], 'code'), true));
    $payload['classificationQueries'][0]['source'] = $citation;
    $payload['classificationQueries'][0]['name'] = 'Unrelated product';
    $rejected = $merger->merge($state, $payload, [$source]);
    same([], $rejected['tnvedQueries'] ?? []);
});

test('directly documented ten-digit codes retain primary provenance instead of being replaced by name guesses', function (): void {
    $state = sessionFixture()->create()['state'];
    $state['draft']['goods'][0]['description'] = 'Клей H.B. Fuller';
    $state['draft']['goods'][0]['commodityCode'] = '3506910000';
    $state['draft']['fieldStates']['goods/goods-1/commodityCode'] = ['status' => 'auto', 'candidateId' => null, 'evidenceIds' => []];
    $state = (new TnvedSuggestions())->apply($state);
    same('3506910000', $state['draft']['goods'][0]['commodityCode']);
    same('auto', $state['draft']['fieldStates']['goods/goods-1/commodityCode']['status']);
    same([], $state['candidates']);
});

test('a search rewrite cannot invent Russian product properties or resolve a generic English name automatically', function (): void {
    $state = sessionFixture()->create()['state'];
    $state['evidence']['name'] = ['documentId' => 'invoice', 'documentName' => 'invoice.pdf', 'page' => 1, 'quote' => 'GLUE'];
    foreach (['Клей', 'GLUE'] as $name) {
        $copy = $state; $copy['draft']['goods'][0]['description'] = $name;
        $copy['tnvedQueries']['goods-1'][TnvedCatalogue::normalize($name)] = ['queryRu' => 'этилацетат', 'evidenceIds' => ['name']];
        $copy = (new TnvedSuggestions())->apply($copy);
        same(null, $copy['draft']['goods'][0]['commodityCode']);
    }
    $issues = (new Customs\Domain\Normalizer())->issues((new Registry())->field('goods.commodityCode'), '9999999999');
    truth(count($issues) > 0);
});

test('an unlisted documentary code requires review and is not silently replaced by name inference', function (): void {
    $state = sessionFixture()->create()['state'];
    $state['draft']['goods'][0]['description'] = 'Этилацетат';
    $address = 'goods/goods-1/commodityCode';
    $state['candidates']['unlisted'] = ['id' => 'unlisted', 'fieldId' => 'goods.commodityCode', 'entityId' => 'goods-1', 'address' => $address, 'value' => '9999999999', 'unit' => null, 'valid' => false, 'issues' => ['Код отсутствует в справочнике.'], 'evidenceIds' => []];
    $state = (new TnvedSuggestions())->apply($state);
    same(null, $state['draft']['goods'][0]['commodityCode']); same(1, count($state['candidates']));
    $state = (new Draft(new Registry()))->edit($state, 'goods.commodityCode', 'goods-1', '9999999999');
    $state = (new TnvedSuggestions())->apply($state);
    same('9999999999', $state['draft']['goods'][0]['commodityCode']);
    same('manual', $state['draft']['fieldStates'][$address]['status']);
});

test('a reference-selected TN VED code is shared by the confirmed PDF, DT and DTS snapshot', function (): void {
    require_once dirname(__DIR__) . '/support/fixtures.php';
    $state = completeDraftState();
    $state['confirmed'] = $state['artifactSet'] = null;
    $state['draft']['goods'][0]['description'] = 'Этилацетат';
    $state['draft']['goods'][0]['commodityCode'] = null;
    unset($state['draft']['fieldStates']['goods/goods-1/commodityCode']);
    $state = (new TnvedSuggestions())->apply($state);
    $state['calculation'] = (new Customs\Domain\Calculator())->calculate($state);
    $snapshot = (new Customs\Export\Snapshot())->confirm($state, $state['calculation']['hash'])['confirmed'];
    $files = (new Customs\Export\XmlProfiles())->serialize($snapshot);
    foreach ($files as $xml) truth(str_contains($xml, '2915310000'));
    $pdf = (new Customs\Export\PdfDeclaration())->render($snapshot['data'], sys_get_temp_dir() . '/customs-tnved-pdf-' . bin2hex(random_bytes(6)));
    truth(str_contains((new Smalot\PdfParser\Parser())->parseContent($pdf)->getText(), '2915310000'));
});
