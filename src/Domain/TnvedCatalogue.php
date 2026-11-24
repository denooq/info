<?php
declare(strict_types=1);

namespace Customs\Domain;

/** Local retrieval only: no invented codes, rates or legal classification rules. */
final class TnvedCatalogue
{
    private static ?array $data = null;
    private static ?array $index = null;
    private static array $searchCache = [];
    private static ?array $codes = null;
    public const MATCH_VERSION = 'tnved-name-v1';

    public function __construct()
    {
        if (self::$data !== null) return;
        self::$data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/tnved/catalog.v1.json'), true, 64, JSON_THROW_ON_ERROR);
        self::$index = [];
        foreach (self::$data['items'] as $index => $item) self::$index[$item[0]] = $index;
    }

    public function version(): string { return self::$data['metadata']['source_sha256'] . ':' . self::MATCH_VERSION; }
    public function metadata(): array { return self::$data['metadata']; }

    public static function containsCode(string $code): bool
    {
        if (!preg_match('/^[0-9]{10}$/D', $code)) return false;
        if (self::$codes === null) {
            $index = json_decode(file_get_contents(dirname(__DIR__, 2) . '/resources/tnved/codes.v1.json'), true, 16, JSON_THROW_ON_ERROR);
            self::$codes = array_fill_keys($index['codes'], true);
        }
        return isset(self::$codes[$code]);
    }

    public function entry(string $code): ?array
    {
        if (!preg_match('/^[0-9]{10}$/D', $code) || !isset(self::$index[$code])) return null;
        $row = self::$data['items'][self::$index[$code]];
        return ['code' => $row[0], 'name' => $row[1], 'fullName' => $row[2], 'sourceUrl' => self::$data['sources'][$row[3]], 'page' => $row[4]];
    }

    /** Search only terminal ten-digit entries by code prefix or literal catalog text. */
    public function searchCatalog(string $query, int $offset = 0, int $limit = 20): array
    {
        $query = trim($query);
        $normalized = self::normalize($query);
        $codeQuery = preg_match('/^[0-9]+$/D', $normalized) === 1;
        $offset = max(0, $offset);
        $limit = max(1, min(50, $limit));
        $matches = [];
        if ($normalized !== '') foreach (self::$data['items'] as $row) {
            if (strlen((string)$row[0]) !== 10) continue;
            if ($codeQuery) {
                if (!str_starts_with($row[0], $normalized)) continue;
            } elseif (!str_contains(self::normalize($row[1]), $normalized)
                && !str_contains(self::normalize($row[2]), $normalized)) continue;
            $matches[] = $this->entry($row[0]);
        }
        return [
            'version' => $this->version(), 'query' => $query,
            'items' => array_slice($matches, $offset, $limit), 'total' => count($matches),
            'offset' => $offset, 'nextOffset' => $offset + $limit < count($matches) ? $offset + $limit : null,
        ];
    }

    public static function normalize(string $value): string
    {
        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', str_replace('ё', 'е', mb_strtolower($value))));
    }

    private static function tokens(string $value): array
    {
        $words = explode(' ', self::normalize($value));
        $russian = (bool) preg_match('/[а-я]/ui', $value);
        $stop = ['и','в','на','из','для','или','с','по','не','до','от','при','прочие','прочий','прочая','товар','товары','the','of','and','for','cas'];
        $tokens = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || ctype_digit($word) || in_array($word, $stop, true)) continue;
            if ($russian && !preg_match('/[а-я]/ui', $word)) continue;
            if (in_array($word, ['клей','клеи','клея','клеев','клею','клеями','клеях'], true)) { $tokens['клей'] = true; continue; }
            if (preg_match('/^адгезив(?:ы|ов|а|у|ами|ах)?$/u', $word)) { $tokens['клей'] = true; continue; }
            // Conservative inflection folding; never match "клей" inside "клейковина".
            $stem = preg_replace('/(?:иями|ями|ами|ого|ому|ыми|ими|ов|ев|ей|ий|ый|ой|ая|яя|ое|ее|ые|ие|ам|ям|ах|ях|ом|ем|а|я|ы|и|у|ю)$/u', '', $word);
            if (mb_strlen($stem) >= 3) $tokens[$stem] = true;
            else $tokens[$word] = true;
        }
        return array_keys($tokens);
    }

    /** exact is deliberately stricter than a top search score. */
    public function search(string $description, ?string $queryRu = null, int $limit = 12): array
    {
        $limit = max(1, min(12, $limit));
        $key = hash('sha256', json_encode([$description, $queryRu, $limit], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if (isset(self::$searchCache[$key])) return self::$searchCache[$key];
        if (count(self::$searchCache) >= 128) array_shift(self::$searchCache);
        return self::$searchCache[$key] = $this->find($description, $queryRu, $limit);
    }

    private function find(string $description, ?string $queryRu, int $limit): array
    {
        $queries = array_values(array_unique(array_filter([self::normalize($description), $queryRu === null ? '' : self::normalize($queryRu)])));
        $tokens = self::tokens($queryRu ?? $description);
        if ($tokens === []) return ['exact' => null, 'matches' => [], 'total' => 0];
        $exact = []; $ranked = [];
        foreach (self::$data['items'] as $row) {
            $name = self::normalize($row[1]);
            $qualifiedPath = in_array(self::normalize($row[2]), $queries, true);
            $specificName = in_array($name, $queries, true) && !preg_match('/^(?:проч|для\b|с\b|из\b|не\b|содержащ|менее\b|более\b|от\b|до\b|мощност|длино|ширино|толщино|массой|нетто)/u', $name);
            if ($qualifiedPath || $specificName) $exact[] = $row[0];
        }
        if (count($exact) === 1) return ['exact' => $exact[0], 'matches' => [$this->entry($exact[0])], 'total' => 1];
        foreach (self::$data['items'] as $row) {
            $segments = explode(' → ', $row[2]);
            $text = count($segments) > 1 ? implode(' → ', array_slice($segments, 1)) : $row[2];
            $positive = static fn(string $text): string => preg_replace('/\([^)]*кроме[^)]*\)|для\s+(?:производства|изготовления)[^→;]*/ui', '', $text);
            $nameTokens = self::tokens($positive($row[1]));
            $pathTokens = self::tokens($positive($text));
            if (!in_array($tokens[0], $pathTokens, true)) continue;
            $matched = count(array_intersect($tokens, $pathTokens));
            if ($matched === 0) continue;
            $heading = $segments[1] ?? $row[1];
            $heading = preg_replace('/\([^)]*\)/u', '', $heading);
            $heading = preg_split('/\b(?:для|из|кроме|включая|полученные|содержащие)\b/ui', $heading)[0];
            $headingTokens = self::tokens(implode(' ', array_slice(explode(' ', $heading), 0, 18)));
            $headingScore = 0;
            foreach ($tokens as $token) { $position = array_search($token, $headingTokens, true); if ($position !== false) $headingScore += 2 / (1 + $position / 3); }
            $score = $matched / count($tokens) + count(array_intersect($tokens, $nameTokens)) / count($tokens) + $headingScore / count($tokens);
            $ranked[] = ['code' => $row[0], 'score' => $score];
        }
        usort($ranked, static fn(array $a, array $b): int => ($b['score'] <=> $a['score']) ?: strcmp($a['code'], $b['code']));
        $best = $ranked[0]['score'] ?? 0;
        $ranked = array_values(array_filter($ranked, static fn(array $row): bool => $row['score'] >= max(0.5, $best * 0.45)));
        return ['exact' => null, 'matches' => array_map(fn(array $row): array => $this->entry($row['code']), array_slice($ranked, 0, $limit)), 'total' => count($ranked)];
    }
}
