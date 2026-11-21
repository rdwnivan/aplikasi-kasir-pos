<?php

namespace App\Support;

/**
 * Exact decimal arithmetic for HPP (cost) values, backed by bcmath.
 * Values are numeric strings so they round-trip with Postgres decimal columns.
 */
final class Decimal
{
    public const SCALE = 4;

    private const WORK_SCALE = 10;

    public static function add(string $a, string $b): string
    {
        return bcadd($a, $b, self::WORK_SCALE);
    }

    public static function mul(string $a, string $b): string
    {
        return bcmul($a, $b, self::WORK_SCALE);
    }

    public static function div(string $a, string $b): string
    {
        if (bccomp($b, '0', self::WORK_SCALE) === 0) {
            throw new \DivisionByZeroError('Decimal division by zero.');
        }

        return bcdiv($a, $b, self::WORK_SCALE);
    }

    /** Round half away from zero to the storage scale. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $half = '0.'.str_repeat('0', $scale).'5';
        $adjusted = str_starts_with($value, '-')
            ? bcsub($value, $half, $scale)
            : bcadd($value, $half, $scale);

        return bcadd($adjusted, '0', $scale);
    }

    public static function equals(string $a, string $b, int $scale = self::SCALE): bool
    {
        return bccomp($a, $b, $scale) === 0;
    }
}
