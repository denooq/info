<?php
declare(strict_types=1);

use Customs\AI\Mapper;
use Customs\AI\MistralClient;
use Customs\AI\SourceManifest;
use Customs\Config;
use Customs\Domain\GraphMappingContract;
use Customs\Domain\Registry;

function graphMappingResponse(array $mapping): array
{
    return ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => json_encode($mapping, JSON_UNESCAPED_UNICODE)]]], 'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 20]], JSON_UNESCAPED_UNICODE)];
}

test('missing graph results receive no more than two repair calls', function (): void {
    $manifest = (new SourceManifest())->build([['documentId' => str_repeat('e', 32), 'name' => 'invoice.pdf', 'contentHash' => 'hash-e', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice ETHYL ACETATE']]]], 'sheets' => []]], 'session-missing-graph');
    $calls = 0;
    $mapper = new Mapper(new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), function () use (&$calls): array { $calls++; return graphMappingResponse(emptyGraphMapping()); }));
    $result = $mapper->request($manifest);
    same(3, $calls); same(2, $result['repairs']);
    truth(in_array('incomplete', array_column($result['mapping']['results'], 'status'), true));
});

test('bounded windows mark absence as incomplete when source coverage was truncated', function (): void {
    $source = ['documentId' => str_repeat('d', 32), 'name' => 'bundle.pdf', 'contentHash' => 'hash-d', 'pages' => [['page' => 1, 'blocks' => []]], 'sheets' => []];
    for ($i = 0; $i < 30; $i++) $source['pages'][0]['blocks'][] = ['id' => 'p1-b' . $i, 'text' => str_repeat('Text ' . $i . ' ', 700)];
    $manifest = (new SourceManifest())->build([$source], 'session-window');
    $calls = 0;
    $client = new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), function (string $url, array $body) use (&$calls): array {
        $calls++; $input = json_decode($body['messages'][1]['content'], true, 256, JSON_THROW_ON_ERROR);
        $response = emptyGraphMapping();
        $response['results'][] = ['graphId' => '15', 'itemKey' => null, 'status' => 'not_found', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => $input['searchWindow']['sourceIds'], 'reason' => 'checked this window'];
        return graphMappingResponse($response);
    });
    $mapper = new Mapper($client);
    $plan = $mapper->plan($manifest, 180000, 1);
    same(1, count($plan)); truth(!$plan[0]['completeBundle']); truth($plan[0]['omittedSourceCount'] > 0);
    $result = $mapper->request($manifest, [], ['window' => $plan[0], 'maxContextBytes' => 180000, 'maxRepairs' => 0]);
    same(1, $calls);
    $graph15 = array_values(array_filter($result['mapping']['results'], fn(array $row): bool => $row['graphId'] === '15'))[0];
    same('incomplete', $graph15['status']);
});

function emptyGraphMapping(): array
{
    return ['schemaVersion' => 'graph-mapping-v2', 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []];
}

test('Mapper request contains all graphs, priorities and the complete source manifest', function (): void {
    $sourceId = SourceManifest::sourceId('session-a', str_repeat('a', 32), 'hash-a', ['page', 1, 'block', 'p1-b0']);
    $sources = [['documentId' => str_repeat('a', 32), 'name' => 'invoice.pdf', 'contentHash' => 'hash-a', 'documentKind' => '04021', 'classifierVersion' => 'v1', 'sourceRole' => 'invoice', 'allowsNewGoods' => true, 'pages' => [['page' => 1, 'language' => 'en', 'blocks' => [['id' => 'p1-b0', 'sourceId' => $sourceId, 'text' => 'ETHYL ACETATE USD 37454.40']]]], 'sheets' => []]];
    $manifest = (new SourceManifest())->build($sources, 'session-a');
    $payload = emptyGraphMapping();
    $payload['items'][] = ['itemKey' => 'goods-1', 'description' => 'ETHYL ACETATE', 'evidence' => [['sourceId' => $sourceId, 'quote' => 'ETHYL ACETATE']]];

    $mapper = new Mapper(new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), function (string $url, array $body) use ($payload, $sourceId): array {
        same('https://api.mistral.ai/v1/chat/completions', $url);
        same('mistral-small-2603', $body['model']);
        same('json_schema', $body['response_format']['type']);
        same('customs_graph_mapping_v2', $body['response_format']['json_schema']['name']);
        $input = json_decode($body['messages'][1]['content'], true, 256, JSON_THROW_ON_ERROR);
        same('source-manifest-v1', $input['sourceManifestVersion']);
        same(57, count($input['registry']['positions']));
        $graph20 = array_values(array_filter($input['registry']['positions'], fn(array $p): bool => $p['id'] === '20'))[0];
        same((new Customs\Domain\GraphRegistry())->graph('20')['priorities'], $graph20['priorities']);
        truth(count($input['registry']['auxiliaryDtsFields']) > 0);
        same(1, count($input['sources']));
        same($sourceId, $input['sources'][0]['sourceId']);
        same('invoice.pdf', $input['sources'][0]['name']);
        same('ETHYL ACETATE USD 37454.40', $input['sources'][0]['text']);
        same('document-stable-1', $input['knownDocumentRecords'][0]['key']);
        truth(!array_key_exists('fields', $input), 'The request must not be divided by technical field scopes.');
        truth(str_contains($body['messages'][0]['content'], 'всех переданных граф'));
        truth(str_contains($body['messages'][0]['content'], 'Приоритеты задают порядок внимания'));
        truth(str_contains($body['messages'][0]['content'], 'Не возвращай код ТН ВЭД в query'));
        return graphMappingResponse($payload);
    }));
    $result = $mapper->request($manifest, [], ['maxRepairs' => 0, 'knownDocumentRecords' => [['key' => 'document-stable-1', 'values' => []]]]);
    same('ETHYL ACETATE', $result['mapping']['items'][0]['description']);
    same(['prompt_tokens' => 10, 'completion_tokens' => 20], $result['usage']);
    same(Mapper::PROMPT_VERSION, $result['promptVersion']);
});

test('Mapper stops after two bounded citation repairs and reports an incomplete result', function (): void {
    $manifest = (new SourceManifest())->build([['documentId' => str_repeat('b', 32), 'name' => 'cmr.pdf', 'contentHash' => 'hash-b', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Gross 27860 kg']]]], 'sheets' => []]], 'session-b');
    $payload = emptyGraphMapping();
    $payload['items'][] = ['itemKey' => 'goods-1', 'description' => 'China', 'evidence' => [['sourceId' => 'src_' . str_repeat('f', 32), 'quote' => 'China']]];
    $calls = 0;
    $mapper = new Mapper(new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), function () use ($payload, &$calls): array { $calls++; return graphMappingResponse($payload); }));
    $result = $mapper->request($manifest);
    same(3, $calls);
    same('EVIDENCE_INVALID', $result['failureCode']);
    truth(in_array('incomplete', array_column($result['mapping']['results'], 'status'), true));
});

test('Mapper reports provider truncation as incomplete without a repair loop', function (): void {
    $manifest = (new SourceManifest())->build([['documentId' => str_repeat('c', 32), 'name' => 'invoice.pdf', 'contentHash' => 'hash-c', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice']]]], 'sheets' => []]], 'session-c');
    $mapper = new Mapper(new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), fn() => ['status' => 200, 'body' => json_encode(['choices' => [['finish_reason' => 'length', 'message' => ['content' => '{}']]]]) ]));
    $result = $mapper->request($manifest);
    same(1, $result['calls']);
    same('MAPPING_OUTPUT_LIMIT', $result['failureCode']);
    truth(in_array('incomplete', array_column($result['mapping']['results'], 'status'), true));
});

test('source windows order official primary document types before their fallbacks', function (): void {
    $manifest = (new SourceManifest())->build([
        ['documentId' => str_repeat('f', 32), 'name' => 'contract.pdf', 'contentHash' => 'hash-f', 'documentKind' => '03011', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Contract data']]]], 'sheets' => []],
        ['documentId' => str_repeat('1', 32), 'name' => 'invoice.pdf', 'contentHash' => 'hash-1', 'documentKind' => '04021', 'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => 'Invoice data']]]], 'sheets' => []],
    ], 'session-priority');
    $mapper = new Mapper(new MistralClient(new Config(['mistral_api_key' => 'test-placeholder']), function (string $url, array $body): array {
        $input = json_decode($body['messages'][1]['content'], true, 256, JSON_THROW_ON_ERROR);
        same('04021', $input['sources'][0]['documentKind']);
        same('03011', $input['sources'][1]['documentKind']);
        return graphMappingResponse(emptyGraphMapping());
    }));
    $mapper->request($manifest, [], ['maxRepairs' => 0]);
});
