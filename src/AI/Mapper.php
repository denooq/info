<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Domain\GraphMappingContract;
use Customs\Http\ApiError;

/** Whole-bundle semantic mapper with bounded source windows and repairs. */
final class Mapper
{
    public const PROMPT_VERSION = 'customs-graph-mapping-v2.1.0';
    private GraphMappingContract $contract;

    public function __construct(private readonly MistralClient $client)
    {
        $this->contract = new GraphMappingContract();
    }

    /** Build source windows against the exact serialized Mistral request body. */
    public function plan(array $manifest, int $maxContextBytes, int $maxWindows, array $knownGoods = [], array $options = []): array
    {
        $this->assertManifest($manifest);
        [$allUnits, $idMap] = $this->sourceUnits($manifest['sources']);
        if ($allUnits === []) return [];
        if ($maxContextBytes < 1 || $maxWindows < 1) throw new \InvalidArgumentException('Mapping limits must be positive.');

        $allIds = array_values(array_keys($idMap));
        $requestedIds = isset($options['sourceIds']) && is_array($options['sourceIds'])
            ? array_values(array_unique(array_filter($options['sourceIds'], 'is_string')))
            : null;
        $wanted = $requestedIds === null ? null : array_fill_keys($requestedIds, true);
        $units = $wanted === null ? $allUnits : array_values(array_filter($allUnits, static function (array $unit) use ($wanted): bool {
            if (isset($wanted[$unit['sourceId']])) return true;
            foreach ($unit['cells'] ?? [] as $cell) if (isset($wanted[$cell['sourceId']])) return true;
            return false;
        }));
        if ($units === []) return [];

        $system = $this->prompt();
        $schema = $this->contract->responseSchema();
        $measure = function (array $candidateUnits, array $window) use ($knownGoods, $options, $system, $schema): int {
            $context = $this->context($candidateUnits, $knownGoods, $options, $window);
            $input = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            return $this->client->structuredPayloadBytes($system, $input, $schema);
        };

        $sourceIds = static function (array $candidateUnits): array {
            $ids = [];
            foreach ($candidateUnits as $unit) {
                $ids[] = $unit['sourceId'];
                foreach ($unit['cells'] ?? [] as $cell) $ids[] = $cell['sourceId'];
            }
            return array_values(array_unique($ids));
        };
        $windowFor = static function (array $candidateUnits, int $index, int $count, array $allIds, bool $wholeRun) use ($sourceIds): array {
            $ids = $sourceIds($candidateUnits);
            return [
                'index' => $index, 'count' => $count, 'sourceIds' => $ids, 'allSourceIds' => $allIds,
                'completeBundle' => $wholeRun, 'wholeBundleInWindow' => $wholeRun && count(array_diff($allIds, $ids)) === 0,
                'omittedSourceCount' => count(array_diff($allIds, $ids)),
            ];
        };

        // Use the configured maximum index/count while packing. Their serialized widths
        // are at least as large as the final values, so final-window verification is safe.
        $wholeRun = $wanted === null || count(array_intersect($allIds, $requestedIds ?? [])) === count($allIds);
        $current = []; $groups = [];
        foreach ($units as $unit) {
            $candidate = [...$current, $unit];
            $window = $windowFor($candidate, $maxWindows - 1, $maxWindows, $allIds, $wholeRun);
            $bytes = $measure($candidate, $window);
            if ($bytes <= $maxContextBytes) { $current = $candidate; continue; }

            if ($current !== []) {
                $groups[] = $current;
                $current = [];
            }
            $single = [$unit];
            $singleWindow = $windowFor($single, $maxWindows - 1, $maxWindows, $allIds, $wholeRun);
            $singleBytes = $measure($single, $singleWindow);
            if ($singleBytes > $maxContextBytes) {
                throw new ApiError('MAPPING_CONTEXT_LIMIT', 'Один неделимый блок источника превышает безопасный лимит контекста.', 413, [
                    'measuredBytes' => $singleBytes, 'limitBytes' => $maxContextBytes,
                ]);
            }
            $current = $single;
        }
        if ($current !== []) $groups[] = $current;

        if (count($groups) > $maxWindows) {
            throw new ApiError('MAPPING_WINDOW_LIMIT', 'Для полного комплекта требуется больше окон сопоставления, чем разрешено настройками.', 413, [
                'requiredWindows' => count($groups), 'limitWindows' => $maxWindows,
            ]);
        }

        $count = count($groups); $plan = [];
        foreach ($groups as $index => $group) {
            $window = $windowFor($group, $index, $count, $allIds, $wholeRun);
            $bytes = $measure($group, $window);
            if ($bytes > $maxContextBytes) {
                throw new ApiError('MAPPING_CONTEXT_LIMIT', 'Полный запрос сопоставления превышает безопасный лимит контекста.', 413, [
                    'measuredBytes' => $bytes, 'limitBytes' => $maxContextBytes,
                ]);
            }
            $plan[] = [...$window, 'requestBytes' => $bytes, 'maxContextBytes' => $maxContextBytes];
        }
        return $plan;
    }

    /** Execute one bounded source window and repair omissions/citations at most twice. */
    public function request(array $manifest, array $knownGoods = [], array $options = []): array
    {
        $this->assertManifest($manifest);
        [$allUnits, $allIds] = $this->sourceUnits($manifest['sources']);
        if ($allUnits === []) throw new ApiError('OCR_REQUIRED', 'Нет распознанного текста для сопоставления.', 409);
        $window = $options['window'] ?? ['index' => 0, 'count' => 1, 'sourceIds' => array_keys($allIds), 'allSourceIds' => array_keys($allIds), 'completeBundle' => true, 'wholeBundleInWindow' => true, 'omittedSourceCount' => 0];
        $wanted = array_fill_keys($window['sourceIds'] ?? array_keys($allIds), true);
        $units = array_values(array_filter($allUnits, static function (array $unit) use ($wanted): bool {
            if (isset($wanted[$unit['sourceId']])) return true;
            foreach ($unit['cells'] ?? [] as $cell) if (isset($wanted[$cell['sourceId']])) return true;
            return false;
        }));
        if ($units === []) throw new ApiError('MAPPING_WINDOW_EMPTY', 'Окно сопоставления не содержит доступных источников.', 422);

        $context = $this->context($units, $knownGoods, $options, $window);
        $maxContextBytes = (int)($options['maxContextBytes'] ?? 180000);
        $prompt = $this->prompt();
        $schema = $this->contract->responseSchema();
        $maxRepairs = max(0, min(2, (int)($options['maxRepairs'] ?? 2)));
        $repairRequest = null; $usage = []; $calls = 0; $totalInputBytes = 0; $mapping = null; $failure = null;

        for ($attempt = 0; $attempt <= $maxRepairs; $attempt++) {
            $requestContext = $context;
            if ($repairRequest !== null) $requestContext['repairRequest'] = $repairRequest;
            $input = json_encode($requestContext, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $requestBytes = $this->client->structuredPayloadBytes($prompt, $input, $schema);
            if ($requestBytes > $maxContextBytes) throw new ApiError('MAPPING_CONTEXT_LIMIT', 'Полный запрос сопоставления превышает безопасный лимит контекста.', 413, [
                'measuredBytes' => $requestBytes, 'limitBytes' => $maxContextBytes,
            ]);
            $totalInputBytes += $requestBytes;
            $response = $this->client->structured($prompt, $input, $schema);
            $calls++;
            foreach ($response['usage'] ?? [] as $key => $value) if (is_numeric($value)) $usage[$key] = ($usage[$key] ?? 0) + $value;
            $choice = $response['choices'][0] ?? null;
            if (($choice['finish_reason'] ?? null) !== 'stop') {
                if (($choice['finish_reason'] ?? null) === 'length') {
                    $mapping = $this->incompleteMapping([], 'Ответ AI ограничен размером окна вывода.');
                    $failure = 'MAPPING_OUTPUT_LIMIT';
                    break;
                }
                throw new ApiError('MAPPING_INCOMPLETE', 'Ответ сопоставления граф не завершён.', 502);
            }

            try {
                $content = $choice['message']['content'] ?? null;
                if (!is_string($content)) throw new ApiError('GRAPH_MAPPING_SCHEMA', 'В ответе сопоставления отсутствует JSON.', 502);
                $candidate = json_decode($content, true, 256, JSON_THROW_ON_ERROR);
                $this->contract->validateResponse($candidate);
                $this->verifyEvidence($candidate, $manifest['sources'], $allIds);
                (new GraphMappingVerifier($this->contract))->verify($candidate, $manifest['sources']);
                $missing = $this->missingTargets($candidate, $window);
                if ($missing !== [] && $attempt < $maxRepairs) {
                    $repairRequest = ['reason' => 'missing_graph_results', 'targets' => $missing, 'attempt' => $attempt + 1];
                    continue;
                }
                foreach ($missing as $target) $candidate['results'][] = $this->incompleteResult($target, 'AI не вернул результат после ограниченных попыток исправления.');
                $mapping = $this->normalizeWindowAbsence($candidate, $window);
                break;
            } catch (ApiError $error) {
                if (!in_array($error->errorCode, ['GRAPH_MAPPING_SCHEMA', 'GRAPH_MAPPING_VALUE', 'EVIDENCE_INVALID', 'GRAPH_VALUE_CANONICAL', 'GRAPH_VALUE_CURRENCY', 'GRAPH_VALUE_SEMANTIC', 'UNIT_UNCONFIRMED'], true)) throw $error;
                $failure = $error->errorCode;
                if ($attempt < $maxRepairs) {
                    $repairRequest = ['reason' => $error->errorCode, 'instruction' => 'Перепроверь значения и цитаты по переданным sourceId; не повторяй непроверенное предложение.', 'attempt' => $attempt + 1];
                    continue;
                }
                $mapping = $this->incompleteMapping([], $error->getMessage());
            } catch (\JsonException) {
                $failure = 'GRAPH_MAPPING_SCHEMA';
                if ($attempt < $maxRepairs) { $repairRequest = ['reason' => $failure, 'instruction' => 'Верни только корректный JSON по переданной схеме.', 'attempt' => $attempt + 1]; continue; }
                $mapping = $this->incompleteMapping([], 'Ответ не соответствует JSON-схеме после двух исправлений.');
            }
            break;
        }
        if (!is_array($mapping)) $mapping = $this->incompleteMapping([], 'Сопоставление не завершено.');
        $this->contract->validateResponse($mapping);
        return [
            'mapping' => $mapping, 'usage' => $usage, 'calls' => $calls, 'repairs' => max(0, $calls - 1),
            'failureCode' => $failure, 'promptVersion' => self::PROMPT_VERSION,
            'inputBytes' => $totalInputBytes, 'window' => $window,
        ];
    }

    private function context(array $units, array $knownGoods, array $options, array $window, ?array $repairRequest = null): array
    {
        $context = [
            'mappingVersion' => $this->contract->data()['schemaVersion'],
            'sourceManifestVersion' => SourceManifest::VERSION,
            'registry' => $this->contract->data(),
            'sources' => $units,
            'knownGoods' => array_values($knownGoods),
            'knownDocumentRecords' => array_values($options['knownDocumentRecords'] ?? []),
            'searchWindow' => array_intersect_key($window, array_flip(['index','count','sourceIds','completeBundle','wholeBundleInWindow','omittedSourceCount'])),
            'contextRequests' => array_values($options['contextRequests'] ?? []),
        ];
        if ($repairRequest !== null) $context['repairRequest'] = $repairRequest;
        return $context;
    }

    private function prompt(): string
    {
        $prompt = file_get_contents(dirname(__DIR__, 2) . '/resources/prompts/graph-mapping.v2.txt');
        if (!is_string($prompt) || $prompt === '') throw new \LogicException('Graph mapping prompt is missing.');
        return $prompt;
    }

    private function assertManifest(array $manifest): void
    {
        if (($manifest['version'] ?? null) !== SourceManifest::VERSION || !is_array($manifest['sources'] ?? null)) {
            throw new ApiError('SOURCE_MANIFEST_INVALID', 'Для сопоставления нужен полный манифест распознанных документов.', 422);
        }
    }

    private function missingTargets(array $mapping, array $window = []): array
    {
        $present = [];
        foreach ($mapping['results'] ?? [] as $result) {
            $key = $result['graphId'] . '/' . ($result['itemKey'] ?? '_');
            $needsRepair = ($window['wholeBundleInWindow'] ?? false) && $result['status'] === 'incomplete';
            if (($window['wholeBundleInWindow'] ?? false) && $result['status'] === 'not_found') {
                $needsRepair = array_diff($window['sourceIds'] ?? [], $result['searchedSourceIds'] ?? []) !== [];
            }
            if (!$needsRepair) $present[$key] = true;
        }
        $items = array_column($mapping['items'] ?? [], 'itemKey');
        $missing = [];
        foreach ($this->contract->positions() as $id => $position) {
            if ($position['scope'] === 'document') continue;
            $eligible = array_filter($position['bindings'], static fn(array $binding): bool => $binding['aiProposalAllowed']) !== []
                || (isset($position['positionValue']) && in_array($position['positionValue']['owner'], ['document', 'manual'], true));
            if (!$eligible) continue;
            $targets = $position['scope'] === 'goods' ? $items : [null];
            foreach ($targets as $itemKey) {
                $key = $id . '/' . ($itemKey ?? '_');
                if (!isset($present[$key])) $missing[] = ['graphId' => (string)$id, 'itemKey' => $itemKey];
            }
        }
        return $missing;
    }

    private function incompleteResult(array $target, string $reason): array
    {
        return ['graphId' => $target['graphId'], 'itemKey' => $target['itemKey'], 'status' => 'incomplete', 'values' => [], 'positionValue' => null, 'searchedSourceIds' => [], 'reason' => $reason];
    }

    private function incompleteMapping(array $mapping, string $reason): array
    {
        $base = ['schemaVersion' => GraphMappingContract::SCHEMA_VERSION, 'items' => [], 'results' => [], 'documentRecords' => [], 'dtsFacts' => [], 'referenceSelections' => [], 'catalogRequests' => [], 'contextRequests' => [], 'warnings' => [$reason]];
        foreach ($this->missingTargets($base) as $target) $base['results'][] = $this->incompleteResult($target, $reason);
        return $base;
    }

    private function normalizeWindowAbsence(array $mapping, array $window): array
    {
        foreach ($mapping['results'] as &$result) if ($result['status'] === 'not_found') {
            $searchedAllWindow = array_diff($window['sourceIds'] ?? [], $result['searchedSourceIds'] ?? []) === [];
            if (($window['wholeBundleInWindow'] ?? false) && ($window['completeBundle'] ?? false) && $searchedAllWindow) continue;
            $result['status'] = 'incomplete';
            $result['reason'] = 'Значение не найдено во всех источниках этого ответа; полный комплект ещё не проверен.';
        }
        unset($result);
        if (!($window['completeBundle'] ?? true)) $mapping['warnings'][] = 'Обработка остановлена на установленном пределе окон; часть источников не проверена.';
        return $mapping;
    }

    /** Flatten OCR blocks, table rows/cells and spreadsheet cells without dropping their stable IDs. */
    private function sourceUnits(array $sources): array
    {
        $units = []; $ids = []; $classifiers = new \Customs\Domain\Classifiers();
        foreach ($sources as $source) {
            $document = array_intersect_key($source, array_flip(['documentId', 'name', 'documentKind', 'classifierVersion', 'sourceRole', 'allowsNewGoods']));
            if (is_string($source['documentKind'] ?? null)) $document['documentKindName'] = $classifiers->document($source['documentKind'])['name'] ?? null;
            foreach ($source['pages'] ?? [] as $page) {
                foreach ($page['blocks'] ?? [] as $block) {
                    if (trim((string)($block['text'] ?? '')) === '' || !isset($block['sourceId'])) continue;
                    $units[] = [...$document, 'kind' => 'ocr_block', 'sourceId' => $block['sourceId'], 'page' => $page['page'], 'language' => $page['language'] ?? null, 'text' => $block['text'], 'bbox' => $block['bbox'] ?? null];
                    $ids[$block['sourceId']] = true;
                }
                foreach ($page['tables'] ?? [] as $table) foreach ($table['rows'] ?? [] as $row) {
                    $cells = [];
                    foreach ($row['cells'] ?? [] as $cell) {
                        if (!isset($cell['sourceId'])) continue;
                        $cells[] = ['column' => $cell['column'], 'kind' => $cell['kind'] ?? 'data', 'sourceId' => $cell['sourceId'], 'text' => $cell['text']];
                        $ids[$cell['sourceId']] = true;
                    }
                    if ($cells === [] || !isset($row['sourceId'])) continue;
                    $units[] = [...$document, 'kind' => 'table_row', 'sourceId' => $row['sourceId'], 'page' => $page['page'], 'language' => $table['language'] ?? $page['language'] ?? null, 'tableId' => $table['id'], 'row' => $row['row'], 'bbox' => $table['bbox'] ?? null, 'cells' => $cells];
                    $ids[$row['sourceId']] = true;
                }
            }
            foreach ($source['sheets'] ?? [] as $sheet) foreach ($sheet['cells'] ?? [] as $address => $cell) {
                if (($cell['value'] ?? null) === null || $cell['value'] === '' || !isset($cell['sourceId'])) continue;
                $units[] = [...$document, 'kind' => 'spreadsheet_cell', 'sourceId' => $cell['sourceId'], 'sheet' => $sheet['name'], 'cell' => (string)$address, 'language' => $sheet['language'] ?? null, 'text' => (string)$cell['value']];
                $ids[$cell['sourceId']] = true;
            }
        }
        $priorityTerms = [];
        foreach ($this->contract->positions() as $position) foreach ($position['priorities'] as $rank => $priority) {
            if (is_string($priority) && trim($priority) !== '') $priorityTerms[] = ['text' => self::normalizeText($priority), 'rank' => $rank];
        }
        $sequence = 0;
        foreach ($units as &$unit) { $unit['_priorityScore'] = $this->priorityScore($unit, $priorityTerms); $unit['_sequence'] = $sequence++; }
        unset($unit);
        usort($units, static fn(array $a, array $b): int => [$a['_priorityScore'], $a['_sequence']] <=> [$b['_priorityScore'], $b['_sequence']]);
        foreach ($units as &$unit) { unset($unit['_priorityScore'], $unit['_sequence']); }
        unset($unit);
        return [$units, $ids];
    }

    private function priorityScore(array $unit, array $priorities): int
    {
        $label = self::normalizeText((string)($unit['documentKindName'] ?? ''));
        if ($label === '') return 1000;
        $rankTotal = 0; $matches = 0;
        foreach ($priorities as $priority) if (str_contains($label, $priority['text'])) { $rankTotal += $priority['rank']; $matches++; }
        return $matches === 0 ? 1000 : (int)round(($rankTotal / $matches) * 100);
    }

    private static function normalizeText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    /** Validate citations and searched-source references against the server-side manifest. */
    private function verifyEvidence(array $mapping, array $sources, array $knownIds): void
    {
        $index = new SourceIndex($sources);
        $walk = function (mixed $value) use (&$walk, $index, $knownIds): void {
            if (!is_array($value)) return;
            if (isset($value['sourceId'], $value['quote'])) {
                if (!isset($knownIds[$value['sourceId']])) throw new ApiError('EVIDENCE_INVALID', 'Сопоставление сослалось на источник вне текущего комплекта.');
                $index->verify($value);
                return;
            }
            foreach ($value as $key => $child) {
                if (in_array($key, ['searchedSourceIds', 'sourceIds'], true) && is_array($child))
                    foreach ($child as $sourceId) if (!is_string($sourceId) || !isset($knownIds[$sourceId])) throw new ApiError('EVIDENCE_INVALID', 'Сопоставление указало неизвестный источник.');
                $walk($child);
            }
        };
        $walk($mapping);
    }
}
