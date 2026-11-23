<?php
declare(strict_types=1);

namespace Customs\Domain;

use Customs\AI\CandidateMerger;

final class TnvedSuggestions
{
    public function __construct(private readonly TnvedCatalogue $catalogue = new TnvedCatalogue()) {}

    public function apply(array $state, ?array $goodsIds = null): array
    {
        if (isset($state['tnvedQueries'])) $state['tnvedQueries'] = array_intersect_key($state['tnvedQueries'], array_fill_keys(array_column($state['draft']['goods'], 'id'), true));
        foreach ($state['draft']['goods'] as $goods) {
            $id = $goods['id'];
            if ($goodsIds !== null && !in_array($id, $goodsIds, true)) continue;
            $description = trim((string) ($goods['description'] ?? ''));
            $address = Draft::address('goods', $id, 'commodityCode');
            $meta = array_replace(DeclarationDate::meta('unknown'), $state['draft']['fieldStates'][$address] ?? []);
            $meta['evidenceIds'] ??= [];
            $state['draft']['fieldStates'][$address] = $meta;
            $wasReference = ($meta['status'] ?? '') === 'reference';
            $query = $state['tnvedQueries'][$id][TnvedCatalogue::normalize($description)] ?? null;
            if ($query !== null && array_diff($query['evidenceIds'], array_keys($state['evidence'])) !== []) $query = null;
            $descriptionAddress = Draft::address('goods', $id, 'description');
            $sourceEvidence = $state['draft']['fieldStates'][$descriptionAddress]['evidenceIds'] ?? [];
            $sourceEvidence = array_values(array_unique([...$sourceEvidence, ...($query['evidenceIds'] ?? [])]));
            $sourceEvidence = array_values(array_filter($sourceEvidence, static fn(string $sourceId): bool => isset($state['evidence'][$sourceId])));
            $basis = hash('sha256', json_encode([$description, $query['queryRu'] ?? null, $sourceEvidence, $this->catalogue->version()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (($meta['tnved']['basis'] ?? null) === $basis) continue;
            $state['candidates'] = array_filter($state['candidates'], static fn(array $candidate): bool => $candidate['address'] !== $address || ($candidate['origin'] ?? null) !== 'tnved' || (!$wasReference && ($meta['candidateId'] ?? null) === $candidate['id']));
            if (($meta['status'] ?? '') === 'reference') {
                foreach ($state['draft']['goods'] as &$row) if ($row['id'] === $id) $row['commodityCode'] = null;
                unset($row);
                $meta = ['status' => 'unknown', 'candidateId' => null, 'evidenceIds' => [], 'needsReview' => false, 'conflict' => false];
            }
            if ($description === '') { $meta['tnved'] = ['basis' => $basis, 'status' => 'missing_name', 'message' => 'Укажите описание товара для подбора ТН ВЭД.']; $state['draft']['fieldStates'][$address] = $meta; continue; }
            // A directly documented or manually chosen code has precedence over inferred suggestions.
            $manual = in_array($meta['status'] ?? '', ['manual','selected'], true) && ($goods['commodityCode'] ?? null) !== null && $goods['commodityCode'] !== '';
            if (!$wasReference && ($manual || $this->catalogue->entry((string) ($goods['commodityCode'] ?? '')) !== null)) {
                $meta['tnved'] = ['basis' => $basis, 'status' => $manual ? 'manual' : 'existing', 'message' => $manual ? 'Код задан пользователем.' : 'Код найден в справочнике ТН ВЭД.'];
                $state['draft']['fieldStates'][$address] = $meta; continue;
            }
            $unlistedDocumentCode = false;
            foreach ($state['candidates'] as $candidate) if ($candidate['address'] === $address && ($candidate['origin'] ?? '') !== 'tnved' && preg_match('/^[0-9]{10}$/D', (string) $candidate['value']) && !TnvedCatalogue::containsCode((string) $candidate['value'])) $unlistedDocumentCode = true;
            if ($unlistedDocumentCode) {
                $meta['tnved'] = ['basis' => $basis, 'status' => 'unlisted_document_code', 'message' => 'Код из документа отсутствует в справочнике; подтвердите его вручную.'];
                $state['draft']['fieldStates'][$address] = $meta; continue;
            }
            // Russian names need no AI rewrite; generic English names cannot establish hidden properties.
            $queryRu = preg_match('/[а-я]/ui', $description) ? null : ($query['queryRu'] ?? null);
            $result = $this->catalogue->search($description, $queryRu);
            if ($result['exact'] !== null && $queryRu !== null && preg_match('/\b(?:glue|adhesives?|parts?|goods|products?|materials?|plastics?|machines?|bolts?|screws?)\b/i', $description)) $result['exact'] = null;
            $status = $result['exact'] !== null ? 'matched' : ($result['total'] === 0 ? 'not_found' : 'ambiguous');
            $message = match ($status) {
                'matched' => 'Код подобран по наименованию из справочника ТН ВЭД.',
                'not_found' => 'Код по этому наименованию не найден. Уточните описание или укажите код вручную.',
                default => 'Подходящих позиций: ' . $result['total'] . '. Уточните состав, назначение и характеристики товара или выберите подходящий код.',
            };
            $meta['tnved'] = ['basis' => $basis, 'status' => $status, 'message' => $message, 'total' => $result['total']];
            $state['draft']['fieldStates'][$address] = $meta;
            foreach ($result['matches'] as $entry) {
                $evidenceId = 'tnved-' . hash('sha256', $this->catalogue->version() . $entry['code']);
                $state['evidence'][$evidenceId] = ['id' => $evidenceId, 'documentId' => 'tnved-catalogue', 'documentName' => 'Справочник ТН ВЭД · ' . $entry['code'], 'kind' => 'tnved', 'catalogueVersion' => $this->catalogue->metadata()['source_sha256'], 'page' => $entry['page'], 'quote' => $entry['fullName'], 'url' => $entry['sourceUrl']];
                $candidateId = hash('sha256', json_encode([$address, $entry['code'], null], JSON_THROW_ON_ERROR));
                $documentIds = [];
                foreach ($sourceEvidence as $sourceId) if (isset($state['evidence'][$sourceId])) $documentIds[] = $state['evidence'][$sourceId]['documentId'];
                $valid = $result['exact'] !== null || $result['total'] > 1;
                $state['candidates'][$candidateId] = ['id' => $candidateId, 'address' => $address, 'fieldId' => 'goods.commodityCode', 'entityId' => $id, 'value' => $entry['code'], 'unit' => null, 'origin' => 'tnved', 'catalogueName' => $entry['name'], 'valid' => $valid, 'issues' => $valid ? [] : ['Найдено близкое описание ' . $entry['code'] . '; однозначное соответствие не подтверждено.'], 'evidenceIds' => array_values(array_unique([$evidenceId, ...$sourceEvidence])), 'documentIds' => array_values(array_unique($documentIds)), 'rawValues' => []];
            }
        }
        return (new CandidateMerger(new Registry()))->reconcile($state);
    }
}
