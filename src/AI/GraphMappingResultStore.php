<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Domain\Classifiers;
use Customs\Domain\Draft;
use Customs\Domain\GraphMappingContract;
use Customs\Domain\GraphRegistry;
use Customs\Domain\GraphSources;
use Customs\Domain\Normalizer;
use Customs\Domain\Registry;
use Customs\Http\ApiError;

/** Applies verified graph facts to the editable draft and builds a safe per-position result view. */
final class GraphMappingResultStore
{
    private Registry $registry;
    private Draft $draft;
    private GraphMappingContract $contract;
    private GraphRegistry $graphs;

    public function __construct()
    {
        $this->registry = new Registry();
        $this->draft = new Draft($this->registry);
        $this->contract = new GraphMappingContract();
        $this->graphs = new GraphRegistry();
    }

    /** Start a new mapping revision while preserving user-entered and user-selected values. */
    public function resetForRun(array $state, string $fingerprint, array $expectedSourceIds): array
    {
        $removed = [];
        foreach ($state['candidates'] ?? [] as $id => $candidate) if (($candidate['origin'] ?? null) === 'graph-mapping') {
            $meta = $state['draft']['fieldStates'][$candidate['address'] ?? ''] ?? [];
            if (($meta['status'] ?? null) === 'selected' && ($meta['candidateId'] ?? null) === $id) {
                $state['candidates'][$id]['origin'] = 'selected-snapshot';
                continue;
            }
            $removed[$id] = $candidate['address'] ?? null;
            unset($state['candidates'][$id]);
        }
        foreach ($removed as $id => $address) {
            if (!is_string($address)) continue;
            $meta = $state['draft']['fieldStates'][$address] ?? [];
            if (($meta['candidateId'] ?? null) !== $id || in_array($meta['status'] ?? null, ['manual','selected'], true)) continue;
            try {
                [$scope,$entityId,$key] = explode('/', $address, 3);
                $row =& $this->draft->entity($state['draft'], $scope, $entityId === '_' ? null : $entityId);
                $row[$key] = null;
                unset($row);
                $state['draft']['fieldStates'][$address] = [...$meta,'status'=>'unknown','candidateId'=>null,'evidenceIds'=>[],'conflict'=>false,'needsReview'=>false];
            } catch (ApiError) {}
        }
        $state['graphMappingFingerprint'] = $fingerprint;
        $state['graphMappingExpectedSourceIds'] = array_values(array_unique($expectedSourceIds));
        $state['graphMappingRunComplete'] = false;
        $state['graphMappingParts'] = [];
        $state['graphMappingResults'] = null;
        $state = (new CandidateMerger($this->registry))->reconcile($state);
        return $state;
    }

    /** Apply only facts whose source citations resolve in this session's OCR manifest. */
    public function apply(array $state, array $mapping, array $sources): array
    {
        $state['draft'] = array_replace($this->draft->empty(), $state['draft'] ?? []);
        $state['candidates'] ??= [];
        $state['evidence'] ??= [];
        $state['mappingIssues'] ??= [];
        $index = new SourceIndex($sources);

        foreach ($mapping['items'] ?? [] as $item) $state = $this->applyItem($state, $item, $index);

        foreach ($mapping['results'] ?? [] as $result) {
            $graph = $this->graphs->graph((string)$result['graphId']);
            foreach ($result['values'] ?? [] as $value) {
                $entityId = $this->entityId($value['fieldId'], $graph, $result['itemKey'] ?? null);
                $state = $this->applyValue($state, $value, $entityId, $index);
            }
            if (is_array($result['positionValue'] ?? null)) {
                $state = $this->applyPositionValue($state, $result['graphId'], $result['positionValue'], $index);
            }
        }

        foreach ($mapping['documentRecords'] ?? [] as $record) {
            $recordId = (string)$record['recordKey'];
            $goodsIds = array_values(array_unique($record['itemKeys'] ?? []));
            $evidenceDocuments = [];
            foreach ($record['values'] ?? [] as $value) {
                $proofs = $this->verifiedEvidence($value['evidence'] ?? [], $index);
                foreach ($proofs as $proof) $evidenceDocuments[$proof['documentId']] = true;
            }
            $state = $this->ensureDocument($state, $recordId, $goodsIds, array_keys($evidenceDocuments));
            foreach ($record['values'] ?? [] as $value) $state = $this->applyValue($state, $value, $recordId, $index);
        }

        foreach ($mapping['dtsFacts'] ?? [] as $fact) {
            $fieldId = $fact['value']['fieldId'] ?? '';
            $field = $this->registry->field($fieldId);
            $entityId = match ($field['scope']) {
                'declaration' => null,
                'goods' => $fact['itemKey'] ?? null,
                'party' => is_string($fact['participantRole'] ?? null) ? 'party-' . $fact['participantRole'] : null,
                default => null,
            };
            // The current mapping contract has no stable key for individual cost/payment rows.
            // Keep those cited facts in the mapping result projection until the contract can identify a row.
            $proofs = $this->verifiedEvidence($fact['value']['evidence'] ?? [], $index);
            foreach ($proofs as $proof) $state['evidence'][$proof['id']] = $proof;
            if (in_array($field['scope'], ['declaration','goods','party'], true)) {
                $state = $this->applyValue($state, $fact['value'], $entityId, $index);
            }
        }

        $state = (new CandidateMerger($this->registry))->reconcile($state);
        $state = $this->applyCatalogRequests($state, $mapping, $index);
        return $state;
    }

    /** Resolve the AI's bounded descriptive query through the local TN VED catalog only. */
    private function applyCatalogRequests(array $state, array $mapping, SourceIndex $index): array
    {
        $items = [];
        foreach ($mapping['items'] ?? [] as $item) if (is_string($item['itemKey'] ?? null)) $items[$item['itemKey']] = $item;
        $goodsIds = [];
        foreach ($mapping['catalogRequests'] ?? [] as $request) {
            $goodsId = $request['itemKey'] ?? null;
            if (($request['graphId'] ?? null) !== '33' || ($request['fieldId'] ?? null) !== 'goods.commodityCode'
                || !is_string($goodsId) || !is_string($request['query'] ?? null)) continue;
            $item = $items[$goodsId] ?? null;
            $goods = $this->findRow($state['draft']['goods'] ?? [], $goodsId);
            $description = is_array($item) && is_string($item['description'] ?? null) ? trim($item['description']) : '';
            if ($goods === null || $description === ''
                || \Customs\Domain\TnvedCatalogue::normalize((string)($goods['description'] ?? '')) !== \Customs\Domain\TnvedCatalogue::normalize($description)) continue;

            // A catalog query is useful only for the exact, source-cited item description being mapped.
            $proofs = $this->verifiedEvidence($item['evidence'] ?? [], $index);
            $needle = mb_strtolower(SourceIndex::text($description));
            $proofIds = [];
            foreach ($proofs as $proof) {
                $quote = mb_strtolower(SourceIndex::text((string)($proof['quote'] ?? '')));
                if ($needle !== '' && str_contains($quote, $needle)) $proofIds[] = $proof['id'];
            }
            if ($proofIds === []) {
                $state['mappingIssues'][] = ['fieldId' => 'goods.commodityCode', 'code' => 'TNVED_QUERY_SOURCE', 'message' => 'Поисковый запрос ТН ВЭД не связан с цитатой описания этого товара.'];
                continue;
            }

            $nameKey = \Customs\Domain\TnvedCatalogue::normalize($description);
            $state['tnvedQueries'][$goodsId][$nameKey] = [
                'queryRu' => trim($request['query']),
                'evidenceIds' => array_values(array_unique($proofIds)),
            ];
            $goodsIds[$goodsId] = true;
        }
        if ($goodsIds !== []) $state = (new \Customs\Domain\TnvedSuggestions())->apply($state, array_keys($goodsIds));
        return $state;
    }

    /** Public-safe statuses and alternatives; raw model objects and source text are omitted. */
    public function project(array $state): array
    {
        $positions = $this->contract->positions();
        $events = [];
        $positionFacts = [];
        $resultFacts = [];
        $documentFacts = [];
        $dtsFacts = [];
        foreach ($state['graphMappingParts'] ?? [] as $part) {
            foreach ($part['results'] ?? [] as $result) {
                $key = $this->targetKey((string)$result['graphId'], $result['itemKey'] ?? null);
                $events[$key][] = [
                    'status' => $result['status'] ?? 'incomplete',
                    'reason' => $result['reason'] ?? null,
                    'searchedSourceIds' => $result['searchedSourceIds'] ?? [],
                ];
                foreach ($result['values'] ?? [] as $value) $resultFacts[$key][$value['fieldId']][] = $value;
                if (is_array($result['positionValue'] ?? null)) {
                    $positionFacts[$key][] = $result['positionValue'];
                }
            }
            foreach ($part['documentRecords'] ?? [] as $record) {
                foreach ($record['itemKeys'] ?? [] as $goodsId) {
                    foreach ($record['values'] ?? [] as $value) $documentFacts[$this->targetKey('44', $goodsId)][$value['fieldId']][] = $value;
                }
                if (($record['itemKeys'] ?? []) === []) foreach ($record['values'] ?? [] as $value) $documentFacts[$this->targetKey('44', null)][$value['fieldId']][] = $value;
            }
            foreach ($part['dtsFacts'] ?? [] as $fact) $dtsFacts[] = $fact;
        }

        $targets = [];
        foreach ($positions as $graphId => $position) {
            foreach ($this->targetEntities($position, $state) as $entityId) {
                $itemKey = $position['scope'] === 'goods' ? $entityId : null;
                $key = $this->targetKey((string)$graphId, $itemKey);
                $eventRows = $events[$key] ?? [];
                $searched = [];
                foreach ($eventRows as $event) foreach ($event['searchedSourceIds'] as $sourceId) if (is_string($sourceId)) $searched[$sourceId] = true;
                $fields = [];
                foreach ($position['bindings'] as $binding) {
                    $definition = $this->registry->field($binding['fieldId']);
                    $rows = $this->graphs->rows($state['draft'], $this->graphs->graph((string)$graphId), $definition);
                    foreach ($rows as $row) {
                        if ($binding['scope'] === 'goods' && $row['id'] !== $itemKey) continue;
                        if ($binding['scope'] === 'document' && $position['scope'] === 'goods' && ($row['goodsIds'] ?? []) !== [] && !in_array($itemKey, $row['goodsIds'], true)) continue;
                        $addressEntityId = $binding['scope'] === 'declaration' ? null : $row['id'];
                        $address = Draft::address($binding['scope'], $addressEntityId, $binding['key']);
                        $mappingValues = [...($resultFacts[$key][$binding['fieldId']] ?? []), ...($documentFacts[$key][$binding['fieldId']] ?? [])];
                        $alternatives = $this->alternatives($state, $address, $mappingValues);
                        $fieldStatus = $this->fieldStatus($binding, $address, $alternatives, $eventRows, $searched, $state);
                        $fieldKey = $binding['fieldId'] . '/' . $address;
                        if (!isset($fields[$fieldKey])) $fields[$fieldKey] = [
                            'fieldId' => $binding['fieldId'], 'address' => $address,
                            'owner' => $binding['owner'], 'status' => $fieldStatus,
                            'alternatives' => $alternatives,
                        ];
                    }
                }
                $positionValues = array_map(fn(array $value): array => $this->safePositionValue($value, $state), $positionFacts[$key] ?? []);
                $owner = $this->positionOwner($position);
                $hasConflict = count(array_filter($fields, static fn(array $field): bool => $field['status'] === 'conflict')) > 0;
                $hasFound = count(array_filter($fields, static fn(array $field): bool => $field['status'] === 'found')) > 0 || $positionValues !== [];
                $hasManual = count(array_filter($fields, static fn(array $field): bool => in_array($field['status'], ['manual','selected'], true))) > 0;
                $status = $this->targetStatus($position, $owner, $hasConflict, $hasFound, $hasManual, $eventRows, $searched, $state);
                $targets[] = [
                    'graphId' => (string)$graphId, 'entityId' => $entityId, 'status' => $status,
                    'owner' => $owner, 'searchComplete' => (bool)($state['graphMappingRunComplete'] ?? false),
                    'searchedSourceIds' => array_values(array_keys($searched)), 'reason' => $this->reason($eventRows),
                    'fields' => array_values($fields),
                    'positionValues' => $positionValues,
                ];
            }
        }
        return [
            'version' => '1.0.0', 'complete' => (bool)($state['graphMappingRunComplete'] ?? false),
            'targets' => $targets, 'dtsFacts' => $this->projectDtsFacts($state, $dtsFacts),
        ];
    }

    private function applyItem(array $state, array $item, SourceIndex $index): array
    {
        $goodsId = $item['itemKey'] ?? null;
        if (!is_string($goodsId) || !is_string($item['description'] ?? null) || trim($item['description']) === '') return $state;
        $proofs = $this->verifiedEvidence($item['evidence'] ?? [], $index);
        if ($proofs === []) return $state;
        $existing = $this->findRow($state['draft']['goods'], $goodsId);
        $authorized = false;
        foreach ($proofs as $proof) if ($index->supportsNewGoods($proof['documentId'])) $authorized = true;
        if ($existing === null && !$authorized) return $state;

        if ($existing === null) {
            $state['draft']['goods'] = array_values(array_filter($state['draft']['goods'], fn(array $row): bool => !$this->emptyGoods($row)));
            $state['draft']['goods'][] = ['id' => $goodsId, ...$this->draft->row('goods')];
        }
        foreach ($proofs as $proof) {
            $lineId = 'line-' . substr($proof['id'], 0, 16);
            $row =& $this->draft->entity($state['draft'], 'goods', $goodsId);
            $row['sourceLineIds'] = array_values(array_unique([...($row['sourceLineIds'] ?? []), $lineId]));
            $state['draft']['sourceLines'][$lineId] = ['id' => $lineId, 'origin' => 'document', 'evidenceId' => $proof['id'], 'quote' => $proof['quote']];
            unset($row);
        }
        $value = ['fieldId' => 'goods.description', 'normalizedValue' => trim($item['description']), 'rawValue' => trim($item['description']), 'unit' => null];
        return $this->storeCandidate($state, $value, $goodsId, $proofs);
    }

    private function applyPositionValue(array $state, string $graphId, array $value, SourceIndex $index): array
    {
        if ($graphId !== '15' || ($value['valueType'] ?? null) !== 'country-name') return $state;
        $country = $this->countryByExactName((string)($value['normalizedValue'] ?? ''));
        if ($country === null) return $state;
        $proofs = $this->verifiedEvidence($value['evidence'] ?? [], $index);
        if ($proofs === []) return $state;
        return $this->storeCandidate($state, [
            'fieldId' => 'declaration.dispatchCountry',
            'normalizedValue' => $country['code'], 'rawValue' => (string)$value['rawValue'], 'unit' => null,
        ], null, $proofs);
    }

    private function applyValue(array $state, array $value, ?string $entityId, SourceIndex $index): array
    {
        $field = $this->registry->field((string)$value['fieldId']);
        if (in_array($field['scope'], ['cost','payment','rate'], true) && $entityId === null) return $state;
        if (in_array($field['scope'], ['document','goods','party','transport'], true) && $entityId === null) return $state;
        try { $this->draft->entity($state['draft'], $field['scope'], $entityId); }
        catch (ApiError) { return $state; }
        $proofs = $this->verifiedEvidence($value['evidence'] ?? [], $index);
        if ($proofs === []) return $state;
        return $this->storeCandidate($state, $value, $entityId, $proofs);
    }

    private function storeCandidate(array $state, array $value, ?string $entityId, array $proofs): array
    {
        $field = $this->registry->field((string)$value['fieldId']);
        $address = Draft::address($field['scope'], $entityId, $field['key']);
        $normalized = $value['normalizedValue'] ?? null;
        if ($normalized === null || $normalized === '') return $state;
        $unit = $value['unit'] ?? $field['unit'] ?? null;
        $id = hash('sha256', json_encode([$address, $normalized, $unit], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $row =& $this->draft->entity($state['draft'], $field['scope'], $entityId);
        $rank = null;
        foreach ($proofs as $proof) {
            $source = null;
            foreach ($state['documents'] ?? [] as $document) if (($document['id'] ?? null) === $proof['documentId']) { $source = $document; break; }
            $rankValue = GraphSources::rank($field['id'], $row, $source['documentKind'] ?? null);
            if ($rankValue !== null) $rank = min($rank ?? $rankValue, $rankValue);
            $state['evidence'][$proof['id']] = $proof;
        }
        unset($row);

        $issues = (new Normalizer())->issues($field, $normalized);
        $state['candidates'][$id] ??= [
            'id' => $id, 'address' => $address, 'fieldId' => $field['id'], 'entityId' => $entityId,
            'value' => $normalized, 'unit' => $unit, 'valid' => $issues === [], 'issues' => $issues,
            'evidenceIds' => [], 'documentIds' => [], 'rawValues' => [], 'origin' => 'graph-mapping',
        ];
        $candidate =& $state['candidates'][$id];
        $candidate['origin'] = 'graph-mapping';
        $candidate['evidenceIds'] = array_values(array_unique([...($candidate['evidenceIds'] ?? []), ...array_column($proofs, 'id')]));
        $candidate['documentIds'] = array_values(array_unique([...($candidate['documentIds'] ?? []), ...array_column($proofs, 'documentId')]));
        if ($rank !== null) $candidate['rank'] = min($candidate['rank'] ?? $rank, $rank);
        $raw = ['value' => $value['rawValue'] ?? (string)$normalized, 'unit' => $value['unit'] ?? null, 'evidenceId' => $proofs[0]['id']];
        if (!in_array($raw, $candidate['rawValues'], true)) $candidate['rawValues'][] = $raw;
        unset($candidate);
        return $state;
    }

    private function verifiedEvidence(array $citations, SourceIndex $index): array
    {
        $proofs = [];
        foreach ($citations as $citation) {
            try {
                $proof = $index->verify($citation);
                unset($proof['context']);
                $proofs[$proof['id']] = $proof;
            } catch (ApiError) {
                // Rejected citations never become candidates or source links.
            }
        }
        return array_values($proofs);
    }

    private function ensureDocument(array $state, string $recordId, array $goodsIds, array $documentIds): array
    {
        $found = false;
        foreach ($state['draft']['documents'] as &$record) if ($record['id'] === $recordId) {
            $record['goodsIds'] = array_values(array_unique([...($record['goodsIds'] ?? []), ...$goodsIds]));
            $record['documentIds'] = array_values(array_unique([...($record['documentIds'] ?? []), ...$documentIds]));
            $found = true; break;
        }
        unset($record);
        if (!$found) $state['draft']['documents'][] = ['id' => $recordId, ...$this->draft->row('document'), 'goodsIds' => $goodsIds, 'documentIds' => $documentIds];
        return $state;
    }

    private function entityId(string $fieldId, array $graph, ?string $itemKey): ?string
    {
        $field = $this->registry->field($fieldId);
        return match ($field['scope']) {
            'declaration' => null,
            'goods' => $itemKey,
            'party' => is_string($graph['entityRole'] ?? null) ? 'party-' . $graph['entityRole'] : null,
            'transport' => is_string($graph['entityRole'] ?? null) ? 'transport-' . $graph['entityRole'] : null,
            default => null,
        };
    }

    private function targetEntities(array $position, array $state): array
    {
        if ($position['scope'] === 'goods') {
            $ids = [];
            foreach ($state['draft']['goods'] ?? [] as $row) if (!$this->emptyGoods($row) || count($state['draft']['goods']) === 1) $ids[] = $row['id'];
            return $ids;
        }
        if (in_array($position['entityRole'] ?? null, ['seller','buyer','consignor','consignee','declarant','financial'], true)) return ['party-' . $position['entityRole']];
        if (in_array($position['entityRole'] ?? null, ['arrival','border'], true)) return ['transport-' . $position['entityRole']];
        return [null];
    }

    private function bindingEntityId(array $binding, array $position, ?string $itemKey): ?string
    {
        return match ($binding['scope']) {
            'goods' => $itemKey,
            'party' => in_array($position['entityRole'] ?? null, ['seller','buyer','consignor','consignee','declarant','financial'], true) ? 'party-' . $position['entityRole'] : null,
            'transport' => in_array($position['entityRole'] ?? null, ['arrival','border'], true) ? 'transport-' . $position['entityRole'] : null,
            default => null,
        };
    }

    private function fieldStatus(array $binding, string $address, array $alternatives, array $events, array $searched, array $state): string
    {
        $meta = $state['draft']['fieldStates'][$address] ?? [];
        if (($meta['conflict'] ?? false) || ($meta['needsReview'] ?? false)) return 'conflict';
        if (count($alternatives) > 1) return 'conflict';
        if (in_array($meta['status'] ?? '', ['manual','selected'], true)) return $meta['status'];
        if (count($alternatives) === 1) return ($alternatives[0]['candidateId'] ?? null) === null ? 'incomplete' : 'found';
        if (!in_array($binding['owner'], ['document','manual'], true)) return $binding['owner'];
        return $this->absenceStatus($events, $searched, $state);
    }

    private function alternatives(array $state, string $address, array $mappedValues): array
    {
        $byId = [];
        foreach ($state['candidates'] ?? [] as $candidate) if (($candidate['address'] ?? null) === $address && ($candidate['valid'] ?? false)) {
            $id = (string)$candidate['id'];
            $byId[$id] = [
                'candidateId' => $id, 'value' => $candidate['value'], 'rank' => $candidate['rank'] ?? null,
                'evidenceIds' => array_values(array_filter($candidate['evidenceIds'] ?? [], fn(string $proof): bool => isset($state['evidence'][$proof]))),
                'documentIds' => array_values(array_unique($candidate['documentIds'] ?? [])),
            ];
        }
        // Document records are already converted into candidates; this fallback keeps a cited result visible if
        // a legacy session cannot materialize its corresponding row.
        foreach ($mappedValues as $value) {
            $normalized = $value['normalizedValue'] ?? null;
            if ($normalized === null) continue;
            $id = hash('sha256', json_encode([$address, $normalized, $value['unit'] ?? null], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $proofIds = [];
            foreach ($value['evidence'] ?? [] as $citation) foreach ($state['evidence'] ?? [] as $proof) if (($proof['sourceId'] ?? null) === ($citation['sourceId'] ?? null)) $proofIds[] = $proof['id'];
            if (!isset($byId[$id]) && $proofIds !== []) $byId[$id] = ['candidateId' => null, 'value' => $normalized, 'rank' => null, 'evidenceIds' => array_values(array_unique($proofIds)), 'documentIds' => []];
        }
        $alternatives = array_values($byId);
        usort($alternatives, static fn(array $a, array $b): int => ($a['rank'] ?? 99) <=> ($b['rank'] ?? 99));
        return $alternatives;
    }

    private function targetStatus(array $position, string $owner, bool $conflict, bool $found, bool $manual, array $events, array $searched, array $state): string
    {
        if ($owner === 'not_applicable') return 'not_applicable';
        if ($owner === 'calculated') return 'calculated';
        if ($conflict) return 'conflict';
        if ($found) return 'found';
        if ($manual) return 'manual';
        if (!in_array($owner, ['document','manual'], true)) return $owner;
        return $this->absenceStatus($events, $searched, $state);
    }

    private function positionOwner(array $position): string
    {
        if ($position['fillMode'] === 'not_applicable') return 'not_applicable';
        if ($position['calculationStage'] === 'result' || $position['fillMode'] === 'calculated') return 'calculated';
        $owners = $position['owners'];
        if ($owners === [] && isset($position['positionValue']['owner'])) $owners[] = $position['positionValue']['owner'];
        if ($owners === []) return 'incomplete';
        foreach (['document','manual','reference','profile','calculated','not_applicable'] as $priority) if (in_array($priority, $owners, true)) return $priority;
        return 'incomplete';
    }

    private function absenceStatus(array $events, array $searched, array $state): string
    {
        if (!(bool)($state['graphMappingRunComplete'] ?? false)) return 'incomplete';
        $expected = $state['graphMappingExpectedSourceIds'] ?? [];
        if ($expected === [] || array_diff($expected, array_keys($searched)) !== []) return 'incomplete';
        if ($events !== [] && count(array_filter($events, static fn(array $event): bool => ($event['status'] ?? '') === 'not_found')) === count($events)) return 'not_found';
        return 'incomplete';
    }

    private function reason(array $events): ?string
    {
        foreach (array_reverse($events) as $event) if (is_string($event['reason'] ?? null) && trim($event['reason']) !== '') return mb_substr($event['reason'], 0, 500);
        return null;
    }

    private function projectDtsFacts(array $state, array $facts): array
    {
        $groups = [];
        foreach ($facts as $fact) {
            $value = $fact['value'] ?? [];
            $key = implode('/', [$fact['matrixId'] ?? '', $value['fieldId'] ?? '', $fact['itemKey'] ?? '_', $fact['participantRole'] ?? '_']);
            $entry =& $groups[$key];
            $entry ??= ['matrixId' => $fact['matrixId'] ?? null, 'fieldId' => $value['fieldId'] ?? null, 'itemKey' => $fact['itemKey'] ?? null, 'participantRole' => $fact['participantRole'] ?? null, 'status' => 'incomplete', '_values' => []];
            $entry['_values'][] = $value;
            unset($entry);
        }
        foreach ($groups as &$entry) {
            $address = null;
            try {
                $field = $this->registry->field((string)($entry['fieldId'] ?? ''));
                $entityId = match ($field['scope']) {
                    'goods' => $entry['itemKey'] ?? null,
                    'party' => isset($entry['participantRole']) ? 'party-' . $entry['participantRole'] : null,
                    'declaration' => null,
                    default => null,
                };
                if (in_array($field['scope'], ['goods','party','declaration'], true)) $address = Draft::address($field['scope'], $entityId, $field['key']);
            } catch (ApiError) {}
            if ($address !== null) $entry['alternatives'] = $this->alternatives($state, $address, $entry['_values']);
            else {
                $byValue = [];
                foreach ($entry['_values'] as $value) {
                    $proofIds = [];$documentIds=[];
                    foreach ($value['evidence'] ?? [] as $citation) foreach ($state['evidence'] ?? [] as $proof) if (($proof['sourceId'] ?? null) === ($citation['sourceId'] ?? null)) {$proofIds[]=$proof['id'];$documentIds[]=$proof['documentId'];}
                    $normalized = $value['normalizedValue'] ?? null;
                    if ($normalized === null || $proofIds === []) continue;
                    $signature = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    $byValue[$signature] ??= ['candidateId'=>null,'value'=>$normalized,'rank'=>null,'evidenceIds'=>[],'documentIds'=>[]];
                    $byValue[$signature]['evidenceIds']=array_values(array_unique([...$byValue[$signature]['evidenceIds'],...$proofIds]));
                    $byValue[$signature]['documentIds']=array_values(array_unique([...$byValue[$signature]['documentIds'],...$documentIds]));
                }
                $entry['alternatives']=array_values($byValue);
            }
            if (count($entry['alternatives']) > 1) $entry['status'] = 'conflict';
            elseif (count($entry['alternatives']) === 1 && ($entry['alternatives'][0]['candidateId'] ?? null) !== null) $entry['status'] = 'found';
            unset($entry['_values']);
        }
        unset($entry);
        return array_values($groups);
    }

    private function safePositionValue(array $value, array $state): array
    {
        return [
            'valueType' => $value['valueType'] ?? null,
            'value' => $value['normalizedValue'] ?? null,
            'evidenceIds' => $this->evidenceIds($value['evidence'] ?? [], $state),
        ];
    }

    private function evidenceIds(array $citations, array $state): array
    {
        $sourceIds = [];$ids=[];
        foreach ($citations as $citation) if (is_string($citation['sourceId'] ?? null)) $sourceIds[$citation['sourceId']] = true;
        foreach ($state['evidence'] ?? [] as $proof) if (isset($sourceIds[$proof['sourceId'] ?? ''])) $ids[]=$proof['id'];
        return array_values(array_unique($ids));
    }

    private function targetKey(string $graphId, ?string $itemKey): string { return $graphId . '/' . ($itemKey ?? '_'); }

    private function findRow(array $rows, string $id): ?array
    {
        foreach ($rows as $row) if (($row['id'] ?? null) === $id) return $row;
        return null;
    }

    private function emptyGoods(array $row): bool
    {
        if (($row['sourceLineIds'] ?? []) !== []) return false;
        foreach ($this->registry->fields() as $field) if ($field['scope'] === 'goods' && !isset($field['fixedValue']) && !in_array('calculated', $field['acquisition'] ?? [], true)) {
            $value = $row[$field['key']] ?? null;
            if ($value !== null && $value !== '' && $value !== []) return false;
        }
        return true;
    }

    private function countryByExactName(string $name): ?array
    {
        $needle = mb_strtolower(trim($name));
        if ($needle === '') return null;
        foreach ((new Classifiers())->data()['countries'] as $country) if (mb_strtolower(trim($country['name'])) === $needle) return $country;
        return null;
    }
}
