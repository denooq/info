<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Config;
use Customs\Documents\XlsxReader;
use Customs\Http\ApiError;

final class Ocr
{
    public const CHUNK_PAGES = 8;
    public function __construct(private readonly Config $config, private readonly MistralClient $client) {}

    public function run(array $job, array $state, string $directory): array
    {
        $result = null; $failure = null;
        $this->runBatch([$job], $state, $directory, static function ($job, $payload, $error) use (&$result, &$failure): void { $result = $payload; $failure = $error; });
        if ($failure !== null) throw $failure;
        return $result;
    }

    public function runBatch(array $jobs, array $state, string $directory, callable $complete): void
    {
        $remote = []; $prepared = []; $pending = [];
        foreach ($jobs as $job) {
            $payload = null; $error = null;
            try {
                $item = $this->prepare($job, $state, $directory);
                if (isset($item['saved'])) $payload = $item['saved'];
                elseif ($item['document']['extension'] === 'xlsx') {
                    $book = (new XlsxReader($this->config->get('max_unpacked_bytes')))->read($item['path']);
                    $payload = $this->save($item, ['kind' => 'spreadsheet', 'sheets' => $book['sheets'], 'pages' => [], 'usage' => [], 'model' => 'programmatic-xlsx-v1']);
                } else {
                    $id = $job['id'];
                    $prepared[$id] = $item; $pending[$id] = $job;
                    $remote[$id] = ['path' => $item['path'], 'mime' => $item['document']['mime'], 'pages' => $item['pages']];
                    continue;
                }
            } catch (\Throwable $failure) { $error = $failure; }
            $complete($job, $payload, $error);
        }
        if ($remote === []) return;
        try {
            $this->client->ocrBatch($remote, $directory . '/ocr', function (string $id, ?array $response, ?\Throwable $error) use (&$pending, $prepared, $complete): void {
                $job = $pending[$id]; unset($pending[$id]);
                $payload = null;
                if ($error === null) {
                    try {
                        $normalized = $this->normalize($response, $prepared[$id]['pages']);
                        $normalized['raw'] = $response;
                        $payload = $this->save($prepared[$id], $normalized);
                    } catch (\Throwable $failure) { $error = $failure; }
                }
                $complete($job, $payload, $error);
            });
        } catch (\Throwable $error) {
            foreach ($pending as $job) $complete($job, null, $error);
        }
    }

    private function prepare(array $job, array $state, string $directory): array
    {
        $document = $state['documents'][$job['documentId']] ?? throw new ApiError('DOCUMENT_NOT_FOUND', 'Документ удалён.', 404);
        $path = $directory . '/documents/' . $document['storageName'];
        $start = $job['chunkStart'] ?? 0;
        $pages = range($start, min($document['pages'] - 1, $start + self::CHUNK_PAGES - 1));
        $fingerprint = hash('sha256', json_encode([$document['hash'],$this->config->get('ocr_model'),$pages]));
        $cache = $directory . '/ocr/' . $document['id'] . '-' . substr($fingerprint, 0, 16) . '.json';
        if (is_file($cache)) {
            $raw = @file_get_contents($cache);
            if ($raw === false) throw new ApiError('SESSION_EXPIRED', 'Сессия или сохранённый результат недоступны.', 410);
            $saved = json_decode($raw, true, 256, JSON_THROW_ON_ERROR);
            if (($saved['fingerprint'] ?? null) === $fingerprint) return ['saved' => $saved];
        }
        return compact('document', 'path', 'pages', 'fingerprint', 'cache');
    }

    private function save(array $item, array $normalized): array
    {
        ['document' => $document, 'pages' => $pages, 'fingerprint' => $fingerprint, 'cache' => $cache] = $item;
        $saved = ['fingerprint' => $fingerprint, 'documentId' => $document['id'], 'sourceHash' => $document['hash'], 'ocrModel' => $this->config->get('ocr_model'), 'pageIndexes' => $document['extension'] === 'xlsx' ? range(0, $document['pages'] - 1) : $pages, 'result' => $normalized];
        $this->writeCache($cache, $saved);
        return $saved;
    }

    public function apply(array $state, array $payload, array $job): array
    {
        $id = $job['documentId'];
        if (!isset($state['documents'][$id]) || $state['documents'][$id]['hash'] !== $payload['sourceHash']) return $state;
        $doc = $state['documents'][$id];
        $doc['recognizedPages'] = array_values(array_unique(array_merge($doc['recognizedPages'] ?? [], $payload['pageIndexes'])));
        sort($doc['recognizedPages']);
        $doc['status'] = count($doc['recognizedPages']) === $doc['pages'] ? 'recognized' : 'recognizing';
        $doc['error'] = null;
        if ($doc['status'] !== 'recognized') foreach ($state['jobs'] as $other) {
            if ($other['kind'] === 'ocr' && $other['documentId'] === $id && $other['status'] === 'failed') { $doc['status'] = 'error'; $doc['error'] = $other['error']; }
        }
        $state['documents'][$id] = $doc;
        return $state;
    }

    public function normalize(array $response, array $expectedIndexes): array
    {
        if (!is_array($response['pages'] ?? null)) throw new ApiError('OCR_INCOMPLETE', 'OCR не вернул страницы документа.', 502);
        $pages = []; $seen = [];
        foreach ($response['pages'] as $page) {
            $index = $page['index'] ?? null;
            if (!is_int($index) || !in_array($index, $expectedIndexes, true) || in_array($index, $seen, true)) throw new ApiError('OCR_PAGE_INDEX', 'OCR вернул несогласованные номера страниц.', 502);
            $seen[] = $index;
            $blocks = []; $tables = [];
            foreach (preg_split('/\n\s*\n/u', (string) ($page['markdown'] ?? '')) as $paragraph) {
                $paragraph = trim($paragraph);
                if ($paragraph !== '') $blocks[] = ['id' => 'p' . ($index + 1) . '-b' . count($blocks), 'text' => $paragraph, 'bbox' => null];
            }
            foreach ($page['tables'] ?? [] as $tableIndex => $table) {
                $content = (string) ($table['content'] ?? '');
                $plain = html_entity_decode(strip_tags(preg_replace('#</(?:td|th|tr)>#i', ' $0', $content)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tableId = (string)($table['id'] ?? 'table-' . ($tableIndex + 1));
                $rows = $this->tableRows($content);
                if (trim($plain) !== '') {
                    $blockId = 'p' . ($index + 1) . '-t' . count($blocks);
                    $blocks[] = ['id' => $blockId, 'text' => trim($plain), 'bbox' => $table['bbox'] ?? null, 'tableId' => $tableId];
                    $tables[] = ['id' => $tableId, 'blockId' => $blockId, 'text' => trim($plain), 'bbox' => $table['bbox'] ?? null, 'language' => $table['language'] ?? $page['language'] ?? null, 'rows' => $rows];
                }
            }
            foreach ($page['blocks'] ?? [] as $nativeIndex => $native) {
                $text = $native['content'] ?? $native['text'] ?? null;
                if (is_string($text) && trim($text) !== '' && !in_array(trim($text), array_column($blocks, 'text'), true)) {
                    $blocks[] = ['id' => 'p' . ($index + 1) . '-n' . $nativeIndex, 'text' => trim($text), 'bbox' => $native['bbox'] ?? $native['bounding_box'] ?? null, 'confidence' => $native['confidence_scores'] ?? null];
                }
            }
            $pages[] = ['page' => $index + 1, 'language' => $page['language'] ?? $page['languages'] ?? null, 'blocks' => $blocks, 'tables' => $tables, 'nativeBlocks' => $page['blocks'] ?? [], 'dimensions' => $page['dimensions'] ?? null, 'confidence' => $page['confidence_scores'] ?? null, 'needsReview' => $blocks === []];
        }
        sort($seen); $sortedExpected = $expectedIndexes; sort($sortedExpected);
        if ($seen !== $sortedExpected) throw new ApiError('OCR_INCOMPLETE', 'OCR пропустил страницы. Успешные части сохранены.', 502);
        usort($pages, fn(array $a, array $b): int => $a['page'] <=> $b['page']);
        return ['kind' => 'ocr','pages' => $pages,'usage' => $response['usage_info'] ?? [],'model' => $response['model'] ?? $this->config->get('ocr_model')];
    }

    private function tableRows(string $html): array
    {
        if (trim($html) === '' || !class_exists(\DOMDocument::class)) return [];
        $document = new \DOMDocument('1.0', 'UTF-8');
        if (!@$document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT)) return [];
        $xpath = new \DOMXPath($document);
        $rows = [];
        foreach ($xpath->query('//tr') ?: [] as $row) {
            $cells = [];
            foreach ($xpath->query('./th|./td', $row) ?: [] as $column => $cell) {
                $text = trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $cell->textContent) ?? '');
                $cells[] = ['column' => $column + 1, 'kind' => strtolower($cell->nodeName) === 'th' ? 'header' : 'data', 'text' => $text];
            }
            if ($cells !== []) $rows[] = ['row' => count($rows) + 1, 'cells' => $cells];
        }
        return $rows;
    }

    private function writeCache(string $path, array $data): void
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $used = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->config->get('runtime_dir'), \FilesystemIterator::SKIP_DOTS)) as $file) if ($file->isFile() && !$file->isLink()) $used += $file->getSize();
        if ($used + strlen($json) > $this->config->get('max_runtime_bytes')) throw new ApiError('STORAGE_LIMIT', 'Недостаточно места для результата распознавания.', 503);
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $path)) {
            @unlink($temporary); throw new ApiError('STORAGE_ERROR', 'Не удалось сохранить распознавание.', 503);
        }
        @chmod($path, 0600);
    }
}
