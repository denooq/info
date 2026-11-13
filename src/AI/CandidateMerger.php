<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Domain\Draft;
use Customs\Domain\Registry;
use Customs\Http\ApiError;

final class CandidateMerger
{
    public function __construct(private readonly Registry $registry) {}

    public function merge(array $state, array $response, array $sources): array
    {
        $model = new Draft($this->registry);
        $index = new SourceIndex($sources);
        $verifier = new CandidateVerifier($this->registry, $index);
        $entities = [];
        $state['draft'] = array_replace($model->empty(), $state['draft']);
        $state['mappingIssues'] ??= [];
        $verified = []; $needed = [];
        foreach ($response['candidates'] as $proposal) {
            try {
                $candidate = $verifier->verify($proposal);
                $verified[] = $candidate;
                $needed[$candidate['scope'] . ':' . $candidate['entityKey']] = true;
            } catch (ApiError $error) { $state['mappingIssues'][] = ['fieldId' => $proposal['fieldId'] ?? null, 'code' => $error->errorCode, 'message' => $error->getMessage()]; }
        }
        foreach ($response['entities'] as $entity) {
            if (!isset($needed[$entity['scope'] . ':' . $entity['key']])) continue;
            try {
                $evidence = $index->verify($entity['source']);
                unset($evidence['context']);
                if ($entity['scope'] === 'goods' && (preg_match('/^(?:TOTAL|ИТОГО|ВСЕГО)\b/ui', trim($entity['source']['quote'])) || !preg_match('/\p{L}/u', $entity['source']['quote']))) throw new ApiError('TOTAL_NOT_GOODS', 'Итоговая строка не является товаром.');
                $knownGoodsIds = array_column(array_filter($state['draft']['goods'], static fn(array $row): bool => ($row['description'] ?? null) !== null || ($row['sourceLineIds'] ?? []) !== []), 'id');
                if ($entity['scope'] === 'goods' && !$index->supportsNewGoods($evidence['documentId']) && !isset($state['draft']['entityAliases']['goods:' . $entity['key']]) && !in_array($entity['key'], $knownGoodsIds, true)) throw new ApiError('GOODS_SHIPMENT_UNCONFIRMED', 'Перечень продукции разрешительного документа не создаёт товары поставки.');
                $entityId = $this->resolveEntity($state['draft'], $entity, $evidence['id'], $model);
                $entities[$entity['key']] = ['scope' => $entity['scope'], 'id' => $entityId];
                $state['evidence'][$evidence['id']] = $evidence;
                if ($entity['scope'] === 'goods' && $index->supportsNewGoods($evidence['documentId'])) {
                    $lineId = 'line-' . substr($evidence['id'], 0, 16);
                    $row =& $model->entity($state['draft'], 'goods', $entityId);
                    $row['sourceLineIds'] = array_values(array_unique([...$row['sourceLineIds'], $lineId]));
                    $state['draft']['sourceLines'][$lineId] = ['id' => $lineId,'origin' => 'document','evidenceId' => $evidence['id'],'quote' => $evidence['quote']];
                    unset($row);
                }
            } catch (ApiError $error) { $state['mappingIssues'][] = ['code' => $error->errorCode,'message' => $error->getMessage()]; }
        }
        foreach ($verified as $candidate) {
            try {
                $scope = $candidate['scope'];
                $entityId = null;
                if (!in_array($scope, ['declaration','keden'], true)) {
                    $entity = $entities[$candidate['entityKey']] ?? null;
                    if ($entity === null || $entity['scope'] !== $scope) throw new ApiError('CANDIDATE_ENTITY', 'Не подтверждена строка предложения.');
                    $entityId = $entity['id'];
                }
                $field = $this->registry->field($candidate['fieldId']);
                $address = Draft::address($scope, $entityId, $field['key']);
                $id = hash('sha256', json_encode([$address,$candidate['value'],$candidate['unit']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $evidence = $candidate['evidence'];
                $state['evidence'][$evidence['id']] = $evidence;
                if (!isset($state['candidates'][$id])) $state['candidates'][$id] = ['id' => $id,'address' => $address,'fieldId' => $candidate['fieldId'],'entityId' => $entityId,'value' => $candidate['value'],'unit' => $candidate['unit'],'valid' => $candidate['valid'],'issues' => $candidate['issues'],'evidenceIds' => [],'documentIds' => [],'rawValues' => []];
                $current =& $state['candidates'][$id];
                $current['evidenceIds'] = array_values(array_unique([...$current['evidenceIds'],$evidence['id']]));
                $current['documentIds'] = array_values(array_unique([...$current['documentIds'],$evidence['documentId']]));
                $rawRecord = ['value' => $candidate['rawValue'],'unit' => $candidate['sourceUnit'],'evidenceId' => $evidence['id']];
                if (!in_array($rawRecord, $current['rawValues'], true)) $current['rawValues'][] = $rawRecord;
                unset($current);
            } catch (ApiError $error) { $state['mappingIssues'][] = ['fieldId' => $candidate['fieldId'],'code' => $error->errorCode,'message' => $error->getMessage()]; }
        }
        $state = $this->reconcile($state);
        foreach ($response['classificationQueries'] ?? [] as $query) {
            try {
                $evidence = $index->verify($query['source']);
                if (!str_contains(mb_strtolower($evidence['quote']), mb_strtolower($query['name']))) throw new ApiError('TNVED_QUERY_SOURCE', 'Название для подбора кода отсутствует в цитате.');
                $entity = $entities[$query['entityKey']] ?? null;
                $goodsId = ($entity['scope'] ?? null) === 'goods' ? $entity['id'] : ($state['draft']['entityAliases']['goods:' . $query['entityKey']] ?? $query['entityKey']);
                $goods = array_values(array_filter($state['draft']['goods'], static fn(array $row): bool => $row['id'] === $goodsId))[0] ?? null;
                $nameKey = \Customs\Domain\TnvedCatalogue::normalize($query['name']);
                $knownName = $goods !== null && \Customs\Domain\TnvedCatalogue::normalize($goods['description'] ?? '') === $nameKey;
                foreach ($state['candidates'] as $candidate) if ($candidate['fieldId'] === 'goods.description' && $candidate['entityId'] === $goodsId && \Customs\Domain\TnvedCatalogue::normalize((string) $candidate['value']) === $nameKey) $knownName = true;
                if ($goods === null || !$knownName) throw new ApiError('TNVED_QUERY_GOODS', 'Поисковое название не совпадает с текущим товаром.');
                unset($evidence['context']);
                $state['evidence'][$evidence['id']] = $evidence;
                $state['tnvedQueries'][$goodsId][$nameKey] = ['queryRu' => $query['queryRu'], 'evidenceIds' => [$evidence['id']]];
            } catch (ApiError $error) { $state['mappingIssues'][] = ['fieldId' => 'goods.commodityCode', 'code' => $error->errorCode, 'message' => $error->getMessage()]; }
        }
        return $state;
    }

    public function reconcile(array $state): array
    {
        $model = new Draft($this->registry);
        $byAddress = [];
        foreach ($state['candidates'] as $candidate) $byAddress[$candidate['address']][] = $candidate;
        foreach ($byAddress as $address => $candidates) {
            $meta = $state['draft']['fieldStates'][$address] ?? [];
            [$scope,$entityId,$key] = explode('/', $address, 3);
            $row =& $model->entity($state['draft'], $scope, $entityId === '_' ? null : $entityId);
            if (in_array($meta['status'] ?? null, ['manual','selected'], true)) {
                foreach ($candidates as $candidate) if ($candidate['valid'] && !in_array($candidate['id'], $meta['reviewedCandidateIds'] ?? [], true) && $candidate['value'] !== $row[$key]) $state['draft']['fieldStates'][$address]['needsReview'] = true;
                unset($row); continue;
            }
            $eligible = array_values(array_filter($candidates, fn(array $candidate): bool => $candidate['valid']));
            $one = count($eligible) === 1;
            $row[$key] = $one ? $eligible[0]['value'] : null;
            $status = $one ? (($eligible[0]['origin'] ?? '') === 'tnved' ? 'reference' : 'auto') : 'unknown';
            $state['draft']['fieldStates'][$address] = [...$meta, 'status' => $status,'candidateId' => $one ? $eligible[0]['id'] : null,'evidenceIds' => $one ? $eligible[0]['evidenceIds'] : [],'reviewedCandidateIds' => [],'conflict' => count($eligible) > 1,'needsReview' => !$one];
            unset($row);
        }
        return $state;
    }

    private function resolveEntity(array &$draft, array $entity, string $signature, Draft $model): string
    {
        $scope = $entity['scope']; $collection = $model->collection($scope);
        $alias = $scope . ':' . $entity['key'];
        if (isset($draft['entityAliases'][$alias])) return $draft['entityAliases'][$alias];
        if ($scope === 'goods') foreach ($draft['goods'] as $row) if ($row['id'] === $entity['key']) return $draft['entityAliases'][$alias] = $row['id'];
        if ($scope === 'party') {
            if (!in_array($entity['role'], ['seller','buyer','consignor','consignee','declarant','financial'], true)) throw new ApiError('ENTITY_ROLE', 'Неизвестная роль участника.');
            foreach ($draft['parties'] as $row) if ($row['role'] === $entity['role']) return $draft['entityAliases'][$alias] = $row['id'];
        }
        foreach ($draft[$collection] as $row) if (($row['_sourceSignature'] ?? null) === $signature) return $draft['entityAliases'][$alias] = $row['id'];
        foreach ($draft[$collection] as &$row) {
            $empty = true;
            foreach ($this->registry->fields() as $field) if ($field['scope'] === $scope && !isset($field['fixedValue']) && !in_array('calculated', $field['acquisition'], true) && !in_array($field['key'], ['stage','role'], true)) {
                if (($row[$field['key']] ?? null) !== null && ($row[$field['key']] ?? null) !== []) { $empty = false; break; }
            }
            if ($empty && ($scope !== 'transport' || $row['stage'] === $entity['role'])) { $row['_sourceSignature'] = $signature; return $draft['entityAliases'][$alias] = $row['id']; }
        }
        unset($row);
        $id = $scope . '-' . substr($signature, 0, 16);
        $row = ['id' => $id, ...$model->row($scope), '_sourceSignature' => $signature];
        if ($scope === 'transport') {
            if (!in_array($entity['role'], ['arrival','border'], true)) throw new ApiError('ENTITY_ROLE', 'Неизвестный этап транспорта.');
            $row['stage'] = $entity['role'];
        }
        $draft[$collection][] = $row;
        return $draft['entityAliases'][$alias] = $id;
    }
}
