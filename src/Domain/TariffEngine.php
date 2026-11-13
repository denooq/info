<?php
declare(strict_types=1);
namespace Customs\Domain;

use Brick\Math\BigDecimal as D;
use Brick\Math\BigRational as Q;
use Brick\Math\RoundingMode as R;
use Customs\Http\ApiError;

final class TariffEngine
{
    public const FORMULAS = ['advalorem','specific','combined','combined_sum','alternative_minimum'];
    public const OPERANDS = ['dutyFormula','dutyRate','dutyAlternativeRate','dutySpecificRate','dutyCurrency','dutyPerQuantity','dutyUnitCode','dutyUnitSymbol','dutyQuantityBasis','dutyQuantity'];

    public static function parameters(array $rule): array
    {
        $specific = null; $percent = null; $alternative = null;
        if (($rule['kind'] ?? '') === 'ad_valorem') { $formula = 'advalorem'; $percent = $rule['percent']; }
        elseif (($rule['kind'] ?? '') === 'specific') { $formula = 'specific'; $specific = $rule; }
        elseif (in_array($rule['operator'] ?? '', ['max','sum'], true)) {
            $formula = $rule['operator'] === 'max' ? 'combined' : 'combined_sum';
            $percent = $rule['operands'][0]['percent']; $specific = $rule['operands'][1];
        } elseif (($rule['operator'] ?? '') === 'min') {
            $formula = 'alternative_minimum'; $alternative = $rule['operands'][0]['percent'];
            $percent = $rule['operands'][1]['operands'][0]['percent']; $specific = $rule['operands'][1]['operands'][1];
        } else throw new ApiError('TARIFF_RULE_UNSUPPORTED', 'Неизвестная структура ставки в справочнике.', 422);
        return ['dutyFormula' => $formula, 'dutyRate' => $percent, 'dutyAlternativeRate' => $alternative, 'dutySpecificRate' => $specific['amount'] ?? null, 'dutyCurrency' => $specific['currency'] ?? null, 'dutyPerQuantity' => $specific['per_quantity'] ?? null, 'dutyUnitCode' => $specific['unit_code'] ?? null, 'dutyUnitSymbol' => $specific['unit_symbol'] ?? null, 'dutyQuantityBasis' => $specific['quantity_basis'] ?? 'net_mass'];
    }

    private static function number(array $goods, string $key, bool $positive = false): string
    {
        $value = $goods[$key] ?? null;
        if ($value === null || !is_string($value)) throw new ApiError('TARIFF_OPERAND_REQUIRED', 'Не заполнен параметр ставки: ' . $key, 422);
        try { $number = D::of($value); }
        catch (\Throwable) { throw new ApiError('TARIFF_OPERAND_INVALID', 'Неверное число в параметре ставки: ' . $key, 422); }
        if ($number->isNegative() || ($positive && !$number->isPositive())) throw new ApiError('TARIFF_OPERAND_INVALID', 'Параметр ставки должен быть положительным: ' . $key, 422);
        return $value;
    }

    public static function rule(array $goods): array
    {
        $formula = $goods['dutyFormula'] ?? 'advalorem';
        if (!in_array($formula, self::FORMULAS, true)) throw new ApiError('PAYMENT_FORMULA_UNSUPPORTED', 'Неизвестная формула пошлины.', 422);
        $percent = $formula === 'specific' ? null : ['kind' => 'ad_valorem', 'percent' => self::number($goods, 'dutyRate'), 'basis' => 'customs_value'];
        if ($formula === 'advalorem') return $percent;
        $basis = $goods['dutyQuantityBasis'] ?? null;
        if (!in_array($basis, ['net_mass','goods_quantity','engine_displacement'], true) || trim($goods['dutyCurrency'] ?? '') === '' || trim($goods['dutyUnitSymbol'] ?? '') === '') throw new ApiError('TARIFF_OPERAND_REQUIRED', 'Укажите валюту, единицу и базу количества специфической ставки.', 422);
        if ($basis === 'net_mass' && ($goods['dutyUnitCode'] ?? '') !== '166') throw new ApiError('TARIFF_UNIT', 'Для базы нетто требуется единица кг (166).', 422);
        $specific = ['kind' => 'specific', 'amount' => self::number($goods, 'dutySpecificRate'), 'currency' => $goods['dutyCurrency'], 'per_quantity' => self::number($goods, 'dutyPerQuantity', true), 'unit_code' => $goods['dutyUnitCode'] ?? null, 'unit_symbol' => $goods['dutyUnitSymbol'], 'quantity_basis' => $basis];
        return match ($formula) {
            'specific' => $specific,
            'combined' => ['operator' => 'max', 'operands' => [$percent, $specific]],
            'combined_sum' => ['operator' => 'sum', 'operands' => [$percent, $specific]],
            'alternative_minimum' => ['operator' => 'min', 'operands' => [['kind' => 'ad_valorem', 'percent' => self::number($goods, 'dutyAlternativeRate'), 'basis' => 'customs_value'], ['operator' => 'max', 'operands' => [$percent, $specific]]]],
        };
    }

    public static function linkedQuantity(array $goods): ?string
    {
        if (($goods['dutyQuantityBasis'] ?? '') === 'net_mass') return $goods['netMass'] ?? null;
        if (($goods['dutyQuantityBasis'] ?? '') !== 'goods_quantity') return null;
        $target = $goods['dutyUnitCode'] ?? null;
        // Only dimensionally identical measures are interchangeable. Pair/item and pure/total alcohol are distinct.
        $units = ['796' => ['count','1'], '797' => ['count','100'], '798' => ['count','1000'], '112' => ['volume','1'], '130' => ['volume','1000'], '055' => ['area','1'], '715' => ['pairs','1']];
        foreach ([['additionalUnit','additionalQuantity'], ['secondaryAdditionalUnit','secondaryAdditionalQuantity']] as [$unitKey,$quantityKey]) {
            $source = $goods[$unitKey] ?? null; $quantity = $goods[$quantityKey] ?? null;
            if (!isset($units[$source], $units[$target]) || $units[$source][0] !== $units[$target][0] || $quantity === null) continue;
            return (string) D::of($quantity)->multipliedBy($units[$source][1])->dividedByExact($units[$target][1])->strippedOfTrailingZeros();
        }
        return null;
    }

    public static function describe(array $rule): string
    {
        if (($rule['kind'] ?? '') === 'ad_valorem') return $rule['percent'] . '% от таможенной стоимости';
        if (($rule['kind'] ?? '') === 'specific') return $rule['amount'] . ' ' . $rule['currency'] . ' за ' . $rule['per_quantity'] . ' ' . $rule['unit_symbol'];
        $parts = array_map(self::describe(...), $rule['operands']);
        return $rule['operator'] . '(' . implode('; ', $parts) . ')';
    }

    public function calculate(array $rule, string $customsValue, array $goods, array $rates): array
    {
        $trace = [];
        $value = $this->evaluate($rule, $customsValue, $goods, $rates, $trace);
        return ['amount' => (string) $value->toScale(2, R::HalfUp), 'formula' => self::describe($rule), 'rule' => $rule, 'trace' => $trace];
    }

    private function evaluate(array $rule, string $customsValue, array $goods, array $rates, array &$trace): Q
    {
        if (($rule['kind'] ?? '') === 'ad_valorem') {
            $value = Q::of($customsValue)->multipliedBy($rule['percent'])->dividedBy('100');
            $trace[] = ['kind' => 'ad_valorem', 'percent' => $rule['percent'], 'base' => $customsValue, 'amount' => (string) $value->toScale(2, R::HalfUp)];
            return $value;
        }
        if (($rule['kind'] ?? '') === 'specific') {
            $quantity = $rule['quantity_basis'] === 'net_mass' ? self::number($goods, 'netMass', true) : self::number($goods, 'dutyQuantity', true);
            $value = Q::of($rule['amount'])->multipliedBy($quantity)->dividedBy($rule['per_quantity']);
            $fx = '1'; $nominal = '1';
            if ($rule['currency'] !== 'KZT') {
                $rate = null; foreach ($rates as $row) if ($row['currency'] === $rule['currency']) $rate = $row;
                if ($rate === null || !D::of($rate['value'])->isPositive() || !D::of($rate['nominal'])->isPositive()) throw new ApiError('RATE_REQUIRED', 'Нужен подтверждённый курс ' . $rule['currency'] . '.', 422);
                $fx = $rate['value']; $nominal = $rate['nominal'];
            }
            $value = $value->multipliedBy($fx)->dividedBy($nominal);
            $trace[] = ['kind' => 'specific', 'quantity' => $quantity, 'unit' => $rule['unit_symbol'], 'rate' => $rule['amount'], 'perQuantity' => $rule['per_quantity'], 'currency' => $rule['currency'], 'fx' => $fx, 'nominal' => $nominal, 'amount' => (string) $value->toScale(2, R::HalfUp)];
            return $value;
        }
        if (!in_array($rule['operator'] ?? '', ['min','max','sum'], true) || count($rule['operands'] ?? []) < 2) throw new ApiError('TARIFF_RULE_UNSUPPORTED', 'Неизвестная операция ставки.', 422);
        $operands = []; foreach ($rule['operands'] as $part) $operands[] = $this->evaluate($part, $customsValue, $goods, $rates, $trace);
        $value = array_shift($operands);
        foreach ($operands as $operand) $value = match ($rule['operator']) { 'sum' => $value->plus($operand), 'max' => $operand->compareTo($value) > 0 ? $operand : $value, 'min' => $operand->compareTo($value) < 0 ? $operand : $value };
        $trace[] = ['kind' => $rule['operator'], 'amount' => (string) $value->toScale(2, R::HalfUp), 'exact' => (string) $value];
        return $value;
    }
}
