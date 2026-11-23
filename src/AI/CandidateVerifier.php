<?php
declare(strict_types=1);

namespace Customs\AI;

use Customs\Domain\Normalizer;
use Customs\Domain\Registry;
use Customs\Http\ApiError;

final class CandidateVerifier
{
    public function __construct(private readonly Registry $registry, private readonly SourceIndex $sources) {}

    public function verify(array $candidate): array
    {
        $field = $this->registry->field($candidate['fieldId']);
        if (!isset($this->registry->extractable()[$field['id']])) throw new ApiError('FIELD_NOT_EXTRACTABLE', 'Это поле не заполняется AI в текущем профиле граф.');
        $evidence = $this->sources->verify($candidate['source']);
        $raw = SourceIndex::text($candidate['rawValue']);
        $quote = SourceIndex::text($evidence['quote']);
        $present = str_contains(mb_strtolower($quote), mb_strtolower($raw));
        if (!$present && in_array($field['type'], ['decimal','integer'], true)) {
            $present = str_contains(preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $quote), preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $raw));
        }
        if (!$present) throw new ApiError('VALUE_UNSUPPORTED', 'Исходное значение отсутствует в подтверждающей цитате.');
        if (($field['unit'] ?? null) === 'kg') {
            $sourceUnit = mb_strtolower($candidate['unit'] ?? '');
            $quoteKg = preg_match('/\b(?:kgs?|кг)\b|千克/ui', $quote);
            $quoteTon = preg_match('/\b(?:t|tons?|tonnes?|т|тонн[аы]?)\b|吨/ui', $quote);
            if (($quoteKg && !$quoteTon && in_array($sourceUnit, ['t','ton','tons','tonne','tonnes','т','тонна','тонны','тонн','吨'], true)) || ($quoteTon && !$quoteKg && in_array($sourceUnit, ['kg','kgs','кг','千克'], true))) throw new ApiError('UNIT_UNCONFIRMED', 'Предложенная единица противоречит цитате.');
        }
        $normalizer = new Normalizer();
        $value = $normalizer->value($field, $candidate['rawValue'], $candidate['unit'], $evidence['context']);
        $issues = $normalizer->issues($field, $value);
        if($field['id']==='declaration.sourcePackageCount'&&(!is_int($value)||$value<1||!preg_match('/packages?|pallets?|places?|количеств\p{L}*\s+мест|мест\b|паллет/ui',$evidence['context'])))$issues[]='Количество мест не подтверждено явным заголовком документа.';
        if (in_array($field['id'], ['goods.grossMass','goods.netMass'], true)) {
            $label=$field['id']==='goods.grossMass'?'/gross|брутто|毛重/ui':'/net(?:to)?(?:\s|weight|mass|\b)|нетто|净重/ui';
            if(!preg_match($label,$evidence['context']))$issues[]='Источник не содержит явного признака брутто/нетто рядом с данными; подтвердите значение вручную.';
        }
        unset($evidence['context']);
        return ['fieldId' => $field['id'], 'scope' => $field['scope'], 'entityKey' => $candidate['entityKey'], 'value' => $value, 'rawValue' => $candidate['rawValue'], 'sourceUnit' => $candidate['unit'], 'unit' => $field['unit'] ?? null, 'evidence' => $evidence, 'valid' => $issues === [], 'issues' => $issues];
    }
}
