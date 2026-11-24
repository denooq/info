<?php
declare(strict_types=1);

use Customs\AI\GraphMappingVerifier;
use Customs\AI\SourceManifest;
use Customs\Domain\GraphMappingContract;

function sessionRegressionManifest(): array
{
    $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/session-json-regression.v1.json'), true, 64, JSON_THROW_ON_ERROR);
    $sources = [];
    foreach ($fixture['sourceKinds'] as $index => $kind) {
        $id = str_repeat(dechex($index + 1), 32);
        $sources[] = [
            'documentId' => $id,
            'name' => 'document-' . ($index + 1) . '.pdf',
            'contentHash' => 'regression-' . $kind,
            'documentKind' => $kind,
            'pages' => [['page' => 1, 'blocks' => [['id' => 'p1-b0', 'text' => $fixture['syntheticSourceText'][$kind]]]]],
            'sheets' => [],
        ];
    }
    return (new SourceManifest())->build($sources, 'session-json-regression');
}

function regressionSource(array $manifest, string $kind, string $quote): array
{
    foreach ($manifest['sources'] as $source) if ($source['documentKind'] === $kind) {
        return ['sourceId' => $source['pages'][0]['blocks'][0]['sourceId'], 'quote' => $quote];
    }
    throw new RuntimeException('Regression source kind is missing: ' . $kind);
}

function regressionValue(string $fieldId, string $type, string $raw, mixed $normalized, array $proof, ?string $unit = null, ?string $currency = null): array
{
    return ['fieldId' => $fieldId, 'valueType' => $type, 'rawValue' => $raw, 'normalizedValue' => $normalized, 'unit' => $unit, 'currency' => $currency, 'evidence' => [$proof]];
}

test('sanitized session regression retains exact evidence and permits canonical country, date, mass and price values', function (): void {
    $fixture = json_decode(file_get_contents(dirname(__DIR__) . '/fixtures/session-json-regression.v1.json'), true, 64, JSON_THROW_ON_ERROR);
    same(['02015', '09013', '04021', '03011'], $fixture['sourceKinds']);
    same(14, $fixture['observedIssueCounts']['SOURCE_ROLE_UNCONFIRMED']);
    same(4, $fixture['observedIssueCounts']['VALUE_UNSUPPORTED']);
    same(['csrfToken', 'sessionId', 'cookie', 'apiKey'], $fixture['sensitiveKeysExcluded']);
    foreach ($fixture['sensitiveKeysExcluded'] as $sensitiveKey) truth(!array_key_exists($sensitiveKey, $fixture), 'Sensitive session key was copied to the fixture.');

    $manifest = sessionRegressionManifest();
    $countryProof = regressionSource($manifest, '04021', 'REPUBLIC OF KAZAKHSTAN');
    $dateProof = regressionSource($manifest, '04021', '09.10.2026');
    $priceProof = regressionSource($manifest, '04021', 'USD 37454.40');
    $grossProof = regressionSource($manifest, '02015', 'Gross 27860 kg');
    $netProof = regressionSource($manifest, '02015', 'Net 24480 kg');
    $contractProof = regressionSource($manifest, '03011', 'Contract No C-1');
    $mapping = [
        'items' => [],
        'results' => [
            ['graphId' => '15', 'values' => [regressionValue('declaration.dispatchCountry', 'code', 'REPUBLIC OF KAZAKHSTAN', 'KZ', $countryProof)], 'positionValue' => null],
            ['graphId' => '35', 'values' => [regressionValue('goods.grossMass', 'decimal', '27860', '27860', $grossProof, 'kg')], 'positionValue' => null],
            ['graphId' => '38', 'values' => [regressionValue('goods.netMass', 'decimal', '24480', '24480', $netProof, 'kg')], 'positionValue' => null],
            ['graphId' => '42', 'values' => [regressionValue('goods.invoiceValue', 'decimal', '37454.40', '37454.4', $priceProof, null, 'USD')], 'positionValue' => null],
        ],
        'documentRecords' => [['values' => [
            regressionValue('document.date', 'date', '09.10.2026', '2026-10-09', $dateProof),
            regressionValue('document.number', 'string', 'C-1', 'C-1', $contractProof),
        ]]],
        'dtsFacts' => [],
    ];
    (new GraphMappingVerifier(new GraphMappingContract()))->verify($mapping, $manifest['sources']);
});

test('graph verifier rejects fabricated raw values even when citation is real', function (): void {
    $manifest = sessionRegressionManifest();
    $proof = regressionSource($manifest, '02015', 'Gross 27860 kg');
    $mapping = ['items' => [], 'results' => [['graphId' => '35', 'values' => [regressionValue('goods.grossMass', 'decimal', '28000', '28000', $proof, 'kg')], 'positionValue' => null]], 'documentRecords' => [], 'dtsFacts' => []];
    errorCode('EVIDENCE_INVALID', fn() => (new GraphMappingVerifier(new GraphMappingContract()))->verify($mapping, $manifest['sources']));
});

test('graph verifier will not map an explicit mass or amount as a legal document number', function (): void {
    $manifest = sessionRegressionManifest();
    $proof = regressionSource($manifest, '04021', 'USD 37454.40');
    $mapping = ['items' => [], 'results' => [], 'documentRecords' => [['values' => [regressionValue('document.number', 'string', '37454.40', '37454.40', $proof)]]], 'dtsFacts' => []];
    errorCode('GRAPH_VALUE_SEMANTIC', fn() => (new GraphMappingVerifier(new GraphMappingContract()))->verify($mapping, $manifest['sources']));
});

test('graph verifier requires source currency for invoice amounts', function (): void {
    $manifest = (new SourceManifest())->build([['documentId'=>str_repeat('a',32),'name'=>'invoice.pdf','contentHash'=>'currency-missing','documentKind'=>'04021','pages'=>[['page'=>1,'blocks'=>[['id'=>'p1-b0','text'=>'Total 37454.40']]]],'sheets'=>[]]], 'currency-missing');
    $proof = ['sourceId'=>$manifest['sources'][0]['pages'][0]['blocks'][0]['sourceId'],'quote'=>'37454.40'];
    $mapping = ['items' => [], 'results' => [['graphId' => '42', 'values' => [regressionValue('goods.invoiceValue', 'decimal', '37454.40', '37454.4', $proof, null, 'USD')], 'positionValue' => null]], 'documentRecords' => [], 'dtsFacts' => []];
    errorCode('GRAPH_VALUE_CURRENCY', fn() => (new GraphMappingVerifier(new GraphMappingContract()))->verify($mapping, $manifest['sources']));
});
