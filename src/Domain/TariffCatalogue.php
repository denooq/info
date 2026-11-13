<?php
declare(strict_types=1);
namespace Customs\Domain;

use Customs\Http\ApiError;

final class TariffCatalogue
{
    private static ?array $context = null;
    private static array $groups = [];

    private function context(): array
    {
        return self::$context ??= json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/tnved/tariff-context.v1.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function get(string $code): ?array
    {
        if (!TnvedCatalogue::containsCode($code)) return null;
        $group = substr($code, 0, 2);
        if (!isset(self::$groups[$group])) {
            if (count(self::$groups) >= 4) unset(self::$groups[array_key_first(self::$groups)]);
            $file = dirname(__DIR__, 2) . '/resources/tnved/tariffs/' . $group . '.json';
            self::$groups[$group] = json_decode(file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
        }
        $data = self::$groups[$group]; $context = $this->context();
        if ($data['source_sha256'] !== $context['source_sha256']) throw new ApiError('TARIFF_VERSION', 'Файлы справочника тарифов имеют разные версии.', 503);
        $item = $data['items'][$code] ?? null;
        if ($item === null) return null;
        $notes = [];
        foreach ($context['notes'] as $note) if (in_array($note['id'], $item['duty']['note_ids'], true)) $notes[] = $note;
        $ids = array_filter([$item['sourceDocumentId'], ...array_values($item['classificationContext']), ...array_column($notes, 'source_document_id')], 'is_string');
        $documents = array_intersect_key($context['documents'], array_fill_keys($ids, true));
        return [...$item, 'code' => $code, 'version' => $data['source_sha256'], 'notes' => $notes, 'coverage' => $context['coverage'], 'documents' => $documents];
    }

    public function units(): array { return $this->context()['units']; }
    public function version(): string { return $this->context()['source_sha256']; }
}
