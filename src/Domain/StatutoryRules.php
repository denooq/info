<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;

final class StatutoryRules
{
    public static function owns(string $fieldId): bool
    {
        $fields = ['declaration.feeBase','declaration.feeAdditional','goods.vatRate','goods.rateBasis','goods.ratesDate','goods.tariffFromCatalogue'];
        foreach (TariffEngine::OPERANDS as $key) if ($key !== 'dutyQuantity') $fields[] = 'goods.'.$key;
        return in_array($fieldId, $fields, true);
    }

    public function apply(array $state): array
    {
        $state = (new TariffDefaults())->apply($state);
        $snapshot = $this->resolve($state['draft']);
        $state['draft']['declaration']['feeBase'] = $snapshot['fee'];
        $state['draft']['declaration']['feeAdditional'] = $snapshot['fee'] === null ? null : '0';
        foreach (['feeBase','feeAdditional'] as $key) $state['draft']['fieldStates']['declaration/_/'.$key] = DeclarationDate::meta('rule');
        foreach ($state['draft']['goods'] as &$goods) {
            $goods['vatRate'] = $snapshot['goods'][$goods['id']]['vatRate'];
            $state['draft']['fieldStates']['goods/'.$goods['id'].'/vatRate'] = DeclarationDate::meta('rule');
        }
        unset($goods);
        $state['draft']['statutoryRules'] = $snapshot;
        return $state;
    }
    public static function resource(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/registry/statutory-rules.kz.v1.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    public function resolve(array $draft): array
    {
        $rule = self::resource(); $date = $draft['declaration']['calculationDate'] ?? null;
        $validDate = is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) && checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4));
        $available = $validDate && $date >= $rule['effectiveFrom'] && $date <= $rule['effectiveTo'];
        $snapshot = [...$rule, 'date'=>$date, 'status'=>$available?'resolved':'unavailable', 'fee'=>$available?(string)BigDecimal::of($rule['mrp'])->multipliedBy($rule['feeMultiplier'])->toScale(2):null, 'goods'=>[], 'errors'=>[]];
        if (!$available) $snapshot['errors'][] = ['address'=>'declaration/_/signatureDate','graphId'=>'54','code'=>'STATUTORY_DATE','message'=>'Для этой даты нет нормативных правил и подтверждённого МРП. Покрытие: 2026 год.'];
        foreach ($draft['goods'] as $goods) {
            $prefix = 'goods/'.$goods['id'].'/'; $status = $available ? 'resolved' : 'unavailable'; $message = null;
            $medical = str_starts_with((string)($goods['commodityCode'] ?? ''), '30') || preg_match('/медицин|лекарствен|фармацев|протез|\bmedical\b|\bmedicin|\bpharmaceu/ui', (string)($goods['description'] ?? ''));
            if ($available && ($medical || ($goods['hasPreference'] ?? null) === true || ($goods['exciseApplicable'] ?? null) === true || ($goods['valuationMethod'] ?? '1') !== '1')) {
                $status = 'unsupported'; $message = 'Льготная/медицинская ставка, акциз или иной специальный случай не поддержаны нормативным профилем MVP.';
            } elseif ($available) {
                $plain = ($goods['hasPreference'] ?? null) === false;
                foreach (['preferenceFee','preferenceDuty','preferenceExcise','preferenceVat'] as $key) {
                    if (!in_array($goods[$key] ?? null, ['ОО','OO','00'], true)) $plain = false;
                }
                if (!$plain || ($goods['tariffConfirmed'] ?? null) !== true) {
                    $status = 'needs_confirmation'; $message = 'Подтвердите применимость стандартного импорта без льгот, акциза, исключений ВТО и защитных пошлин; проверьте базовую ставку ТН ВЭД и сноски.';
                }
            }
            if ($available && !isset($goods['_tariff'])) { $status='unsupported'; $message='Код товара отсутствует в подключённом тарифном справочнике. Расчёт по ручной ставке не поддерживается.'; }
            $snapshot['goods'][$goods['id']] = ['status'=>$status,'vatRate'=>$status==='resolved'?$rule['vatRate']:null,'message'=>$message,'ruleId'=>$rule['version'].'/standard-vat','sourceUrl'=>$rule['sources']['vat'],'commodityCode'=>$goods['commodityCode']??null,'tariffVersion'=>$goods['_tariff']['version']??null,'contextHash'=>$goods['_tariff']['contextHash']??null];
            if ($message !== null) $snapshot['errors'][] = ['address'=>$prefix.'tariffConfirmed','graphId'=>'36','code'=>$status==='unsupported'?'STATUTORY_UNSUPPORTED':'STATUTORY_CONTEXT','message'=>$message];
            if ($status !== 'resolved' && $snapshot['status'] === 'resolved') $snapshot['status'] = $status;
        }
        $snapshot['hash'] = hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
        return $snapshot;
    }
}
