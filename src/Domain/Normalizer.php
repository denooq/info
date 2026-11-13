<?php
declare(strict_types=1);

namespace Customs\Domain;

use Brick\Math\BigDecimal;
use Customs\Http\ApiError;

final class Normalizer
{
    public function value(array $field, string $raw, ?string $unit, string $context): mixed
    {
        $raw = trim($raw);
        return match ($field['type']) {
            'decimal' => $this->decimal($field, $raw, $unit, $context),
            'integer' => $this->integer($raw),
            'boolean' => match (mb_strtolower($raw)) { 'да','yes','true','1' => true, 'нет','no','false','0' => false, default => throw new ApiError('VALUE_INVALID', 'Нет явного ответа да/нет.') },
            'date' => $this->date($raw),
            'code','enum' => $this->code($field, $raw),
            'code-list','string-list' => array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/u', $raw)))),
            'date-list' => array_map($this->date(...), array_map('trim', preg_split('/[,;\n]+/u', $raw))),
            default => $raw,
        };
    }

    public function decimal(array $field, string $raw, ?string $unit, string $context): string
    {
        if ($unit !== null && $unit !== '' && str_ends_with(mb_strtolower($raw), mb_strtolower($unit))) $raw = trim(mb_substr($raw, 0, mb_strlen($raw) - mb_strlen($unit)));
        $number = preg_replace('/[\s\x{00A0}\x{202F}]/u', '', $raw);
        if (str_contains($number, '.') && str_contains($number, ',')) {
            if (preg_match('/^-?[0-9]{1,3}(,[0-9]{3})+\.[0-9]+$/D', $number)) $number = str_replace(',', '', $number);
            elseif (preg_match('/^-?[0-9]{1,3}(\.[0-9]{3})+,[0-9]+$/D', $number)) $number = str_replace(',', '.', str_replace('.', '', $number));
            else throw new ApiError('VALUE_AMBIGUOUS', 'Неоднозначная запись десятичного числа.');
        } else $number = str_replace(',', '.', $number);
        if (!preg_match('/^-?[0-9]+(?:\.[0-9]+)?$/D', $number)) throw new ApiError('VALUE_INVALID', 'Неверная запись десятичного числа.');
        $value = BigDecimal::of($number);
        if (($field['unit'] ?? null) === 'kg') {
            $sourceUnit = mb_strtolower(trim($unit ?? ''));
            if (in_array($sourceUnit, ['t','ton','tons','tonne','tonnes','т','тонна','тонны','тонн','吨'], true)) {
                if (!preg_match('/\b(?:t|tons?|tonnes?|т|тонн[аы]?)\b|吨/ui', $context)) throw new ApiError('UNIT_UNCONFIRMED', 'Единица массы не подтверждена источником.');
                $value = $value->multipliedBy('1000');
            } elseif (in_array($sourceUnit, ['kg','kgs','кг','килограмм','килограммы','千克'], true)) {
                if (!preg_match('/(?<!\p{L})(?:kgs?|кг|килограмм\p{L}*)(?!\p{L})|千克/ui', $context)) throw new ApiError('UNIT_UNCONFIRMED', 'Килограммы не подтверждены источником.');
            } else throw new ApiError('UNIT_UNCONFIRMED', 'Нужно подтвердить исходную единицу массы.');
        }
        return (string) $value->strippedOfTrailingZeros();
    }

    public function issues(array $field, mixed $value): array
    {
        $issues = [];
        if (($field['id'] ?? '') === 'goods.commodityCode' && preg_match('/^[0-9]{10}$/D', (string) $value) && !TnvedCatalogue::containsCode((string) $value)) $issues[] = 'Код отсутствует в подключённом справочнике ТН ВЭД; требуется ручная проверка.';
        if ($field['type'] === 'enum' && isset($field['values']) && !in_array($value, $field['values'], true)) $issues[] = 'Выберите допустимое значение списка.';
        $catalogs = Registry::resource('catalogs');
        $catalog = $catalogs['fieldCatalogs'][$field['id']] ?? null;
        if ($catalog !== null && is_string($value) && !in_array($value, array_column($catalogs['lists'][$catalog], 'code'), true)) $issues[] = 'Код отсутствует в проверочном справочнике; требуется ручная проверка.';
        foreach ($field['validWhen'] as $rule) {
            if ($rule['op'] === 'pattern' && (!is_string($value) || !preg_match('~' . $rule['value'] . '~uD', $value))) $issues[] = 'Значение не соответствует формату поля.';
            if ($rule['op'] === 'decimal') {
                try {
                    $number = BigDecimal::of((string) $value);
                    if (isset($rule['min']) && ($number->isLessThan($rule['min']) || (($rule['exclusiveMin'] ?? false) && $number->isEqualTo($rule['min'])))) $issues[] = 'Значение меньше допустимого.';
                } catch (\Throwable) { $issues[] = 'Неверное число.'; }
            }
        }
        return $issues;
    }

    public function date(string $raw): string
    {
        $raw = trim(preg_replace('/\s*г(?:ода)?\.?$/ui', '', str_replace(['«', '»', '"'], '', trim($raw))));
        $months = ['января' => '01', 'февраля' => '02', 'марта' => '03', 'апреля' => '04', 'мая' => '05', 'июня' => '06', 'июля' => '07', 'августа' => '08', 'сентября' => '09', 'октября' => '10', 'ноября' => '11', 'декабря' => '12'];
        if (preg_match('/^([0-9]{1,2})\s+([а-я]+)\s+([0-9]{4})$/uiD', $raw, $match) && isset($months[mb_strtolower($match[2])])) $raw = $match[3] . '-' . $months[mb_strtolower($match[2])] . '-' . str_pad($match[1], 2, '0', STR_PAD_LEFT);
        if (preg_match('/^[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}$/D', $raw)) throw new ApiError('DATE_AMBIGUOUS', 'Числовая дата со слешами требует проверки порядка дня/месяца.');
        $raw = preg_replace('/^([0-9]{4})年([0-9]{1,2})月([0-9]{1,2})日$/u', '$1-$2-$3', $raw);
        $raw = preg_replace('/\b([0-9]{1,2})(?:ST|ND|RD|TH)\b/i', '$1', $raw);
        $raw = preg_replace('/([A-Za-z]{3})\.\s*([0-9]{4})/', '$1 $2', $raw);
        foreach (['!Y-m-d','!Ymd','!d.m.Y','!d M Y','!M d Y','!d F Y','!F d, Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, $raw);
            $errors = \DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) return $date->format('Y-m-d');
        }
        throw new ApiError('DATE_INVALID', 'Дата не распознана однозначно.');
    }

    private function integer(string $raw): int
    {
        if (!preg_match('/^[0-9]+$/D', $raw)) throw new ApiError('VALUE_INVALID', 'Ожидается целое число.');
        $value = filter_var(ltrim($raw, '0') ?: '0', FILTER_VALIDATE_INT);
        if ($value === false) throw new ApiError('VALUE_INVALID', 'Целое число выходит за допустимый диапазон.');
        return $value;
    }

    private function code(array $field, string $raw): string
    {
        if (($field['id'] ?? '') === 'goods.commodityCode' && preg_match('/^[0-9\s\x{00A0}\x{202F}]+$/uD', $raw)) return preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $raw);
        $catalogs = Registry::resource('catalogs');
        $catalog = $catalogs['fieldCatalogs'][$field['id']] ?? null;
        if ($catalog !== null) foreach ($catalogs['lists'][$catalog] as $entry) {
            foreach ([$entry['code'], $entry['label'], ...($entry['aliases'] ?? [])] as $alias) if ($this->codeText($raw) === $this->codeText($alias)) return $entry['code'];
        }
        // Preserve unknown codes and short HS values; validation/confirmation is separate.
        return $raw;
    }

    private function codeText(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/[\s\x{00A0}\x{202F}]+/u', ' ', $value)));
    }
}
