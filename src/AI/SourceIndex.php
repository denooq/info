<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Http\ApiError;

final class SourceIndex
{
    private array $documents;
    public function __construct(array $sources)
    {
        $this->documents = array_column($sources, null, 'documentId');
    }

    public static function text(string $text): string
    {
        return trim(preg_replace('/[\s\x{00A0}\x{202F}\x{200B}]+/u', ' ', $text));
    }

    public function verify(array $citation): array
    {
        $document = $this->documents[$citation['documentId']] ?? null;
        if ($document === null) throw new ApiError('EVIDENCE_INVALID', 'Предложение ссылается на неизвестный документ.');
        $text = null; $position = [];
        if ($citation['page'] !== null && $citation['blockId'] !== null && $citation['sheet'] === null && $citation['cell'] === null) {
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
        return ['id' => hash('sha256', json_encode([$citation['documentId'],$position,$citation['quote']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'documentId' => $citation['documentId'], 'documentName' => $document['name'], ...$position, 'quote' => $citation['quote'], 'context' => $text];
    }
}
