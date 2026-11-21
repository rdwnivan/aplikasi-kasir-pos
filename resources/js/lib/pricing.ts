import { roundDiv } from '@/lib/money';
import type { DiscountType, TaxConfig } from '@/types/pos';

/**
 * Client mirror of App\Services\Sales\PriceCalculator, for live totals on the cashier screen.
 * The server recomputes everything at checkout and its numbers are what gets stored and
 * printed; this only has to match closely enough to show the right amount to collect.
 */

export type PricingLine = {
    unitPrice: number;
    qty: number;
    discountType: DiscountType | null;
    discountValue: number;
};

export type PricedLine = { gross: number; discount: number; total: number };

export type PricedCart = {
    lines: PricedLine[];
    subtotal: number;
    discount: number;
    taxableAmount: number;
    dppAmount: number;
    taxAmount: number;
    total: number;
};

export function discountAmount(
    base: number,
    type: DiscountType | null,
    value: number,
): number {
    if (type === null || value <= 0) {
        return 0;
    }

    if (type === 'percent') {
        return roundDiv(base * Math.min(value, 100), 100);
    }

    return Math.min(value, base);
}

export function priceCart(
    lines: PricingLine[],
    tax: TaxConfig,
    discountType: DiscountType | null,
    discountValue: number,
): PricedCart {
    const priced = lines.map((line) => {
        const gross = line.unitPrice * line.qty;
        const discount = discountAmount(
            gross,
            line.discountType,
            line.discountValue,
        );

        return { gross, discount, total: gross - discount };
    });

    const subtotal = priced.reduce((sum, l) => sum + l.total, 0);
    const discount = discountAmount(subtotal, discountType, discountValue);
    const afterDiscount = subtotal - discount;

    // Effective rate as an exact fraction p/q: 12% × 11/12 = (12·11)/(100·12) = 0,11.
    // Rates with up to two decimals are scaled to integers first.
    const rateScaled = Math.round(tax.rate * 100);
    const p = tax.enabled ? rateScaled * tax.dppNumerator : 0;
    const q = 10000 * tax.dppDenominator;

    let total: number;
    let taxAmount: number;
    let taxableAmount: number;

    if (tax.pricesIncludeTax) {
        total = afterDiscount;
        taxAmount = p === 0 ? 0 : roundDiv(total * p, q + p);
        taxableAmount = total - taxAmount;
    } else {
        taxableAmount = afterDiscount;
        taxAmount = p === 0 ? 0 : roundDiv(taxableAmount * p, q);
        total = taxableAmount + taxAmount;
    }

    const dppAmount = tax.enabled
        ? roundDiv(taxableAmount * tax.dppNumerator, tax.dppDenominator)
        : 0;

    return {
        lines: priced,
        subtotal,
        discount,
        taxableAmount,
        dppAmount,
        taxAmount,
        total,
    };
}
