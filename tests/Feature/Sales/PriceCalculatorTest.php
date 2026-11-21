<?php

use App\Enums\DiscountType;
use App\Services\Sales\CartLine;
use App\Services\Sales\PriceCalculator;
use App\Services\Sales\TaxSettings;
use Illuminate\Validation\ValidationException;

function ppn(bool $inclusive = true): TaxSettings
{
    return new TaxSettings(enabled: true, rate: '12', dppNumerator: 11, dppDenominator: 12, pricesIncludeTax: $inclusive);
}

beforeEach(fn () => $this->calc = new PriceCalculator);

describe('PPN 12% with DPP 11/12', function () {
    it('extracts tax from tax-inclusive prices', function () {
        $cart = $this->calc->price([new CartLine(111000, 1)], ppn(inclusive: true));

        expect($cart)
            ->total->toBe(111000)
            ->taxAmount->toBe(11000)     // effective 11%
            ->taxableAmount->toBe(100000)
            ->dppAmount->toBe(91667);    // 11/12 × 100.000
    });

    it('adds tax on top of tax-exclusive prices', function () {
        $cart = $this->calc->price([new CartLine(100000, 1)], ppn(inclusive: false));

        expect($cart)
            ->taxableAmount->toBe(100000)
            ->taxAmount->toBe(11000)
            ->total->toBe(111000)
            ->dppAmount->toBe(91667);
    });

    it('rounds to whole rupiah, half up', function () {
        // 3.500 × 0,11 / 1,11 = 346,85 → 347
        $cart = $this->calc->price([new CartLine(3500, 1)], ppn());

        expect($cart)->taxAmount->toBe(347)->taxableAmount->toBe(3153);
    });

    it('always adds up: taxable + tax = total', function (int $price, bool $inclusive) {
        $cart = $this->calc->price([new CartLine($price, 1)], ppn($inclusive));

        expect($cart->taxableAmount + $cart->taxAmount)->toBe($cart->total);
    })->with([1, 7, 99, 1500, 3333, 18500, 125000, 999999])->with([true, false]);

    it('charges no tax for a non-PKP store', function () {
        $cart = $this->calc->price([new CartLine(111000, 1)], TaxSettings::none());

        expect($cart)
            ->total->toBe(111000)
            ->taxAmount->toBe(0)
            ->dppAmount->toBe(0)
            ->taxableAmount->toBe(111000);
    });
});

describe('discounts', function () {
    it('applies a percent discount per line', function () {
        $cart = $this->calc->price([new CartLine(3500, 3, DiscountType::Percent, 10)], TaxSettings::none());

        expect($cart->lines[0])->gross->toBe(10500)->discount->toBe(1050)->total->toBe(9450);
    });

    it('applies an amount discount per line', function () {
        $cart = $this->calc->price([new CartLine(18500, 2, DiscountType::Amount, 2000)], TaxSettings::none());

        expect($cart->lines[0]->total)->toBe(35000);
    });

    it('applies the transaction discount after line discounts and before tax', function () {
        $cart = $this->calc->price([
            new CartLine(10000, 2, DiscountType::Amount, 1000), // 19.000
            new CartLine(5000, 1),                              //  5.000
        ], ppn(), DiscountType::Percent, 5);

        expect($cart)
            ->subtotal->toBe(24000)
            ->discount->toBe(1200)
            ->total->toBe(22800)
            ->taxAmount->toBe(2259); // 22.800 × 0,11 / 1,11 = 2.259,46
    });

    it('rounds percent discounts half up', function () {
        // 3.333 × 15% = 499,95 → 500
        $cart = $this->calc->price([new CartLine(3333, 1, DiscountType::Percent, 15)], TaxSettings::none());

        expect($cart->lines[0]->discount)->toBe(500);
    });

    it('rejects invalid input', function (array $lines, ?DiscountType $type, int $value) {
        $this->calc->price($lines, TaxSettings::none(), $type, $value);
    })->with([
        'empty cart' => [[], null, 0],
        'zero qty' => [[new CartLine(1000, 0)], null, 0],
        'line discount above price' => [[new CartLine(1000, 1, DiscountType::Amount, 1001)], null, 0],
        'line percent above 100' => [[new CartLine(1000, 1, DiscountType::Percent, 101)], null, 0],
        'cart discount above subtotal' => [[new CartLine(1000, 1)], DiscountType::Amount, 1001],
        'negative discount' => [[new CartLine(1000, 1)], DiscountType::Amount, -1],
    ])->throws(ValidationException::class);
});
