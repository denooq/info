<?php
declare(strict_types=1);
namespace Customs\Domain;

final class Conditions
{
    public static function evaluate(bool|array $condition, array $row): bool
    {
        if (is_bool($condition)) return $condition;
        return match ($condition['op']) {
            'eq' => ($row[$condition['field']] ?? null) === $condition['value'],
            'all' => !in_array(false, array_map(fn($c) => self::evaluate($c, $row), $condition['args']), true),
            'any' => in_array(true, array_map(fn($c) => self::evaluate($c, $row), $condition['args']), true),
            'not' => !self::evaluate($condition['arg'], $row),
            default => throw new \LogicException('Unknown condition'),
        };
    }
    public static function missing(mixed $value): bool { return $value === null || $value === '' || $value === []; }
}
