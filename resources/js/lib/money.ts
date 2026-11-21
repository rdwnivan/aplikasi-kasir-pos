const rupiahFormat = new Intl.NumberFormat('id-ID', {
    maximumFractionDigits: 0,
});

/** 15000 → "Rp 15.000" */
export function formatRupiah(amount: number): string {
    const sign = amount < 0 ? '-' : '';

    return `${sign}Rp ${rupiahFormat.format(Math.abs(amount))}`;
}

/** 15000 → "15.000" (for inputs) */
export function formatNumber(amount: number): string {
    return rupiahFormat.format(amount);
}

/** "Rp 15.000" / "15.000" / "15000" → 15000. Anything without digits → 0. */
export function parseRupiah(text: string): number {
    const digits = text.replace(/\D/g, '');

    return digits === '' ? 0 : Number.parseInt(digits, 10);
}

/** Integer a / b rounded half up (a, b ≥ 0). Exact for values below 2^53. */
export function roundDiv(a: number, b: number): number {
    return Math.floor((2 * a + b) / (2 * b));
}

/**
 * Cash amounts a customer is likely to hand over for a given total:
 * exact, then the next round 5.000 / 10.000 / 50.000 / 100.000.
 */
export function suggestedPayments(total: number): number[] {
    const steps = [2000, 5000, 10000, 20000, 50000, 100000];
    const options = new Set<number>([total]);

    for (const step of steps) {
        const rounded = Math.ceil(total / step) * step;

        if (rounded > total) {
            options.add(rounded);
        }
    }

    return [...options].sort((a, b) => a - b).slice(0, 5);
}

/** Compact rupiah for chart axes: 1.250.000 → "1,25 jt", 15.000 → "15 rb". */
export function formatRupiahShort(amount: number): string {
    const abs = Math.abs(amount);
    const sign = amount < 0 ? '-' : '';
    const fmt = (n: number) =>
        n.toLocaleString('id-ID', { maximumFractionDigits: 2 });

    if (abs >= 1_000_000_000) {
        return `${sign}${fmt(abs / 1_000_000_000)} M`;
    }

    if (abs >= 1_000_000) {
        return `${sign}${fmt(abs / 1_000_000)} jt`;
    }

    if (abs >= 1_000) {
        return `${sign}${fmt(abs / 1_000)} rb`;
    }

    return `${sign}${abs}`;
}
