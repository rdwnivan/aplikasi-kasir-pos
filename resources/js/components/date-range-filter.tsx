import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

type Props = {
    url: string;
    from: string;
    to: string;
    /** Other query params to keep (e.g. a supplier filter). */
    extra?: Record<string, string | number | null | undefined>;
};

function shift(date: Date, days: number): string {
    const copy = new Date(date);
    copy.setDate(copy.getDate() + days);

    return toDateString(copy);
}

/** Local calendar date (the browser is assumed to be in Asia/Jakarta like the store). */
function toDateString(date: Date): string {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');

    return `${y}-${m}-${d}`;
}

/** From/to date inputs plus quick presets; navigates with ?from=&to=. */
export function DateRangeFilter({ url, from, to, extra = {} }: Props) {
    const [range, setRange] = useState({ from, to });

    const go = (next: { from: string; to: string }) => {
        setRange(next);
        router.get(
            url,
            { ...extra, ...next },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    const today = new Date();
    const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
    const presets = [
        {
            label: 'Hari ini',
            from: toDateString(today),
            to: toDateString(today),
        },
        { label: '7 hari', from: shift(today, -6), to: toDateString(today) },
        { label: '30 hari', from: shift(today, -29), to: toDateString(today) },
        {
            label: 'Bulan ini',
            from: toDateString(firstOfMonth),
            to: toDateString(today),
        },
    ];

    return (
        <form
            className="flex flex-wrap items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                go(range);
            }}
        >
            <Input
                type="date"
                aria-label="Dari tanggal"
                className="w-full sm:w-40"
                value={range.from}
                max={range.to}
                onChange={(e) => setRange({ ...range, from: e.target.value })}
            />
            <span className="text-sm text-muted-foreground">s.d.</span>
            <Input
                type="date"
                aria-label="Sampai tanggal"
                className="w-full sm:w-40"
                value={range.to}
                min={range.from}
                onChange={(e) => setRange({ ...range, to: e.target.value })}
            />
            <Button type="submit" variant="secondary">
                Terapkan
            </Button>
            <div className="flex flex-wrap gap-1">
                {presets.map((p) => (
                    <Button
                        key={p.label}
                        type="button"
                        size="sm"
                        variant={
                            p.from === from && p.to === to ? 'default' : 'ghost'
                        }
                        onClick={() => go({ from: p.from, to: p.to })}
                    >
                        {p.label}
                    </Button>
                ))}
            </div>
        </form>
    );
}
