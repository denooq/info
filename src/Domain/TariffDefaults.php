<?php
declare(strict_types=1);
namespace Customs\Domain;

final class TariffDefaults
{
    public const VERSION = 'tariff-units-v2';
    public const UNIT_FIELDS = ['additionalUnitRequired','additionalUnit','secondaryAdditionalUnitRequired','secondaryAdditionalUnit'];

    public function apply(array $state): array
    {
        $catalogue = new TariffCatalogue();
        foreach ($state['draft']['goods'] as &$goods) {
            $prefix = 'goods/' . $goods['id'] . '/';
            $previous = $goods['_tariff'] ?? null;
            $entry = $catalogue->get((string) ($goods['commodityCode'] ?? ''));
            if ($entry === null) {
                if ($previous !== null) {
                    foreach ([...TariffEngine::OPERANDS, ...self::UNIT_FIELDS, 'rateBasis','ratesDate','tariffFromCatalogue','tariffConfirmed'] as $key) {
                        if (in_array($state['draft']['fieldStates'][$prefix.$key]['status'] ?? '', ['reference','linked'], true)) $this->set($state, $goods, $key, null, []);
                    }
                    unset($goods['_tariff']);
                }
                continue;
            }
            $evidenceId = 'tariff-' . $entry['code'] . '-' . substr($entry['version'], 0, 12);
            $state['evidence'][$evidenceId] = ['kind'=>'tnved', 'documentId'=>'tariff-catalogue', 'documentName'=>'ЕТТ ЕАЭС · '.$entry['code'], 'page'=>$entry['sourcePage'], 'url'=>$entry['sourceUrl'], 'quote'=>$entry['duty']['raw_text'].'; дополнительные единицы: '.$entry['units']['additional_raw'], 'sourceVersion'=>$entry['version']];
            $evidence = [$evidenceId];
            $changedCode = $previous !== null && $previous['code'] !== $entry['code'];
            $modeStatus = $state['draft']['fieldStates'][$prefix.'tariffFromCatalogue']['status'] ?? '';
            if (!in_array($modeStatus, ['manual','selected'], true) && ($goods['tariffFromCatalogue'] ?? null) === null) {
                $ownedRate = false;
                foreach (['dutyRate','dutyFormula','dutySpecificRate'] as $key) if (($goods[$key] ?? null) !== null && in_array($state['draft']['fieldStates'][$prefix.$key]['status'] ?? '', ['manual','selected','auto'], true)) $ownedRate = true;
                $this->set($state, $goods, 'tariffFromCatalogue', !$ownedRate, $evidence);
            }
            $imported = ($goods['tariffFromCatalogue'] ?? false) === true;
            $params = TariffEngine::parameters($entry['duty']['base_rule']);
            if ($imported) {
                foreach ($params as $key=>$value) {
                    $status = $state['draft']['fieldStates'][$prefix.$key]['status'] ?? '';
                    if (!in_array($status, ['manual','selected'], true)) $this->set($state, $goods, $key, $value, $evidence);
                }
                foreach (['rateBasis'=>'Базовая ЕТТ ЕАЭС, код '.$entry['code'].'; применимость проверяется отдельно.', 'ratesDate'=>$state['draft']['declaration']['calculationDate']] as $key=>$value) {
                    if (!in_array($state['draft']['fieldStates'][$prefix.$key]['status'] ?? '', ['manual','selected'], true)) $this->set($state, $goods, $key, $value, $key==='ratesDate'?[]:$evidence, $key==='ratesDate'?'linked':'reference');
                }
            }
            $units = $entry['units']['additional_unit_codes'];
            foreach ([['additionalUnitRequired','additionalUnit','additionalQuantity',0], ['secondaryAdditionalUnitRequired','secondaryAdditionalUnit','secondaryAdditionalQuantity',1]] as [$flag,$unit,$quantity,$index]) {
                $newUnit = $units[$index] ?? null;
                if (($goods[$unit] ?? null) !== $newUnit && ($goods[$quantity] ?? null) !== null) $this->set($state, $goods, $quantity, null, $evidence);
                $this->set($state, $goods, $flag, $newUnit !== null, $evidence);
                $this->set($state, $goods, $unit, $newUnit, $evidence);
            }
            $goods['_tariff'] = $entry;
            $linked = self::linkedQuantity($goods);
            $quantityStatus = $state['draft']['fieldStates'][$prefix.'dutyQuantity']['status'] ?? '';
            $quantityEvidence = $evidence;
            foreach (($goods['dutyQuantityBasis'] ?? '') === 'net_mass' ? ['netMass'] : ['additionalQuantity','secondaryAdditionalQuantity'] as $key) $quantityEvidence = [...$quantityEvidence, ...($state['draft']['fieldStates'][$prefix.$key]['evidenceIds'] ?? [])];
            if ($linked !== null || (($goods['dutyFormula'] ?? '') !== 'advalorem' && ($goods['dutyQuantityBasis'] ?? '') === 'net_mass')) $this->set($state, $goods, 'dutyQuantity', $linked, array_values(array_unique($quantityEvidence)), 'linked');
            elseif ($quantityStatus === 'linked') $this->set($state, $goods, 'dutyQuantity', null, $evidence);
            elseif ($changedCode && $quantityStatus !== 'manual') $this->set($state, $goods, 'dutyQuantity', null, $evidence);
            $hash = self::contextHash($state['draft'], $goods);
            if ($imported && ($previous === null || ($previous['contextHash'] ?? '') !== $hash)) $this->set($state, $goods, 'tariffConfirmed', false, $evidence);
            $goods['_tariff']['contextHash'] = $hash;
            unset($goods['_tariff']['coverage']['source_texts']);
        }
        unset($goods);
        return $state;
    }

    public static function linkedQuantity(array $goods): ?string
    {
        if (($goods['dutyFormula'] ?? 'advalorem') === 'advalorem') return null;
        if (($goods['dutyQuantityBasis'] ?? '') === 'goods_quantity' && ($goods['_tariff']['units']['qualifier'] ?? null) !== null) return null;
        return TariffEngine::linkedQuantity($goods);
    }

    public static function contextHash(array $draft, array $goods): string
    {
        $keys = [...TariffEngine::OPERANDS, 'commodityCode','originCountry','preferenceDuty','hasPreference','preferenceBasis','ratesDate','rateBasis'];
        return hash('sha256', json_encode([$goods['_tariff']['version'] ?? null, array_intersect_key($goods, array_flip($keys)), array_intersect_key($draft['declaration'], array_flip(['calculationDate','destinationCountry','tradeCountry','dispatchCountry']))], JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
    }

    private function set(array &$state, array &$goods, string $key, mixed $value, array $evidence, string $status = 'reference'): void
    {
        $goods[$key] = $value;
        $state['draft']['fieldStates']['goods/'.$goods['id'].'/'.$key] = ['status'=>$status,'candidateId'=>null,'evidenceIds'=>$evidence,'conflict'=>false,'needsReview'=>false];
    }
}
