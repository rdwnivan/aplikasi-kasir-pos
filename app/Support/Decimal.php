<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact decimal arithmetic for HPP (cost) values, backed by bcmath.
 * Values are numeric strings so they round-trip with Postgres decimal columns.
 * Anything that is not a plain decimal number is rejected rather than silently read as 0.
 */
final class Decimal
{
    public const SCALE = 4;

    private const WORK_SCALE = 10;

    public static function add(string $a, string $b): string
    {
        return bcadd(self::num($a), self::num($b), self::WORK_SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul(self::num($a), self::num($b), self::WORK_SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (self::compare($b, '0') === 0) {
            throw new \DivisionByZeroError('Decimal division by zero.');
        }

        return bcdiv(self::num($a), self::num($b), self::WORK_SCALE);
    }

    /** -1, 0 or 1, compared at working precision. */
    public static function compare(string $a, string $b): int
    {
        return bccomp(self::num($a), self::num($b), self::WORK_SCALE);
    }

    /** Round half away from zero to the storage scale. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $value = self::num($value);
        $half = self::num('0.'.str_repeat('0', $scale).'5');
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        return bcadd($adjusted, '0', $scale);
    }

    public static function equals(string $a, string $b, int $scale = self::SCALE): bool
    {
        return bccomp(self::num($a), self::num($b), $scale) === 0;
    }

    /**
     * bcmath only accepts plain decimals ("12", "-0.5"), not "1e3" or " 12".
     *
     * @return numeric-string
     */
    private static function num(string $value): string
    {
        if (! is_numeric($value) || preg_match('/^[+-]?(\d+(\.\d*)?|\.\d+)$/', $value) !== 1) {
            throw new InvalidArgumentException("Not a decimal number: [{$value}].");
        }

        return $value;
    }
}
