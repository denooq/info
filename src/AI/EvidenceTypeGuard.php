<?php
declare(strict_types=1);

namespace Customs\AI;

/** Cross-field safeguards for values whose semantic type is evident in the cited text. */
final class EvidenceTypeGuard
{
    public static function isMeasuredNumber(string $rawValue, string $context): bool
    {
        $rawValue = trim($rawValue);
        if (!preg_match('/^[0-9][0-9 .,:\x{00A0}\x{202F}]*$/uD', $rawValue)) return false;

        $numberParts = preg_split('/\s+/u', $rawValue);
        $number = implode('\\s+', array_map(static fn(string $part): string => preg_quote($part, '/'), $numberParts));
        $unit = '(?:kg|kgs|кг|千克|t|tons?|tonnes?|тонн\p{L}*|吨|usd|kzt|eur|cny|rmb|rub|gbp|jpy|chf|cad|aud|\$|€|¥|₽)';
        $pattern = '/(?:(?<![\p{L}\p{N}])' . $unit . '[\s:：]*' . $number . '(?![\p{L}\p{N}])|(?<![\p{L}\p{N}])' . $number . '[\s:：]*' . $unit . '(?![\p{L}\p{N}]))/ui';
        return preg_match($pattern, $context) === 1;
    }
}
