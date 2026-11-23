<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class Validator
{
    private array $errors = [];
    public function __construct(private readonly Registry $registry) {}

    public function validate(array $state): array
    {
        $state=(new StatutoryRules())->apply($state);
        $this->errors = []; $d = $state['draft']; $normalizer = new Normalizer();
        $reviewFields=(new GraphRegistry())->reviewFields();
        foreach ($this->registry->fields() as $f) {
            if (in_array('calculated', $f['acquisition'], true)) continue;
            if($f['scope']==='keden'||$f['id']==='goods.sourceLineIds')continue;
            if(in_array($f['id'],$reviewFields,true)&&!in_array($f['scope'],['party','rate'],true))continue;
            $notFilled=['3','4','7','10','13','27','28','40','48','49','50','51','52','53'];
            if($f['dtGraphs']!==[]&&array_diff($f['dtGraphs'],$notFilled)===[])continue;
            $collection = $this->registry->data()['scopes'][$f['scope']]['collection'];
            $rows = $collection === null ? [$d[$f['scope']]] : $d[$collection];
            if($f['scope']==='party')$rows=array_values(array_filter($rows,fn($row)=>in_array($row['role'],['seller','buyer'],true)));
            if ($f['scope'] === 'payment' && ($d['declaration']['paymentFactsAvailable'] ?? null) !== true) continue;
            foreach ($rows as $row) {
                $id = $collection === null ? null : $row['id'];
                $required = Conditions::evaluate($f['requiredWhen'], $row);
                $value = $row[$f['key']] ?? null;
                $address = Draft::address($f['scope'], $id, $f['key']);
                $meta = $d['fieldStates'][$address] ?? [];
                if (($meta['conflict'] ?? false) || ($meta['needsReview'] ?? false)) $this->error($address, 'CONFLICT', 'Выберите предложение или подтвердите значение вручную.');
                if (Conditions::missing($value)) {
                    if ($required) $this->error($address, 'REQUIRED', 'Заполните обязательное поле.');
                    continue;
                }
                // A dependent input with an inactive condition has no effect on this declaration.
                if (!$required && is_array($f['requiredWhen'])) continue;
                foreach ($normalizer->issues($f, $value) as $message) {
                    // Partial code lists allow explicit manual confirmation; syntax is always checked.
                    if (str_contains($message, 'справочник') && (in_array($meta['status'] ?? '', ['manual','selected'], true) || ($f['id']==='rate.currency' && ($meta['status']??'')==='nbk' && ($row['_nbk']['currency']??null)===$value))) continue;
                    $this->error($address, 'VALUE', $message);
                }
                if (isset($f['fixedValue']) && $value !== $f['fixedValue']) $this->error($address, 'PROFILE', 'Значение не поддерживается профилем ИМ 40 / метод 1.');
            }
        }
        foreach (Registry::resource('rules')['applicability']['conditions'] as $rule) {
            if (isset($rule['if']) && !Conditions::evaluate($rule['if'], $d['declaration'])) continue;
            if (!Conditions::evaluate($rule, $d['declaration'])) $this->error('declaration/_/'.$rule['field'], 'METHOD_ONE', $rule['error']);
        }
        if ($d['goods'] === []) $this->error('goods', 'GOODS_REQUIRED', 'Добавьте хотя бы один товар.');
        $roles = array_column($d['parties'], null, 'role');
        foreach (['seller','buyer'] as $role) {
            if (!isset($roles[$role])) { $this->error('parties', 'PARTY_REQUIRED', 'Не указан участник: '.$role); continue; }
            $seen = []; $current = $role;
            while (($next = $roles[$current]['sameAsRole'] ?? null) !== null) {
                if (isset($seen[$current]) || !isset($roles[$next])) { $this->error('party/'.$roles[$role]['id'].'/sameAsRole', 'PARTY_LINK', 'Ссылка на участника отсутствует или образует цикл.'); break; }
                $seen[$current] = true; $current = $next;
            }
        }
        $goodsIds = array_column($d['goods'], 'id'); $packageIds = array_column($d['packages'], 'id'); $docIds = array_column($d['documents'], 'id');
        $usedLines = [];
        foreach ($d['goods'] as $g) {
            $prefix = 'goods/'.$g['id'].'/';
            if (!in_array($g['dutyFormula']??null, TariffEngine::FORMULAS, true) || ($g['exciseApplicable']??null)!==false) $this->error($prefix.'dutyFormula','PAYMENT_FORMULA_UNSUPPORTED','Выберите поддерживаемую формулу; расчёт акциза пока не поддерживается.');
            $this->tariff($d, $g, $prefix);
            if ($g['netMass'] !== null && $g['grossMass'] !== null && BigDecimal::of($g['netMass'])->isGreaterThan($g['grossMass'])) $this->error($prefix.'netMass', 'MASS_ORDER', 'Нетто превышает брутто.');
            foreach ($g['sourceLineIds'] as $line) {
                if (!isset($d['sourceLines'][$line]) || isset($usedLines[$line])) $this->error($prefix.'groupConfirmed', 'SOURCE_LINE', 'Проверьте группировку в графе 31: исходная строка отсутствует или повторяется в другом товаре.');
                $usedLines[$line] = true;
            }
            $this->links($prefix.'packageIds', $g['packageIds'], $packageIds);
            if (array_diff($g['packageIds'], $d['declaration']['shipmentPackageIds'])) $this->error($prefix.'packageIds', 'PACKAGE_SHIPMENT', 'Выбранные места товара должны также входить в грузовые места поставки.');
            if ($g['ratesDate'] !== $d['declaration']['calculationDate']) $this->error($prefix.'ratesDate', 'RATE_DATE', 'Дата ставок должна совпадать с датой расчёта.');
            if (($g['statisticalBasisConfirmed'] ?? null) !== true) $this->error($prefix.'statisticalBasisConfirmed', 'STAT_CONFIRM', 'Подтвердите корректировки статистической стоимости.');
        }
        $this->links('declaration/_/shipmentPackageIds', $d['declaration']['shipmentPackageIds'], $packageIds);
        foreach ($d['packages'] as $p) if (!is_int($p['quantity'] ?? null) || $p['quantity'] < 1 || trim($p['label'] ?? '') === '') $this->error('package/'.$p['id'].'/quantity', 'PACKAGE', 'Укажите название группы мест и положительное количество.');
        foreach ($d['documents'] as $doc) {
            $this->links('document/'.$doc['id'].'/goodsIds', $doc['goodsIds'], $goodsIds);
            $this->links('document/'.$doc['id'].'/documentIds', $doc['documentIds'], array_keys($state['documents']));
        }
        $this->links('declaration/_/valuationDocumentIds', $d['declaration']['valuationDocumentIds'], $docIds);
        $requiredCurrencies = \Customs\Rates\ExchangeRates::required($d);
        $currencies = [];
        foreach ($d['rates'] as $r) {
            $a = 'rate/'.$r['id'].'/';
            if (isset($currencies[$r['currency'] ?? ''])) $this->error($a.'currency', 'RATE_DUPLICATE', 'Для валюты нужен один курс.');
            $currencies[$r['currency'] ?? ''] = true;
            if ($r['date'] !== $d['declaration']['calculationDate']) $this->error($a.'date', 'RATE_DATE', 'Дата курса должна совпадать с датой расчёта.');
            if (($r['confirmed'] ?? null) !== true) $this->error($a.'confirmed', 'RATE_CONFIRM', 'Подтвердите курс и источник.');
            if(isset($r['_nbk']) && $r['value']!==null && $r['nominal']!==null) {
                try{$changed=!BigDecimal::of($r['value'])->isEqualTo($r['_nbk']['value']) || !BigDecimal::of($r['nominal'])->isEqualTo($r['_nbk']['nominal']) || $r['date']!==$r['_nbk']['date'];}catch(\Throwable){$changed=true;}
                if($changed && ($d['fieldStates'][$a.'source']['status']??'')!=='manual')$this->error($a.'source','RATE_MANUAL_BASIS','Курс отличается от полученного из НБК. Укажите основание ручной корректировки.');
            }
        }
        foreach ($requiredCurrencies as $currency) if ($currency !== 'KZT' && !isset($currencies[$currency])) $this->error('rates', 'RATE_REQUIRED', 'Добавьте курс '.$currency.' на расчётную дату.');
        $costKinds = [];
        foreach ($d['costs'] as $costIndex=>$c) {
            $a = 'cost/'.$c['id'].'/'; $costKinds[] = $c['kind'];
            $applicable = ($d['declaration'][$c['kind'].'Applicable'] ?? null) === true;
            if (!$applicable) {
                $question=$this->registry->fields()['declaration.'.$c['kind'].'Applicable']??null;
                $address=$question===null?$a.'kind':'declaration/_/'.$question['key'];
                $message=$question===null?'Выберите вид компонента ДТС.':'Для компонента ДТС '.($costIndex+1).' отметьте «'.$question['label'].'» = «Да» или удалите компонент, если он добавлен ошибочно.';
                $this->error($address, 'COST_APPLICABILITY', $message);
            }
            if (($c['allocationConfirmed'] ?? null) !== true) $this->error($a.'allocationConfirmed', 'ALLOCATION_CONFIRM', 'Подтвердите распределение расхода.');
            $this->links($a.'goodsIds', $c['goodsIds'], $goodsIds);
            if ($c['direction'] === 'deduction' && $c['includedInPrice'] !== true) $this->error($a.'includedInPrice', 'DEDUCTION_PRICE', 'Вычет допускается для суммы, включённой в цену.');
            if (($c['allocationBasis'] ?? '') === 'manual') {
                $allocations = $c['allocations'] ?? [];
                $sum = BigDecimal::zero();
                foreach ($c['goodsIds'] as $id) {
                    try { $part = BigDecimal::of($allocations[$id] ?? '-1'); if ($part->isNegative()) throw new \InvalidArgumentException(); $sum = $sum->plus($part); }
                    catch (\Throwable) { $this->error($a.'allocationBasis', 'ALLOCATION', 'Введите долю каждого товара не меньше нуля.'); }
                }
                if ($c['amount'] !== null && !$sum->isEqualTo($c['amount'])) $this->error($a.'allocationBasis', 'ALLOCATION_SUM', 'Сумма ручных долей должна равняться сумме расхода в исходной валюте.');
                if (array_diff(array_keys($allocations), $c['goodsIds'])) $this->error($a.'goodsIds', 'ALLOCATION_LINK', 'Доли содержат посторонний товар.');
            }
        }
        foreach ($d['declaration'] as $key => $value) if (str_ends_with($key, 'Applicable') && $value === true) {
            $kind = substr($key, 0, -10);
            if (in_array($kind, ['specifications','reference'], true)) continue;
            if (!in_array($kind, $costKinds, true)) $this->error('declaration/_/'.$key, 'COST_REQUIRED', 'Добавьте строку соответствующего компонента ДТС.');
        }
        if ($d['declaration']['paymentFactsAvailable'] === true && $d['payments'] === []) $this->error('payments','PAYMENT_REQUIRED','Укажите фактическую уплату с основанием.');
        foreach($d['invalidInputs']??[] as $address=>$invalid)$this->error($address,'VALUE',$invalid['message']);
        foreach($d['statutoryRules']['errors'] as $error)$this->errors[]=$error;
        foreach((new ReviewValidator())->validate($state)['errors'] as $error)if(!array_filter($this->errors,fn($e)=>$e['address']===$error['address']&&$e['code']===$error['code']&&$e['message']===$error['message']))$this->errors[]=$error;
        return ['valid' => $this->errors === [], 'errors' => $this->errors, 'count' => count($this->errors), 'registryVersion' => $this->registry->data()['version'],'stage'=>'calculation'];
    }

    public function requireValid(array $state): void
    {
        $result = $this->validate($state);
        if (!$result['valid']) throw new ApiError('DECLARATION_INVALID','Заполните и проверьте обязательные данные.',422,$result);
    }
    private function tariff(array $draft, array $goods, string $prefix): void
    {
        $entry = $goods['_tariff'] ?? null;
        if ($entry !== null) {
            if ($entry['code'] !== ($goods['commodityCode'] ?? '') || $entry['version'] !== (new TariffCatalogue())->version()) $this->error($prefix.'commodityCode', 'TARIFF_STALE', 'Код или версия тарифа изменены. Обновите данные товара.');
            foreach ([['additionalUnitRequired','additionalUnit',0], ['secondaryAdditionalUnitRequired','secondaryAdditionalUnit',1]] as [$flag,$unit,$index]) {
                $expected = $entry['units']['additional_unit_codes'][$index] ?? null;
                if (($goods[$flag]??false)!==($expected!==null) || ($goods[$unit]??null)!==$expected) $this->error($prefix.$unit, 'TARIFF_UNIT', 'Дополнительная единица должна соответствовать выбранному коду ТН ВЭД.');
            }
        }
        if (($goods['tariffFromCatalogue'] ?? false) === true) {
            if ($entry === null || ($goods['tariffConfirmed']??false)!==true || ($entry['contextHash']??'') !== TariffDefaults::contextHash($draft,$goods)) $this->error($prefix.'tariffConfirmed','TARIFF_CONFIRM','Проверьте применимость базовой ставки к дате, стране происхождения, льготам, исключениям Казахстана и сноскам; затем подтвердите её.');
            $base = $entry === null ? [] : TariffEngine::parameters($entry['duty']['base_rule']);
            $modified = false;
            foreach ($base as $key=>$value) if (($goods[$key]??null)!==$value) $modified = true;
            if ($modified && !in_array($draft['fieldStates'][$prefix.'rateBasis']['status']??'', ['manual','selected'], true)) $this->error($prefix.'rateBasis','TARIFF_BASIS','Ставка отличается от базовой ЕТТ. Укажите основание изменения вручную.');
        } elseif ($entry !== null && ($draft['fieldStates'][$prefix.'rateBasis']['status']??'')==='reference') $this->error($prefix.'rateBasis','TARIFF_BASIS','Для ручной ставки укажите её основание.');
        if (in_array($goods['dutyFormula']??'', TariffEngine::FORMULAS, true)) {
            try {
                TariffEngine::rule($goods);
                if ($goods['dutyFormula'] !== 'advalorem') {
                    $linked = TariffDefaults::linkedQuantity($goods);
                    if ($linked !== null && ($goods['dutyQuantity']??null)!==null && !BigDecimal::of($linked)->isEqualTo($goods['dutyQuantity'])) $this->error($prefix.'dutyQuantity','TARIFF_QUANTITY','Количество пошлины должно совпадать с соответствующей массой или количеством товара.');
                    if (($goods['dutyQuantityBasis']??'')==='net_mass' && ($goods['dutyUnitCode']??'')!=='166') $this->error($prefix.'dutyUnitCode','TARIFF_UNIT','Расчёт по нетто требует единицу кг (166).');
                }
            } catch (\Throwable $error) { $this->error($prefix.'dutyFormula','TARIFF_OPERANDS','Проверьте параметры ставки: '.$error->getMessage()); }
        }
    }
    private function links(string $address, array $values, array $allowed): void { if (array_diff($values,$allowed)) $this->error($address,'LINK_INVALID','Выбрана ссылка на отсутствующую строку.'); }
    private function error(string $address, string $code, string $message): void {
        $parts=explode('/',$address,3);$key=$parts[2]??'';
        if(($parts[0]??'')==='goods'&&(StatutoryRules::owns('goods.'.$key)||$key==='groupConfirmed'))$address='goods/'.$parts[1].'/tariffConfirmed';
        $entry=compact('address','code','message');if(!in_array($entry,$this->errors,true))$this->errors[]=$entry;
    }
}
