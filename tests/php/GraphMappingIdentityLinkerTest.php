<?php
declare(strict_types=1);

use Customs\AI\GraphMappingIdentityLinker;
use Customs\AI\SourceManifest;

function identityMappingSource(string $session, array $documents): array
{
    return (new SourceManifest())->build(array_map(static function (array $document): array {
        return [
            'documentId' => $document['id'], 'name' => $document['name'], 'contentHash' => $document['hash'],
            'pages' => array_map(static fn(array $page, int $index): array => [
                'page' => $index + 1,
                'blocks' => array_map(static fn(array $block, int $blockIndex): array => [
                    'id' => 'p' . ($index + 1) . '-b' . $blockIndex,
                    'text' => $block['text'],
                ], $page, array_keys($page)),
            ], $document['pages'], array_keys($document['pages'])),
            'sheets' => [],
        ];
    }, $documents), $session)['sources'];
}

function identityEmptyMapping(): array
{
    return ['schemaVersion' => 'graph-mapping-v2', 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => []];
}

function identityRecord(string $key, string $sourceId, string $quote, string $number): array
{
    return ['recordKey' => $key, 'itemKeys' => [], 'values' => [[
        'fieldId' => 'document.number', 'valueType' => 'string', 'rawValue' => $number,
        'normalizedValue' => $number, 'unit' => null, 'currency' => null,
        'evidence' => [['sourceId' => $sourceId, 'quote' => $quote]],
    ]]];
}

test('identity linker keeps two and five goods stable across windows without spreading common masses or packages', function (): void {
    foreach ([2, 5] as $count) {
        $session = 'goods-session-' . $count;
        $blocks = [];
        for ($i = 1; $i <= $count; $i++) $blocks[] = ['text' => 'SKU ' . $i . ' PRODUCT ' . $i];
        $sources = identityMappingSource($session, [['id' => 'file-goods', 'name' => 'invoice.pdf', 'hash' => 'invoice-hash', 'pages' => [$blocks]]]);
        $first = identityEmptyMapping();
        foreach ($sources[0]['pages'][0]['blocks'] as $i => $block) {
            $key = 'ai-item-' . ($i + 1);
            $proof = ['sourceId' => $block['sourceId'], 'quote' => $block['text']];
            $first['items'][] = ['itemKey' => $key, 'description' => 'PRODUCT ' . ($i + 1), 'evidence' => [$proof]];
            $mass = (string)(100 * ($i + 1));
            $first['results'][] = ['graphId' => '35', 'itemKey' => $key, 'status' => 'found', 'values' => [[
                'fieldId' => 'goods.grossMass', 'valueType' => 'decimal', 'rawValue' => $mass . ' kg', 'normalizedValue' => $mass,
                'unit' => 'kg', 'currency' => null, 'evidence' => [$proof],
            ]], 'positionValue' => null, 'searchedSourceIds' => [$block['sourceId']], 'reason' => null];
        }
        $first['results'][] = ['graphId' => '6', 'itemKey' => null, 'status' => 'not_found', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => [], 'reason' => null];
        if ($count > 1) $first['results'][] = ['graphId' => '31', 'itemKey' => 'ai-item-1', 'status' => 'found', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => [$sources[0]['pages'][0]['blocks'][0]['sourceId']], 'reason' => null];

        $linker = new GraphMappingIdentityLinker();
        $linked = $linker->link($first, $session, $sources);
        $ids = array_column($linked['mapping']['items'], 'itemKey');
        same($count, count(array_unique($ids)));
        $packageResults = array_values(array_filter($linked['mapping']['results'], fn(array $r): bool => $r['graphId'] === '31'));
        if ($count > 1) same($ids[0], $packageResults[0]['itemKey']);

        $nextWindow = identityEmptyMapping();
        foreach ($linked['mapping']['items'] as $i => $item) {
            $key = $item['itemKey'];
            $block = $sources[0]['pages'][0]['blocks'][$i];
            $proof = ['sourceId' => $block['sourceId'], 'quote' => $block['text']];
            $nextWindow['items'][] = ['itemKey' => $key, 'description' => $item['description'], 'evidence' => [$proof]];
            $mass = (string)(100 * ($i + 1));
            $nextWindow['results'][] = ['graphId' => '35', 'itemKey' => $key, 'status' => 'found', 'values' => [[
                'fieldId' => 'goods.grossMass', 'valueType' => 'decimal', 'rawValue' => $mass . ' kg', 'normalizedValue' => $mass,
                'unit' => 'kg', 'currency' => null, 'evidence' => [$proof],
            ]], 'positionValue' => null, 'searchedSourceIds' => [$block['sourceId']], 'reason' => null];
        }
        $nextWindow['results'][] = ['graphId' => '6', 'itemKey' => null, 'status' => 'not_found', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => [], 'reason' => null];
        $next = $linker->link($nextWindow, $session, $sources, $linked['registry']);
        same($ids, array_column($next['mapping']['items'], 'itemKey'));
        same($count, count(array_filter($next['mapping']['results'], fn(array $r): bool => $r['graphId'] === '35')));
        same((string)(100 * $count), $next['mapping']['results'][$count - 1]['values'][0]['normalizedValue']);
        same(null, $next['mapping']['results'][$count]['itemKey']);
        same(1, count(array_filter($next['mapping']['results'], fn(array $r): bool => $r['graphId'] === '6')));
        same(0, count(array_filter($next['mapping']['results'], fn(array $r): bool => $r['graphId'] === '31')));
    }
});

test('identity linker joins exact duplicate legal attachments but keeps contract and appendix records separate', function (): void {
    $session = 'legal-doc-session';
    $duplicateSources = identityMappingSource($session, [
        ['id' => 'contract-a', 'name' => 'contract.pdf', 'hash' => 'same-contract-bytes', 'pages' => [[['text' => 'Contract No CN-10 dated 2025-04-08']]]],
        ['id' => 'contract-b', 'name' => 'copy.pdf', 'hash' => 'same-contract-bytes', 'pages' => [[['text' => 'Contract No CN-10 dated 2025-04-08']]]],
    ]);
    $linker = new GraphMappingIdentityLinker();
    $first = identityEmptyMapping();
    $first['documentRecords'][] = identityRecord('ai-contract-a', $duplicateSources[0]['pages'][0]['blocks'][0]['sourceId'], 'Contract No CN-10 dated 2025-04-08', 'CN-10');
    $linkedFirst = $linker->link($first, $session, $duplicateSources);
    $second = identityEmptyMapping();
    $second['documentRecords'][] = identityRecord('ai-contract-copy', $duplicateSources[1]['pages'][0]['blocks'][0]['sourceId'], 'Contract No CN-10 dated 2025-04-08', 'CN-10');
    $linkedSecond = $linker->link($second, $session, $duplicateSources, $linkedFirst['registry']);
    same($linkedFirst['mapping']['documentRecords'][0]['recordKey'], $linkedSecond['mapping']['documentRecords'][0]['recordKey']);

    $copiesTogether = identityEmptyMapping();
    $copiesTogether['documentRecords'][] = identityRecord('copy-one', $duplicateSources[0]['pages'][0]['blocks'][0]['sourceId'], 'Contract No CN-10 dated 2025-04-08', 'CN-10');
    $copiesTogether['documentRecords'][] = identityRecord('copy-two', $duplicateSources[1]['pages'][0]['blocks'][0]['sourceId'], 'Contract No CN-10 dated 2025-04-08', 'CN-10');
    $mergedCopies = $linker->link($copiesTogether, $session, $duplicateSources);
    same(1, count($mergedCopies['mapping']['documentRecords']));
    same(2, count($mergedCopies['mapping']['documentRecords'][0]['values'][0]['evidence']));

    $mergedPdf = identityMappingSource($session, [[
        'id' => 'bundle', 'name' => 'contract-and-appendix.pdf', 'hash' => 'merged-pdf', 'pages' => [
            [['text' => 'Contract No CN-10 dated 2025-04-08']],
            [['text' => 'Appendix No A-2 to Contract CN-10']],
        ],
    ]]);
    $bundle = identityEmptyMapping();
    $bundle['documentRecords'][] = identityRecord('contract', $mergedPdf[0]['pages'][0]['blocks'][0]['sourceId'], 'Contract No CN-10 dated 2025-04-08', 'CN-10');
    $bundle['documentRecords'][] = identityRecord('appendix', $mergedPdf[0]['pages'][1]['blocks'][0]['sourceId'], 'Appendix No A-2 to Contract CN-10', 'A-2');
    $linkedBundle = $linker->link($bundle, $session, $mergedPdf, $linkedSecond['registry']);
    same(2, count(array_unique(array_column($linkedBundle['mapping']['documentRecords'], 'recordKey'))));
});

test('identity linker records independent DTS party roles and leaves input registry unchanged on failure', function (): void {
    $linker = new GraphMappingIdentityLinker();
    $session = 'party-session';
    $mapping = identityEmptyMapping();
    $mapping['dtsFacts'] = [
        ['matrixId' => 'DTS-1-2', 'itemKey' => null, 'participantRole' => 'seller', 'value' => ['fieldId' => 'party.name']],
        ['matrixId' => 'DTS-1-2', 'itemKey' => null, 'participantRole' => 'buyer', 'value' => ['fieldId' => 'party.name']],
    ];
    $linked = $linker->link($mapping, $session, []);
    same('party-seller', $linked['registry']['participants']['seller']);
    same('party-buyer', $linked['registry']['participants']['buyer']);
    truth($linked['registry']['participants']['seller'] !== $linked['registry']['participants']['buyer']);

    $original = $linked['registry'];
    $malformed = identityEmptyMapping();
    $malformed['items'][] = ['description' => 'missing key', 'evidence' => []];
    try { $linker->link($malformed, $session, [], $original); }
    catch (InvalidArgumentException) { same($original, $linked['registry']); return; }
    throw new RuntimeException('Expected an invalid mapping error.');
});

test('identity link fallback for one cross-file record preserves unrelated goods and participant links', function (): void {
    $session = 'partial-link-session';
    $sources = identityMappingSource($session, [
        ['id' => 'invoice', 'name' => 'invoice.pdf', 'hash' => 'invoice-hash', 'pages' => [[['text' => 'SKU A product and contract CN-10']]]],
        ['id' => 'contract', 'name' => 'contract.pdf', 'hash' => 'contract-hash', 'pages' => [[['text' => 'Contract CN-10']]]],
    ]);
    $invoiceId = $sources[0]['pages'][0]['blocks'][0]['sourceId'];
    $contractId = $sources[1]['pages'][0]['blocks'][0]['sourceId'];
    $mapping = identityEmptyMapping();
    $mapping['items'][] = ['itemKey' => 'item-a', 'description' => 'Product A', 'evidence' => [['sourceId' => $invoiceId, 'quote' => 'SKU A product']]];
    $mapping['results'][] = ['graphId' => '35', 'itemKey' => 'item-a', 'status' => 'found', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => [$invoiceId], 'reason' => null];
    $mapping['documentRecords'][] = ['recordKey' => 'contract-a', 'itemKeys' => ['item-a'], 'values' => [[
        'fieldId' => 'document.number', 'valueType' => 'string', 'rawValue' => 'CN-10', 'normalizedValue' => 'CN-10', 'unit' => null, 'currency' => null,
        'evidence' => [['sourceId' => $invoiceId, 'quote' => 'contract CN-10'], ['sourceId' => $contractId, 'quote' => 'Contract CN-10']],
    ]]];
    $mapping['dtsFacts'][] = ['matrixId' => 'DTS-1-2', 'itemKey' => null, 'participantRole' => 'seller', 'value' => ['fieldId' => 'party.name']];

    $linked = (new GraphMappingIdentityLinker())->link($mapping, $session, $sources);
    same($linked['mapping']['items'][0]['itemKey'], $linked['mapping']['results'][0]['itemKey']);
    same($linked['mapping']['items'][0]['itemKey'], $linked['mapping']['documentRecords'][0]['itemKeys'][0]);
    same('party-seller', $linked['registry']['participants']['seller']);
    truth(str_starts_with($linked['mapping']['documentRecords'][0]['recordKey'], 'document-'));
});
