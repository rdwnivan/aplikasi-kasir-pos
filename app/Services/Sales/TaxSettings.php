<?php

namespace App\Services\Sales;

use App\Support\Decimal;

/** PPN settings in effect for one checkout. */
final readonly class TaxSettings
{
    public function __construct(
        public bool $enabled,
        public string $rate,
        public int $dppNumerator,
        public int $dppDenominator,
        public bool $pricesIncludeTax,
    ) {
        if ($dppNumerator <= 0 || $dppDenominator <= 0 || $dppNumerator > $dppDenominator) {
            throw new \InvalidArgumentException('DPP fraction must be between 0 and 1.');
        }
    }

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('pos.tax.enabled'),
            rate: (string) config('pos.tax.rate'),
            dppNumerator: (int) config('pos.tax.dpp_numerator'),
            dppDenominator: (int) config('pos.tax.dpp_denominator'),
            pricesIncludeTax: (bool) config('pos.tax.prices_include_tax'),
        );
    }

    public static function none(): self
    {
        return new self(false, '0', 1, 1, true);
    }

    /** DPP as a fraction of the selling price excluding tax, e.g. 11/12. */
    public function dppFraction(): string
    {
        return Decimal::div((string) $this->dppNumerator, (string) $this->dppDenominator);
    }

    /** Tax as a fraction of the selling price excluding tax: 12% × 11/12 = 0.11. */
    public function effectiveRate(): string
    {
        if (! $this->enabled) {
            return '0';
        }

        return Decimal::mul(Decimal::div($this->rate, '100'), $this->dppFraction());
    }
}
