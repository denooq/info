<?php
declare(strict_types=1);

namespace Customs\AI;

use Brick\Math\BigDecimal;
use Customs\Domain\GraphMappingContract;
use Customs\Domain\Normalizer;
use Customs\Domain\Registry;
use Customs\Http\ApiError;

/** Validates evidence, canonical formatting, and explicit units independently of AI field selection. */
final class GraphMappingVerifier
{
    private Registry $registry;
    private Normalizer $normalizer;

    public function __construct(private readonly GraphMappingContract $contract)
    {
        $this->registry = new Registry();
        $this->normalizer = new Normalizer();
    }

    public function verify(array $mapping, array $sources): void
    {
        $index = new SourceIndex($sources);
        foreach ($mapping['items'] ?? [] as $item) {
            if (is_string($item['description'] ?? null) && trim($item['description']) !== '') {
                $this->verifyLiteral($item['description'], $item['evidence'] ?? [], $index);
            }
        }

        foreach ($mapping['results'] ?? [] as $result) {
            foreach ($result['values'] ?? [] as $value) {
                $this->verifyValue($value, $this->registry->field($value['fieldId']), $index);
            }
            if (is_array($result['positionValue'] ?? null)) {
                $this->verifyLiteral($result['positionValue']['rawValue'], $result['positionValue']['evidence'], $index);
                if ($result['positionValue']['valueType'] === 'date') {
                    $this->verifyCanonicalDate($result['positionValue']['rawValue'], $result['positionValue']['normalizedValue']);
                }
            }
        }

        foreach ($mapping['documentRecords'] ?? [] as $record) foreach ($record['values'] ?? [] as $value) {
            $this->verifyValue($value, $this->registry->field($value['fieldId']), $index);
        }
        foreach ($mapping['dtsFacts'] ?? [] as $fact) {
            $value = $fact['value'];
            $this->verifyValue($value, $this->registry->field($value['fieldId']), $index);
        }
    }

    private function verifyValue(array $value, array $field, SourceIndex $index): void
    {
        $contexts = $this->verifyLiteral($value['rawValue'], $value['evidence'], $index);
        $context = implode("\n", $contexts);

        if ($field['id'] === 'document.number' && EvidenceTypeGuard::isMeasuredNumber($value['rawValue'], $context)) {
            throw new ApiError('GRAPH_VALUE_SEMANTIC', 'Цитируемое значение похоже на массу или денежную сумму, а не на номер документа.', 502);
        }

        if (in_array($field['unit'] ?? null, ['invoiceCurrency', 'currency'], true)) {
            $currency = strtoupper(trim((string)($value['currency'] ?? '')));
            if (!preg_match('/^[A-Z]{3}$/D', $currency)
                || !preg_match('/(?<![A-Z])' . preg_quote($currency, '/') . '(?![A-Z])/i', $context)) {
                throw new ApiError('GRAPH_VALUE_CURRENCY', 'Валюта суммы не подтверждена исходным контекстом.', 502);
            }
        }

        if (!in_array($field['type'], ['string', 'identifier'], true)) {
            try {
                $canonical = $this->normalizer->value($field, $value['rawValue'], $value['unit'], $context);
            } catch (ApiError $error) {
                throw new ApiError('GRAPH_VALUE_CANONICAL', 'Исходное значение не прошло проверку формата или единицы: ' . $error->getMessage(), 502);
            }
            if (!$this->sameCanonicalValue($canonical, $value['normalizedValue'])) {
                throw new ApiError('GRAPH_VALUE_CANONICAL', 'Нормализованное значение не соответствует проверяемому преобразованию исходной цитаты.', 502);
            }
        }
    }

    /** @return list<string> verified source contexts */
    private function verifyLiteral(string $rawValue, array $evidence, SourceIndex $index): array
    {
        $literal = mb_strtolower(SourceIndex::text($rawValue));
        $contexts = [];
        $found = false;
        foreach ($evidence as $citation) {
            $verified = $index->verify($citation);
            $quote = mb_strtolower(SourceIndex::text($verified['quote']));
            if ($literal !== '' && str_contains($quote, $literal)) $found = true;
            $contexts[] = $verified['context'];
        }
        if (!$found) throw new ApiError('EVIDENCE_INVALID', 'Исходное значение отсутствует в дословных цитатах указанного источника.', 502);
        return $contexts;
    }

    private function verifyCanonicalDate(string $rawValue, string $normalizedValue): void
    {
        try { $expected = $this->normalizer->date($rawValue); }
        catch (ApiError $error) { throw new ApiError('GRAPH_VALUE_CANONICAL', 'Дата из цитаты не распознана однозначно.', 502); }
        if ($expected !== $normalizedValue) throw new ApiError('GRAPH_VALUE_CANONICAL', 'Каноническая дата не соответствует исходной цитате.', 502);
    }

    private function sameCanonicalValue(mixed $expected, mixed $actual): bool
    {
        if (is_int($expected) || is_float($expected) || (is_string($expected) && is_numeric($expected))) {
            if (!(is_int($actual) || is_float($actual) || (is_string($actual) && is_numeric($actual)))) return false;
            try { return BigDecimal::of((string)$expected)->isEqualTo((string)$actual); }
            catch (\Throwable) { return false; }
        }
        return $expected === $actual;
    }
}
