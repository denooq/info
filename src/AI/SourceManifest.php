<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Http\ApiError;

/** Stable, session-scoped references to every recognized attachment location. */
final class SourceManifest
{
    public const VERSION = 'source-manifest-v1';

    public function build(array $sources, string $sessionScope): array
    {
        if ($sessionScope === '') throw new \InvalidArgumentException('A session scope is required.');
        $manifest = [];
        foreach ($sources as $source) {
            $documentId = (string)($source['documentId'] ?? '');
            if ($documentId === '') throw new ApiError('SOURCE_MANIFEST_INVALID', 'У распознанного файла отсутствует идентификатор.', 500);
            $contentHash = (string)($source['contentHash'] ?? $source['sourceHash'] ?? $source['hash'] ?? '');
            $copy = array_intersect_key($source, array_flip(['documentId','name','documentKind','classifierVersion','sourceRole','allowsNewGoods']));
            $copy['contentHash'] = $contentHash;
            $copy['pages'] = [];
            foreach ($source['pages'] ?? [] as $page) {
                $pageNumber = (int)$page['page'];
                $pageCopy = array_intersect_key($page, array_flip(['page','language','dimensions','confidence','needsReview','nativeBlocks']));
                $pageCopy['page'] = $pageNumber;
                $pageCopy['blocks'] = [];
                foreach ($page['blocks'] ?? [] as $block) {
                    $blockId = (string)$block['id'];
                    $blockCopy = $block;
                    $blockCopy['sourceId'] = self::sourceId($sessionScope, $documentId, $contentHash, ['page', $pageNumber, 'block', $blockId]);
                    $pageCopy['blocks'][] = $blockCopy;
                }
                $pageCopy['tables'] = [];
                foreach ($page['tables'] ?? [] as $tableIndex => $table) {
                    $tableId = (string)($table['id'] ?? 'table-' . ($tableIndex + 1));
                    $tableCopy = array_intersect_key($table, array_flip(['id','blockId','text','bbox','language']));
                    $tableCopy['id'] = $tableId;
                    $tableCopy['sourceId'] = self::sourceId($sessionScope, $documentId, $contentHash, ['page', $pageNumber, 'table', $tableId]);
                    $tableCopy['rows'] = [];
                    foreach ($table['rows'] ?? [] as $rowIndex => $row) {
                        $rowNumber = (int)($row['row'] ?? $rowIndex + 1);
                        $rowCopy = ['row' => $rowNumber, 'sourceId' => self::sourceId($sessionScope, $documentId, $contentHash, ['page', $pageNumber, 'table', $tableId, 'row', $rowNumber]), 'cells' => []];
                        foreach ($row['cells'] ?? [] as $columnIndex => $cell) {
                            $columnNumber = (int)($cell['column'] ?? $columnIndex + 1);
                            $rowCopy['cells'][] = [
                                'column' => $columnNumber,
                                'kind' => $cell['kind'] ?? 'data',
                                'text' => (string)($cell['text'] ?? ''),
                                'sourceId' => self::sourceId($sessionScope, $documentId, $contentHash, ['page', $pageNumber, 'table', $tableId, 'row', $rowNumber, 'column', $columnNumber]),
                            ];
                        }
                        $tableCopy['rows'][] = $rowCopy;
                    }
                    $pageCopy['tables'][] = $tableCopy;
                }
                $copy['pages'][] = $pageCopy;
            }
            $copy['sheets'] = [];
            foreach ($source['sheets'] ?? [] as $sheet) {
                $sheetCopy = ['name' => $sheet['name'], 'language' => $sheet['language'] ?? null, 'cells' => []];
                foreach ($sheet['cells'] ?? [] as $address => $cell) {
                    $cellCopy = $cell;
                    $cellCopy['sourceId'] = self::sourceId($sessionScope, $documentId, $contentHash, ['sheet', $sheet['name'], 'cell', (string)$address]);
                    $cellCopy['address'] = (string)$address;
                    $sheetCopy['cells'][$address] = $cellCopy;
                }
                $copy['sheets'][] = $sheetCopy;
            }
            if ($copy['pages'] === [] && $copy['sheets'] === []) throw new ApiError('OCR_REQUIRED', 'Не все документы имеют сохранённый OCR.', 409);
            $manifest[] = $copy;
        }
        return ['version' => self::VERSION, 'sources' => $manifest];
    }

    public static function sourceId(string $sessionScope, string $documentId, string $contentHash, array $location): string
    {
        return 'src_' . substr(hash('sha256', $sessionScope . "\0" . $documentId . "\0" . $contentHash . "\0" . json_encode($location, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 0, 32);
    }
}
