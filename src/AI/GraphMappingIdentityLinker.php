<?php
declare(strict_types=1);

namespace Customs\AI;

/** Assigns session-stable IDs to AI keys and safely joins exact duplicate legal records. */
final class GraphMappingIdentityLinker
{
    public const VERSION = 'graph-entity-identities-v1';

    /** @return array{mapping: array, registry: array} */
    public function link(array $mapping, string $sessionId, array $sources, array $registry = [], array $knownGoods = [], array $knownDocumentKeys = []): array
    {
        if ($sessionId === '' || ($mapping['schemaVersion'] ?? null) !== 'graph-mapping-v2') {
            throw new \InvalidArgumentException('A session ID and graph-mapping-v2 response are required.');
        }
        $this->assertShape($mapping);
        $next = $this->registry($registry);
        foreach ($sources as $source) if (is_string($source['documentId'] ?? null)) {
            $next['files'][$source['documentId']] = ['contentHash' => (string)($source['contentHash'] ?? '')];
        }
        foreach ($knownGoods as $goods) if (is_string($goods['key'] ?? null) && is_string($goods['description'] ?? null) && trim($goods['description']) !== '') {
            $next['goods']['aliases'][$goods['key']] = $goods['key'];
        }
        foreach ($knownDocumentKeys as $key) if (is_string($key) && $key !== '') $next['documents']['aliases'][$key] = $key;
        $origins = $this->sourceOrigins($sources);
        $goodsIds = [];

        foreach ($mapping['items'] as &$item) {
            $aiKey = (string)$item['itemKey'];
            $id = $this->resolve($next['goods'], 'goods', $sessionId, $aiKey);
            $goodsIds[$aiKey] = $id;
            $item['itemKey'] = $id;
        }
        unset($item);

        foreach ($mapping['results'] as &$result) {
            if (is_string($result['itemKey'] ?? null)) $result['itemKey'] = $goodsIds[$result['itemKey']] ?? $this->knownId($next['goods'], $result['itemKey']);
        }
        unset($result);
        foreach (['referenceSelections', 'catalogRequests', 'contextRequests'] as $section) {
            foreach ($mapping[$section] as &$entry) if (is_string($entry['itemKey'] ?? null)) {
                $entry['itemKey'] = $goodsIds[$entry['itemKey']] ?? $this->knownId($next['goods'], $entry['itemKey']);
            }
            unset($entry);
        }
        foreach ($mapping['dtsFacts'] as &$fact) {
            if (is_string($fact['itemKey'] ?? null)) $fact['itemKey'] = $goodsIds[$fact['itemKey']] ?? $this->knownId($next['goods'], $fact['itemKey']);
            if (is_string($fact['participantRole'] ?? null)) $next['participants'][$fact['participantRole']] = 'party-' . $fact['participantRole'];
        }
        unset($fact);

        $records = [];
        foreach ($mapping['documentRecords'] as $record) {
            $aiKey = (string)$record['recordKey'];
            $fingerprint = $this->recordFingerprint($record, $origins);
            $id = $this->resolve($next['documents'], 'document', $sessionId, $aiKey, $fingerprint);
            $record['recordKey'] = $id;
            $record['itemKeys'] = array_values(array_unique(array_map(
                fn(string $key): string => $goodsIds[$key] ?? $this->knownId($next['goods'], $key),
                $record['itemKeys']
            )));
            if (!isset($records[$id])) $records[$id] = ['recordKey' => $id, 'itemKeys' => [], 'values' => []];
            $records[$id]['itemKeys'] = array_values(array_unique([...$records[$id]['itemKeys'], ...$record['itemKeys']]));
            $records[$id]['values'] = $this->mergeValues($records[$id]['values'], $record['values']);
        }
        $mapping['documentRecords'] = array_values($records);

        return ['mapping' => $mapping, 'registry' => $next];
    }

    private function registry(array $registry): array
    {
        if (($registry['version'] ?? null) !== self::VERSION) $registry = [];
        foreach (['goods', 'documents'] as $type) {
            $registry[$type] ??= ['aliases' => [], 'origins' => []];
            $registry[$type]['aliases'] ??= [];
            $registry[$type]['origins'] ??= [];
        }
        $registry['participants'] ??= [];
        $registry['files'] ??= [];
        foreach (['seller', 'buyer', 'consignor', 'consignee', 'declarant', 'financial'] as $role) {
            $registry['participants'][$role] = 'party-' . $role;
        }
        $registry['version'] = self::VERSION;
        return $registry;
    }

    private function assertShape(array $mapping): void
    {
        foreach (['items', 'results', 'documentRecords', 'dtsFacts', 'referenceSelections', 'catalogRequests', 'contextRequests', 'warnings'] as $section) {
            if (!is_array($mapping[$section] ?? null) || !array_is_list($mapping[$section])) throw new \InvalidArgumentException('Malformed graph-mapping-v2 section: ' . $section);
        }
        foreach ($mapping['items'] as $item) if (!is_array($item) || !is_string($item['itemKey'] ?? null) || !is_array($item['evidence'] ?? null)) {
            throw new \InvalidArgumentException('Malformed item identity.');
        }
        foreach ($mapping['documentRecords'] as $record) if (!is_array($record) || !is_string($record['recordKey'] ?? null)
            || !is_array($record['itemKeys'] ?? null) || !array_is_list($record['itemKeys']) || !is_array($record['values'] ?? null)) {
            throw new \InvalidArgumentException('Malformed legal document identity.');
        }
        foreach (['results', 'dtsFacts', 'referenceSelections', 'catalogRequests', 'contextRequests'] as $section) foreach ($mapping[$section] as $entry) {
            if (!is_array($entry) || (!is_null($entry['itemKey'] ?? null) && !is_string($entry['itemKey']))) throw new \InvalidArgumentException('Malformed entity relation in ' . $section . '.');
        }
    }

    private function resolve(array &$entities, string $kind, string $sessionId, string $key, ?string $origin = null): string
    {
        if ($origin !== null && isset($entities['origins'][$origin])) $id = $entities['origins'][$origin];
        elseif (isset($entities['aliases'][$key])) $id = $entities['aliases'][$key];
        else $id = $this->stableId($sessionId, $kind, $key);
        $entities['aliases'][$key] = $id;
        $entities['aliases'][$id] ??= $id;
        if ($origin !== null) $entities['origins'][$origin] = $id;
        return $id;
    }

    private function knownId(array $entities, string $key): string
    {
        return $entities['aliases'][$key] ?? $key;
    }

    private function stableId(string $sessionId, string $kind, string $key): string
    {
        return ($kind === 'goods' ? 'goods-' : 'document-') . substr(hash('sha256', $sessionId . "\0" . $kind . "\0" . $key), 0, 28);
    }

    /** Maps source IDs to stable locations that are identical for byte-identical uploaded files. */
    private function sourceOrigins(array $sources): array
    {
        $origins = [];
        foreach ($sources as $source) {
            $hash = (string)($source['contentHash'] ?? '');
            if ($hash === '') continue;
            foreach ($source['pages'] ?? [] as $page) {
                $pageNo = (int)($page['page'] ?? 0);
                foreach ($page['blocks'] ?? [] as $block) {
                    if (!isset($block['sourceId'])) continue;
                    $loc = ['page', $pageNo, 'block', (string)($block['id'] ?? ''), $this->stableJson($block['bbox'] ?? null)];
                    $origins[$block['sourceId']] = ['hash' => $hash, 'location' => $loc];
                }
                foreach ($page['tables'] ?? [] as $tableIndex => $table) {
                    $tableId = (string)($table['id'] ?? 'table-' . ($tableIndex + 1));
                    if (isset($table['sourceId'])) $origins[$table['sourceId']] = ['hash' => $hash, 'location' => ['page', $pageNo, 'table', $tableId]];
                    foreach ($table['rows'] ?? [] as $rowIndex => $row) {
                        $rowNo = (int)($row['row'] ?? $rowIndex + 1);
                        $rowLoc = ['page', $pageNo, 'table', $tableId, 'row', $rowNo];
                        if (isset($row['sourceId'])) $origins[$row['sourceId']] = ['hash' => $hash, 'location' => $rowLoc];
                        foreach ($row['cells'] ?? [] as $columnIndex => $cell) if (isset($cell['sourceId'])) {
                            $column = (int)($cell['column'] ?? $columnIndex + 1);
                            $origins[$cell['sourceId']] = ['hash' => $hash, 'location' => [...$rowLoc, 'column', $column]];
                        }
                    }
                }
            }
            foreach ($source['sheets'] ?? [] as $sheet) foreach ($sheet['cells'] ?? [] as $address => $cell) if (isset($cell['sourceId'])) {
                $origins[$cell['sourceId']] = ['hash' => $hash, 'location' => ['sheet', (string)$sheet['name'], 'cell', (string)($cell['address'] ?? $address)]];
            }
        }
        return $origins;
    }

    /** Exact duplicate copies can share a document ID; partial or cross-file evidence cannot. */
    private function recordFingerprint(array $record, array $sourceOrigins): ?string
    {
        $parts = []; $hashes = [];
        foreach ($record['values'] ?? [] as $value) foreach ($value['evidence'] ?? [] as $proof) {
            $source = $sourceOrigins[$proof['sourceId'] ?? ''] ?? null;
            if ($source === null) return null;
            $hashes[$source['hash']] = true;
            $parts[] = [
                $value['fieldId'] ?? null,
                $value['valueType'] ?? null,
                $value['normalizedValue'] ?? null,
                $source['location'],
                $this->normalizeText((string)($proof['quote'] ?? '')),
            ];
        }
        if ($parts === [] || count($hashes) !== 1) return null;
        usort($parts, fn(array $a, array $b): int => strcmp($this->stableJson($a), $this->stableJson($b)));
        return hash('sha256', array_key_first($hashes) . "\0" . $this->stableJson($parts));
    }

    private function mergeValues(array $current, array $incoming): array
    {
        $byValue = [];
        foreach ([...$current, ...$incoming] as $value) {
            $key = $this->stableJson([
                $value['fieldId'] ?? null, $value['valueType'] ?? null, $value['normalizedValue'] ?? null,
                $value['unit'] ?? null, $value['currency'] ?? null,
            ]);
            if (!isset($byValue[$key])) $byValue[$key] = $value;
            else $byValue[$key]['evidence'] = $this->mergeEvidence($byValue[$key]['evidence'] ?? [], $value['evidence'] ?? []);
        }
        return array_values($byValue);
    }

    private function mergeEvidence(array $current, array $incoming): array
    {
        $byProof = [];
        foreach ([...$current, ...$incoming] as $proof) $byProof[$this->stableJson($proof)] = $proof;
        return array_values($byProof);
    }

    private function normalizeText(string $text): string
    {
        return mb_strtolower((string)preg_replace('/\s+/u', ' ', trim($text)));
    }

    private function stableJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
