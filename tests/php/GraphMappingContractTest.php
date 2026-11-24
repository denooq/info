<?php
declare(strict_types=1);

test('graph mapping contract joins all 57 positions with source priorities and owners', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $source = new Customs\Domain\GraphRegistry();
    same(57, count($contract->positions()));
    same(54, count(array_filter($contract->positions(), fn($position) => $position['parentGraph'] === null)));
    $owners = [];
    foreach ($contract->positions() as $id => $position) {
        same((string)$id, $position['id']);
        same($source->graph((string)$id)['priorities'], $position['priorities']);
        foreach ($position['owners'] as $owner) $owners[$owner] = true;
    }
    foreach (['document', 'reference', 'profile', 'calculated', 'not_applicable'] as $owner) truth(isset($owners[$owner]), 'Missing owner: ' . $owner);
    same('profile', $contract->position('1')['bindings'][0]['owner']);
    same('calculated', $contract->position('12')['owners'][0]);
    same('document', $contract->position('23')['bindings'][0]['owner']);
    same('reference', $contract->position('23')['bindings'][1]['owner']);
    same('not_applicable', $contract->position('3')['owners'][0]);
    same('country-name', $contract->position('15')['positionValue']['type']);
    truth(count($contract->auxiliaryDtsFields()) > 0, 'DTS facts are included');
    truth(in_array('cost.amount', array_column($contract->auxiliaryDtsFields(), 'fieldId'), true));
});

test('graph mapping contract accepts typed graph and DTS facts with checked source references', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $sourceId = 'src_' . str_repeat('a', 32);
    $proof = ['sourceId' => $sourceId, 'quote' => 'ETHYL ACETATE'];
    $value = ['fieldId' => 'goods.description', 'valueType' => 'string', 'rawValue' => 'ETHYL ACETATE', 'normalizedValue' => 'ETHYL ACETATE', 'unit' => null, 'currency' => null, 'evidence' => [$proof]];
    $response = [
        'schemaVersion' => 'graph-mapping-v2',
        'items' => [['itemKey' => 'goods-a', 'description' => 'ETHYL ACETATE', 'evidence' => [$proof]]],
        'results' => [['graphId' => '31', 'itemKey' => 'goods-a', 'status' => 'found', 'values' => [$value], 'positionValue' => null, 'searchedSourceIds' => [$sourceId], 'reason' => null]],
        'documentRecords' => [],
        'dtsFacts' => [['matrixId' => 'DTS-13', 'itemKey' => 'goods-a', 'participantRole' => null, 'value' => ['fieldId' => 'cost.amount', 'valueType' => 'decimal', 'rawValue' => 'USD 120.00', 'normalizedValue' => '120.00', 'unit' => null, 'currency' => 'USD', 'evidence' => [['sourceId' => $sourceId, 'quote' => 'USD 120.00']]]]],
        'referenceSelections' => [],
        'catalogRequests' => [],
        'contextRequests' => [],
        'warnings' => [],
    ];
    $contract->validateResponse($response, [$sourceId]);
});

test('graph mapping DTS participant facts require an independent explicit party role', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $sourceId = 'src_' . str_repeat('d', 32);
    $proof = ['sourceId' => $sourceId, 'quote' => 'Seller: Example Ltd'];
    $value = ['fieldId' => 'party.name', 'valueType' => 'string', 'rawValue' => 'Example Ltd', 'normalizedValue' => 'Example Ltd', 'unit' => null, 'currency' => null, 'evidence' => [$proof]];
    $response = ['schemaVersion' => 'graph-mapping-v2', 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [
        ['matrixId' => 'DTS-1-2', 'itemKey' => null, 'participantRole' => 'seller', 'value' => $value],
        ['matrixId' => 'DTS-1-2', 'itemKey' => null, 'participantRole' => 'buyer', 'value' => [...$value, 'rawValue' => 'Buyer Ltd', 'normalizedValue' => 'Buyer Ltd']],
    ], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []];
    $contract->validateResponse($response, [$sourceId]);
    $response['dtsFacts'][0]['participantRole'] = null;
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($response, [$sourceId]));
});

test('graph mapping contract rejects unknown graphs, wrong bindings and mismatched value types', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $sourceId = 'src_' . str_repeat('b', 32);
    $proof = ['sourceId' => $sourceId, 'quote' => 'Example'];
    $base = [
        'schemaVersion' => 'graph-mapping-v2',
        'items' => [['itemKey' => 'goods-a', 'description' => 'Example', 'evidence' => [$proof]]],
        'results' => [['graphId' => '31', 'itemKey' => 'goods-a', 'status' => 'found', 'values' => [['fieldId' => 'goods.description', 'valueType' => 'string', 'rawValue' => 'Example', 'normalizedValue' => 'Example', 'unit' => null, 'currency' => null, 'evidence' => [$proof]]], 'positionValue' => null, 'searchedSourceIds' => [$sourceId], 'reason' => null]],
        'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => [],
    ];
    $wrongGraph = $base;
    $wrongGraph['results'][0]['graphId'] = 'missing';
    errorCode('GRAPH_MAPPING_SCHEMA', fn() => $contract->validateResponse($wrongGraph, [$sourceId]));

    $wrongBinding = $base;
    $wrongBinding['results'][0]['values'][0]['fieldId'] = 'goods.grossMass';
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($wrongBinding, [$sourceId]));

    $wrongType = $base;
    $wrongType['results'][0]['values'][0]['valueType'] = 'decimal';
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($wrongType, [$sourceId]));

    $changedProfile = $base;
    $changedProfile['results'][0]['values'][0]['fieldId'] = 'declaration.procedure';
    errorCode('GRAPH_MAPPING_SCHEMA', fn() => $contract->validateResponse($changedProfile, [$sourceId]));

    $extraNormativeOutput = $base;
    $extraNormativeOutput['profileValues'] = ['declaration.procedure' => '99'];
    errorCode('GRAPH_MAPPING_SCHEMA', fn() => $contract->validateResponse($extraNormativeOutput, [$sourceId]));
});

test('catalog requests are limited to source-grounded per-item TN VED search queries', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $sourceId = 'src_' . str_repeat('e', 32);
    $proof = ['sourceId' => $sourceId, 'quote' => 'ETHYL ACETATE'];
    $response = [
        'schemaVersion' => 'graph-mapping-v2',
        'items' => [['itemKey' => 'goods-a', 'description' => 'ETHYL ACETATE', 'evidence' => [$proof]]],
        'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [],
        'catalogRequests' => [['graphId' => '33', 'itemKey' => 'goods-a', 'fieldId' => 'goods.commodityCode', 'query' => 'этилацетат', 'reason' => 'Перевод подтверждённого наименования товара']],
        'contextRequests' => [], 'warnings' => [],
    ];
    $contract->validateResponse($response, [$sourceId]);

    $wrongGraph = $response;
    $wrongGraph['catalogRequests'][0]['graphId'] = '35';
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($wrongGraph, [$sourceId]));

    $inventedCode = $response;
    $inventedCode['catalogRequests'][0]['query'] = 'этилацетат 2915310000';
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($inventedCode, [$sourceId]));

    $unboundItem = $response;
    $unboundItem['catalogRequests'][0]['itemKey'] = null;
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($unboundItem, [$sourceId]));

    $duplicate = $response;
    $duplicate['catalogRequests'][] = $response['catalogRequests'][0];
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($duplicate, [$sourceId]));
});

test('graph mapping contract keeps country-name proposals separate from fixed profile values', function (): void {
    $contract = new Customs\Domain\GraphMappingContract();
    $sourceId = 'src_' . str_repeat('c', 32);
    $proof = ['sourceId' => $sourceId, 'quote' => 'China'];
    $response = [
        'schemaVersion' => 'graph-mapping-v2',
        'items' => [],
        'results' => [['graphId' => '15', 'itemKey' => null, 'status' => 'found', 'values' => [], 'positionValue' => ['valueType' => 'country-name', 'rawValue' => 'China', 'normalizedValue' => 'China', 'evidence' => [$proof]], 'searchedSourceIds' => [$sourceId], 'reason' => null]],
        'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => [],
    ];
    $contract->validateResponse($response, [$sourceId]);
    $response['results'][0]['graphId'] = '17';
    errorCode('GRAPH_MAPPING_VALUE', fn() => $contract->validateResponse($response, [$sourceId]));
});
