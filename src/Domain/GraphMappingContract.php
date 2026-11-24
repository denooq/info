<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\Http\ApiError;

/** Versioned boundary between declaration graphs and semantic AI proposals. */
final class GraphMappingContract
{
    public const VERSION = '1.1.0';
    public const SCHEMA_VERSION = 'graph-mapping-v2';
    public const OWNERS = ['document', 'reference', 'profile', 'calculated', 'not_applicable', 'manual'];

    private array $data;
    private array $positions;
    private array $auxiliaryFields;

    public function __construct()
    {
        $root = dirname(__DIR__, 2);
        $config = json_decode(file_get_contents($root . '/resources/registry/graph-mapping.v2.json'), true, 512, JSON_THROW_ON_ERROR);
        $graphs = new GraphRegistry();
        $graphData = $graphs->data();
        if (($config['version'] ?? null) !== self::VERSION
            || ($config['sourceGraphVersion'] ?? null) !== ($graphData['version'] ?? null)
            || ($config['sourceBindingVersion'] ?? null) !== ($graphData['bindingVersion'] ?? null)) {
            throw new \LogicException('Graph mapping contract does not match the source graph registry.');
        }

        $registry = new Registry();
        $this->positions = [];
        foreach ($graphs->graphs() as $graph) {
            $bindings = [];
            foreach ($graph['fieldBindings'] as $fieldId) {
                $field = $registry->field($fieldId);
                $owner = $this->owner($graph, $field, $config);
                $bindings[] = [
                    'fieldId' => $fieldId,
                    'scope' => $field['scope'],
                    'key' => $field['key'],
                    'label' => $field['label'],
                    'type' => $field['type'],
                    'unit' => $field['unit'] ?? null,
                    'owner' => $owner,
                    'aiProposalAllowed' => in_array($owner, ['document', 'manual'], true),
                    'autoApplyAllowed' => $owner === 'document',
                    'requiredWhen' => $field['requiredWhen'] ?? false,
                    'validWhen' => $field['validWhen'] ?? [],
                ];
            }

            $positionOwner = $config['positionOwners'][$graph['id']] ?? null;
            if ($graph['fillMode'] === 'not_applicable') $positionOwner = 'not_applicable';
            elseif ($graph['fillMode'] === 'calculated' || $graph['calculationStage'] === 'result') $positionOwner = 'calculated';
            $owners = array_values(array_unique(array_column($bindings, 'owner')));
            if ($owners === [] && $positionOwner !== null) $owners[] = $positionOwner;
            if ($positionOwner !== null && !in_array($positionOwner, $owners, true)) $owners[] = $positionOwner;
            foreach ($owners as $owner) $this->assertOwner($owner);

            $position = [
                'id' => $graph['id'],
                'parentGraph' => $graph['parentGraph'] ?? null,
                'sourceNumber' => $graph['sourceNumber'],
                'title' => $graph['title'],
                'scope' => $graph['scope'],
                'entityRole' => $graph['entityRole'] ?? null,
                'fillMode' => $graph['fillMode'],
                'calculationStage' => $graph['calculationStage'],
                'requiredWhen' => $graph['requiredWhen'],
                'applicabilityRule' => $graph['applicabilityRule'],
                'ruleSource' => $graph['ruleSource'],
                'priorities' => $graph['priorities'],
                'owners' => $owners,
                'bindings' => $bindings,
            ];
            if (isset($config['positionTypes'][$graph['id']])) {
                $position['positionValue'] = [
                    'type' => $config['positionTypes'][$graph['id']],
                    'owner' => $positionOwner,
                    'linkedGraphIds' => $graph['id'] === '15' ? ['15a'] : ($graph['id'] === '17' ? ['17a'] : []),
                ];
            }
            $this->positions[$graph['id']] = $position;
        }
        $this->assertPositionCoverage();
        $this->auxiliaryFields = $this->dtsFields($registry, $config);
        $this->data = [
            'version' => self::VERSION,
            'schemaVersion' => self::SCHEMA_VERSION,
            'sourceGraphVersion' => $graphData['version'],
            'sourceBindingVersion' => $graphData['bindingVersion'],
            'positions' => array_values($this->positions),
            'auxiliaryDtsFields' => $this->auxiliaryFields,
        ];
    }

    public function data(): array { return $this->data; }
    public function positions(): array { return $this->positions; }
    public function position(string $graphId): array { return $this->positions[$graphId] ?? throw new ApiError('GRAPH_UNKNOWN', 'Неизвестная графа.', 422); }
    public function auxiliaryDtsFields(): array { return $this->auxiliaryFields; }

    /** Add source- and registry-specific enums to the strict wire schema. */
    public function responseSchema(array $sourceIds = []): array
    {
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/schemas/graph-mapping.v2.json'), true, 128, JSON_THROW_ON_ERROR);
        $graphIds = array_map('strval', array_keys($this->positions));
        $documentFields = [];
        $referenceFields = [];
        $documentRecordFields = [];
        $types = [];
        foreach ($this->positions as $position) foreach ($position['bindings'] as $binding) {
            if ($binding['aiProposalAllowed']) $documentFields[$binding['fieldId']] = true;
            if ($binding['owner'] === 'reference') $referenceFields[$binding['fieldId']] = true;
            if ($binding['scope'] === 'document' && in_array($binding['owner'], ['document', 'reference', 'manual'], true)) $documentRecordFields[$binding['fieldId']] = true;
            $types[$binding['type']] = true;
        }
        $dtsFields = [];
        $matrixIds = [];
        foreach ($this->auxiliaryFields as $field) {
            $matrixIds[$field['matrixId']] = true;
            if ($field['aiProposalAllowed']) $dtsFields[$field['fieldId']] = true;
            $types[$field['type']] = true;
        }
        $fieldIds = array_keys($documentFields);
        $referenceIds = array_keys($referenceFields);
        $recordFieldIds = array_keys($documentRecordFields);
        $dtsFieldIds = array_keys($dtsFields);
        $allTypes = array_keys($types);
        sort($allTypes, SORT_STRING);

        $schema['properties']['results']['items']['properties']['graphId']['enum'] = $graphIds;
        $schema['properties']['results']['items']['properties']['values']['items']['properties']['fieldId']['enum'] = $fieldIds;
        $schema['properties']['results']['items']['properties']['values']['items']['properties']['valueType']['enum'] = $allTypes;
        $schema['properties']['results']['items']['properties']['positionValue']['properties']['valueType']['enum'] = array_values(array_unique(array_merge($allTypes, array_column(array_filter(array_column($this->positions, 'positionValue')), 'type'))));
        $schema['properties']['documentRecords']['items']['properties']['values']['items']['properties']['fieldId']['enum'] = $recordFieldIds;
        $schema['properties']['documentRecords']['items']['properties']['values']['items']['properties']['valueType']['enum'] = $allTypes;
        $schema['properties']['dtsFacts']['items']['properties']['matrixId']['enum'] = array_keys($matrixIds);
        $schema['properties']['dtsFacts']['items']['properties']['value']['properties']['fieldId']['enum'] = $dtsFieldIds;
        $schema['properties']['dtsFacts']['items']['properties']['value']['properties']['valueType']['enum'] = $allTypes;
        $schema['properties']['referenceSelections']['items']['properties']['graphId']['enum'] = $graphIds;
        $schema['properties']['referenceSelections']['items']['properties']['fieldId']['enum'] = $referenceIds;
        $schema['properties']['catalogRequests']['items']['properties']['graphId']['enum'] = $graphIds;
        $schema['properties']['catalogRequests']['items']['properties']['fieldId']['enum'] = ['goods.commodityCode'];
        $schema['properties']['contextRequests']['items']['properties']['graphId']['enum'] = $graphIds;
        if ($sourceIds !== []) {
            $enum = array_values(array_unique($sourceIds));
            $schema['$defs']['sourceIdList']['items']['enum'] = $enum;
            $schema['$defs']['evidenceList']['items']['properties']['sourceId']['enum'] = $enum;
        }
        return $schema;
    }

    /** Validate schema shape and graph-specific binding ownership before candidates reach the merger. */
    public function validateResponse(array $response, array $sourceIds = []): void
    {
        $schema = $this->responseSchema($sourceIds);
        $object = json_decode(json_encode($response, JSON_THROW_ON_ERROR), false, 128, JSON_THROW_ON_ERROR);
        $validator = new \Opis\JsonSchema\Validator();
        $validation = $validator->validate($object, json_decode(json_encode($schema, JSON_THROW_ON_ERROR)));
        if (!$validation->isValid()) throw new ApiError('GRAPH_MAPPING_SCHEMA', 'AI вернул данные вне контракта сопоставления граф.', 502);

        $itemKeys = array_column($response['items'], 'itemKey');
        if (count($itemKeys) !== count(array_unique($itemKeys))) $this->invalidResponse();
        $seen = [];
        foreach ($response['results'] as $result) {
            $position = $this->position($result['graphId']);
            $key = $result['graphId'] . '/' . ($result['itemKey'] ?? '_');
            if (isset($seen[$key])) $this->invalidResponse();
            $seen[$key] = true;
            $this->assertItemTarget($position, $result['itemKey'], $itemKeys);
            $hasPositionValue = $result['positionValue'] !== null;
            $hasValues = $result['values'] !== [];
            if (in_array($result['status'], ['found', 'conflict'], true) !== ($hasValues || $hasPositionValue)) $this->invalidResponse();
            $canPropose = array_filter($position['bindings'], fn($binding) => $binding['aiProposalAllowed']) !== []
                || (isset($position['positionValue']) && in_array($position['positionValue']['owner'], ['document', 'manual'], true));
            if (!$canPropose) $this->invalidResponse();
            $this->validateValues($result['values'], $position, 'graph');
            if ($hasPositionValue) {
                $definition = $position['positionValue'] ?? null;
                if ($definition === null || !in_array($definition['owner'], ['document', 'manual'], true)
                    || $result['positionValue']['valueType'] !== $definition['type']
                    || !$this->matchesType($result['positionValue']['normalizedValue'], $definition['type'])) $this->invalidResponse();
            }
        }

        foreach ($response['documentRecords'] as $record) {
            if (count(array_unique($record['itemKeys'])) !== count($record['itemKeys'])) $this->invalidResponse();
            foreach ($record['itemKeys'] as $itemKey) if (!in_array($itemKey, $itemKeys, true)) $this->invalidResponse();
            $graph = $this->positions['44'];
            $this->validateValues($record['values'], $graph, 'document');
        }
        foreach ($response['dtsFacts'] as $fact) {
            $fields = array_values(array_filter($this->auxiliaryFields, fn($field) => $field['matrixId'] === $fact['matrixId'] && $field['fieldId'] === $fact['value']['fieldId']));
            if ($fields === [] || !$fields[0]['aiProposalAllowed']) $this->invalidResponse();
            $partyFact = $fact['matrixId'] === 'DTS-1-2' && in_array($fact['value']['fieldId'], ['party.name', 'party.country', 'party.taxId'], true);
            if ($partyFact !== ($fact['participantRole'] !== null) || ($partyFact && $fact['itemKey'] !== null)) $this->invalidResponse();
            if ($fact['itemKey'] !== null && !in_array($fact['itemKey'], $itemKeys, true)) $this->invalidResponse();
            $this->validateTypedValue($fact['value'], $fields[0]);
        }
        foreach ($response['referenceSelections'] as $reference) {
            $position = $this->position($reference['graphId']);
            $binding = $this->binding($position, $reference['fieldId']);
            if ($binding === null || $binding['owner'] !== 'reference') $this->invalidResponse();
            $this->assertItemTarget($position, $reference['itemKey'], $itemKeys);
        }
        $catalogRequests = [];
        foreach ($response['catalogRequests'] as $request) {
            // In this profile the only AI-assisted catalog lookup is the per-item TN VED search.
            // The model returns a descriptive query; the server remains the only source of codes.
            if ($request['graphId'] !== '33' || $request['fieldId'] !== 'goods.commodityCode'
                || !is_string($request['itemKey']) || trim($request['query']) === ''
                || preg_match('/(?<!\d)\d{8,10}(?!\d)/', trim($request['query']))) $this->invalidResponse();
            $key = $request['graphId'] . '/' . $request['itemKey'] . '/' . $request['fieldId'];
            if (isset($catalogRequests[$key])) $this->invalidResponse();
            $catalogRequests[$key] = true;
            $item = null;
            foreach ($response['items'] as $candidate) if ($candidate['itemKey'] === $request['itemKey']) { $item = $candidate; break; }
            if (!is_array($item) || !is_string($item['description'] ?? null) || trim($item['description']) === '' || ($item['evidence'] ?? []) === []) $this->invalidResponse();
        }
        foreach ($response['contextRequests'] as $request) {
            $position = $this->position($request['graphId']);
            $this->assertItemTarget($position, $request['itemKey'], $itemKeys);
        }
    }

    private function validateValues(array $values, array $position, string $mode): void
    {
        foreach ($values as $value) {
            $binding = $this->binding($position, $value['fieldId']);
            if ($binding === null || !$binding['aiProposalAllowed']) $this->invalidResponse();
            if ($mode === 'document' && $binding['scope'] !== 'document') $this->invalidResponse();
            $this->validateTypedValue($value, $binding);
        }
    }

    private function validateTypedValue(array $value, array $binding): void
    {
        if ($value['valueType'] !== $binding['type'] || !$this->matchesType($value['normalizedValue'], $binding['type'])) $this->invalidResponse();
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'string', 'code', 'enum', 'date', 'identifier' => is_string($value) && trim($value) !== '',
            'integer' => is_int($value),
            'decimal' => is_int($value) || is_float($value) || (is_string($value) && preg_match('/^-?\d+(?:\.\d+)?$/D', $value) === 1),
            'boolean' => is_bool($value),
            'country-name' => is_string($value) && trim($value) !== '',
            'string-list', 'code-list' => is_array($value) && array_is_list($value) && array_filter($value, fn($part) => !is_string($part)) === [],
            default => false,
        };
    }

    private function assertItemTarget(array $position, ?string $itemKey, array $itemKeys): void
    {
        if ($position['scope'] === 'goods') {
            if ($itemKey === null || !in_array($itemKey, $itemKeys, true)) $this->invalidResponse();
        } elseif ($itemKey !== null && !in_array($itemKey, $itemKeys, true)) $this->invalidResponse();
    }

    private function binding(array $position, string $fieldId): ?array
    {
        foreach ($position['bindings'] as $binding) if ($binding['fieldId'] === $fieldId) return $binding;
        return null;
    }

    private function dtsFields(Registry $registry, array $config): array
    {
        $result = [];
        foreach ($registry->data()['dtsMatrixCoverage'] as $matrix) foreach ($matrix['fieldIds'] as $fieldId) {
            $field = $registry->field($fieldId);
            $owner = $this->baseOwner($field);
            $result[] = [
                'matrixId' => $matrix['id'],
                'fieldId' => $fieldId,
                'scope' => $field['scope'],
                'key' => $field['key'],
                'label' => $field['label'],
                'type' => $field['type'],
                'unit' => $field['unit'] ?? null,
                'owner' => $owner,
                'aiProposalAllowed' => in_array($owner, ['document', 'manual'], true),
                'autoApplyAllowed' => $owner === 'document',
            ];
        }
        return $result;
    }

    private function owner(array $graph, array $field, array $config): string
    {
        $override = $config['fieldOwnerOverrides'][$graph['id']][$field['id']] ?? null;
        if ($override !== null) { $this->assertOwner($override); return $override; }
        if ($graph['fillMode'] === 'not_applicable') return 'not_applicable';
        return $this->baseOwner($field);
    }

    private function baseOwner(array $field): string
    {
        if (array_key_exists('fixedValue', $field)) return 'profile';
        $acquisition = $field['acquisition'] ?? [];
        if (in_array('calculated', $acquisition, true)) return 'calculated';
        if (in_array('extract', $acquisition, true)) return 'document';
        if (in_array('reference', $acquisition, true)) return 'reference';
        if (in_array('manual', $acquisition, true)) return 'manual';
        throw new \LogicException('Field has no value owner: ' . ($field['id'] ?? 'unknown'));
    }

    private function assertOwner(string $owner): void
    {
        if (!in_array($owner, self::OWNERS, true)) throw new \LogicException('Unknown graph mapping owner: ' . $owner);
    }

    private function assertPositionCoverage(): void
    {
        $expected = [...array_map('strval', range(1, 54)), '15a', '17a', 'B'];
        $actual = array_keys($this->positions);
        if (count($actual) !== 57 || count(array_diff($expected, $actual)) !== 0 || count(array_diff($actual, $expected)) !== 0) {
            throw new \LogicException('Graph mapping contract must cover exactly the 57 declared positions.');
        }
    }

    private function invalidResponse(): never
    {
        throw new ApiError('GRAPH_MAPPING_VALUE', 'AI вернул значение, не разрешённое для этой графы или типа поля.', 502);
    }
}
