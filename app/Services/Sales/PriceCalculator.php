<?php

namespace App\Services\Sales;

use App\Enums\DiscountType;
use App\Support\Decimal;
use Illuminate\Validation\ValidationException;

/**
 * Pure pricing: line discounts → transaction discount → PPN. All results are whole Rupiah,
 * rounded half up, and the parts always add up (taxable + tax = total) so the receipt never
 * shows a one-rupiah gap.
 *
 * PPN with DPP nilai lain (PMK 131/2024): tax = 12% × (11/12 × price excl. tax), i.e. an
 * effective 11% of the price excluding tax.
 *  - prices include tax: tax = round(total × e / (1 + e)), taxable = total − tax
 *  - prices exclude tax: tax = round(taxable × e),         total   = taxable + tax
 */
final class PriceCalculator
{
    /** @param list<CartLine> $lines */
    public function price(
        array $lines,
        TaxSettings $tax,
        ?DiscountType $discountType = null,
        int $discountValue = 0,
    ): PricedCart {
        if ($lines === []) {
            throw ValidationException::withMessages(['items' => 'Keranjang masih kosong.']);
        }

        $priced = [];
        $errors = [];

        foreach ($lines as $i => $line) {
            if ($line->qty <= 0) {
                $errors["items.$i.qty"] = 'Jumlah harus lebih dari 0.';

                continue;
            }

            $gross = $line->unitPrice * $line->qty;
            $discount = self::discount($gross, $line->discountType, $line->discountValue, "items.$i.discount", $errors);
            $priced[] = new PricedLine($gross, $discount, $gross - $discount);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $subtotal = array_sum(array_map(fn (PricedLine $l) => $l->total, $priced));
        $discount = self::discount($subtotal, $discountType, $discountValue, 'discount', $errors);

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $afterDiscount = $subtotal - $discount;
        $rate = $tax->effectiveRate();

        if ($tax->pricesIncludeTax) {
            $total = $afterDiscount;
            $taxAmount = self::rupiah(Decimal::div(
                Decimal::mul((string) $total, $rate),
                Decimal::add('1', $rate),
            ));
            $taxable = $total - $taxAmount;
        } else {
            $taxable = $afterDiscount;
            $taxAmount = self::rupiah(Decimal::mul((string) $taxable, $rate));
            $total = $taxable + $taxAmount;
        }

        $dpp = $tax->enabled ? self::rupiah(Decimal::mul((string) $taxable, $tax->dppFraction())) : 0;

        return new PricedCart($priced, $subtotal, $discount, $taxable, $dpp, $taxAmount, $total);
    }

    /** @param array<string, string> $errors */
    private static function discount(int $base, ?DiscountType $type, int $value, string $key, array &$errors): int
    {
        if ($type === null || $value === 0) {
            return 0;
        }

        if ($value < 0) {
            $errors[$key] = 'Diskon tidak boleh negatif.';

            return 0;
        }

        if ($type === DiscountType::Percent) {
            if ($value > 100) {
                $errors[$key] = 'Diskon persen maksimal 100%.';

                return 0;
            }

            return self::rupiah(Decimal::div(Decimal::mul((string) $base, (string) $value), '100'));
        }

        if ($value > $base) {
            $errors[$key] = 'Diskon melebihi harga.';

            return 0;
        }

        return $value;
    }

    private static function rupiah(string $amount): int
    {
        return (int) Decimal::round($amount, 0);
    }
}
