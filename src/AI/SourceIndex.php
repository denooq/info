<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Http\ApiError;

final class SourceIndex
{
    private array $documents;
    private array $sourceReferences = [];
    public function __construct(array $sources)
    {
        $this->documents = array_column($sources, null, 'documentId');
        foreach ($sources as $source) {
            foreach ($source['pages'] ?? [] as $page) {
                foreach ($page['blocks'] ?? [] as $block) if (isset($block['sourceId'])) {
                    $this->sourceReferences[$block['sourceId']] = ['documentId'=>$source['documentId'],'page'=>$page['page'],'blockId'=>$block['id'],'sheet'=>null,'cell'=>null];
                }
                foreach ($page['tables'] ?? [] as $table) {
                    if (isset($table['sourceId']) && isset($table['blockId'])) $this->sourceReferences[$table['sourceId']] = ['documentId'=>$source['documentId'],'page'=>$page['page'],'blockId'=>$table['blockId'],'sheet'=>null,'cell'=>null,'tableId'=>$table['id']];
                    foreach ($table['rows'] ?? [] as $row) {
                        if (isset($row['sourceId']) && isset($table['blockId'])) $this->sourceReferences[$row['sourceId']] = ['documentId'=>$source['documentId'],'page'=>$page['page'],'blockId'=>$table['blockId'],'sheet'=>null,'cell'=>null,'tableId'=>$table['id'],'row'=>$row['row']];
                        foreach ($row['cells'] ?? [] as $cell) if (isset($cell['sourceId']) && isset($table['blockId'])) {
                            $this->sourceReferences[$cell['sourceId']] = ['documentId'=>$source['documentId'],'page'=>$page['page'],'blockId'=>$table['blockId'],'sheet'=>null,'cell'=>null,'tableId'=>$table['id'],'row'=>$row['row'],'column'=>$cell['column']];
                        }
                    }
                }
            }
            foreach ($source['sheets'] ?? [] as $sheet) foreach ($sheet['cells'] ?? [] as $address => $cell) if (isset($cell['sourceId'])) {
                $this->sourceReferences[$cell['sourceId']] = ['documentId'=>$source['documentId'],'page'=>null,'blockId'=>null,'sheet'=>$sheet['name'],'cell'=>(string)$address];
            }
        }
    }

    public static function text(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{202F}\x{200B}]+/u', ' ', $text));
    }

    /** A permit's product list does not establish the composition of a shipment. */
    public function supportsNewGoods(string $documentId): bool
    {
        $document = $this->documents[$documentId] ?? [];
        if (isset($document['allowsNewGoods'])) return (bool) $document['allowsNewGoods'];
        $text = '';
        foreach ($document['pages'] ?? [] as $page) foreach ($page['blocks'] as $block) $text .= "\n" . $block['text'];
        $header = mb_substr($text, 0, 6000);
        $permit = preg_match('/санитарно[\s\p{Pd}]*эпидемиолог|свидетельств\p{L}*\s+о\s+государственной\s+регистрации|сертификат\p{L}*\s+соответствия|certificate\s+of\s+conformity|не\s+подлеж\p{L}*\s+государственной\s+регистрации/ui', $header);
        $shipment = preg_match('/commercial\s+invoice|packing\s+list|^\s*(?:invoice|инвойс|сч[её]т[\s-]*фактура|упаковочный\s+лист)\b/uim', $header);
        return !$permit || (bool) $shipment;
    }

    public function verify(array $citation): array
    {
        $stableSourceId = $citation['sourceId'] ?? null;
        if (is_string($stableSourceId)) {
            $reference = $this->sourceReferences[$stableSourceId] ?? null;
            if ($reference === null || (isset($citation['documentId']) && $citation['documentId'] !== $reference['documentId'])) throw new ApiError('EVIDENCE_INVALID', 'Предложение ссылается на неизвестный документ или фрагмент.');
            $citation = [...$reference, 'sourceId' => $stableSourceId, 'quote' => $citation['quote'] ?? ''];
        }
        $document = $this->documents[$citation['documentId']] ?? null;
        if ($document === null) throw new ApiError('EVIDENCE_INVALID', 'Предложение ссылается на неизвестный документ.');
        $text = null; $position = [];
        if (($citation['tableId'] ?? null) !== null && isset($citation['row'])) {
            foreach ($document['pages'] ?? [] as $page) {
                if ($page['page'] !== $citation['page']) continue;
                foreach ($page['tables'] ?? [] as $table) {
                    if ($table['id'] !== $citation['tableId']) continue;
                    foreach ($table['rows'] ?? [] as $row) {
                        if ($row['row'] !== $citation['row']) continue;
                        if (isset($citation['column'])) {
                            foreach ($row['cells'] as $cell) if ($cell['column'] === $citation['column']) { $text = $cell['text']; break 2; }
                        } else $text = implode(' | ', array_column($row['cells'], 'text'));
                        break 2;
                    }
                }
            }
            $position = ['page'=>$citation['page'],'tableId'=>$citation['tableId'],'row'=>$citation['row'],'column'=>$citation['column']??null];
        } elseif ($citation['page'] !== null && $citation['blockId'] !== null && $citation['sheet'] === null && $citation['cell'] === null) {
            foreach ($document['pages'] ?? [] as $page) {
                if ($page['page'] !== $citation['page']) continue;
                foreach ($page['blocks'] as $block) if ($block['id'] === $citation['blockId']) {
                    $text = $block['text'];
                    $position = ['page' => $page['page'], 'blockId' => $block['id'], 'bbox' => $block['bbox'] ?? null];
                    break 2;
                }
            }
        } elseif ($citation['sheet'] !== null && $citation['cell'] !== null && $citation['page'] === null && $citation['blockId'] === null) {
            foreach ($document['sheets'] ?? [] as $sheet) if ($sheet['name'] === $citation['sheet'] && isset($sheet['cells'][$citation['cell']])) {
                $value = $sheet['cells'][$citation['cell']]['value'];
                if ($value !== null) $text = (string) $value;
                $position = ['sheet' => $sheet['name'], 'cell' => $citation['cell']];
                break;
            }
        }
        if ($text === null || !str_contains(self::text($text), self::text($citation['quote']))) throw new ApiError('EVIDENCE_INVALID', 'Цитата не подтверждается указанным источником.');
        return ['id' => hash('sha256', json_encode([$citation['documentId'],$position,$citation['quote']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'sourceId'=>$stableSourceId, 'documentId' => $citation['documentId'], 'documentName' => $document['name'],...array_intersect_key($document,array_flip(['documentKind','classifierVersion','sourceRole'])), ...$position, 'quote' => $citation['quote'], 'context' => $text];
    }
}
