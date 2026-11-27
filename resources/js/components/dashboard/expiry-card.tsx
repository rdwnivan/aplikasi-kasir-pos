import { Link } from '@inertiajs/react';
import { CalendarClock } from 'lucide-react';
import { daysLabel } from '@/components/inventory/expiry';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatDate, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import products from '@/routes/products';
import reports from '@/routes/reports';
import stockDisposals from '@/routes/stock-disposals';
import type { BatchStatus } from '@/types';

type Totals = { count: number; qty: number; value: number };

export type ExpirySummary = {
    warnDays: number;
    expired: Totals;
    soon: Totals;
    undated: Totals;
    items: {
        batchId: number;
        productId: number;
        product: string;
        label: string;
        expiresOn: string | null;
        days: number | null;
        qty: number;
        baseUnit: string;
        status: BatchStatus;
    }[];
};

/** Batches that have expired or will within the warning window (DESIGN.md §19). */
export function ExpiryCard({
    warnDays,
    expired,
    soon,
    undated,
    items,
}: ExpirySummary) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <CalendarClock className="size-4 text-muted-foreground" />
                    Hampir kedaluwarsa
                </CardTitle>
                <CardDescription>
                    {expired.count + soon.count > 0
                        ? `Batch yang sudah lewat atau kedaluwarsa dalam ${warnDays} hari.`
                        : `Tidak ada batch yang kedaluwarsa dalam ${warnDays} hari.`}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <dl className="grid grid-cols-3 gap-3 text-sm">
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Kedaluwarsa
                        </dt>
                        <dd
                            className={cn(
                                'text-lg font-semibold tabular-nums',
                                expired.count > 0 && 'text-destructive',
                            )}
                        >
                            {expired.count}
                        </dd>
                        {expired.value > 0 && (
                            <dd className="text-xs text-muted-foreground tabular-nums">
                                {formatRupiah(expired.value)}
                            </dd>
                        )}
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            ≤ {warnDays} hari
                        </dt>
                        <dd className="text-lg font-semibold tabular-nums">
                            {soon.count}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-xs text-muted-foreground">
                            Tanpa tanggal
                        </dt>
                        <dd className="text-lg font-semibold tabular-nums">
                            {undated.count}
                        </dd>
                    </div>
                </dl>
                {items.length > 0 && (
                    <ul className="divide-y rounded-lg border text-sm">
                        {items.map((i) => (
                            <li
                                key={i.batchId}
                                className="flex items-center gap-3 px-3 py-2"
                            >
                                <div className="min-w-0 flex-1">
                                    <Link
                                        href={products.stockCard(i.productId, {
                                            query: { batch: i.batchId },
                                        })}
                                        className="block truncate font-medium hover:underline"
                                    >
                                        {i.product}
                                    </Link>
                                    <span
                                        className={cn(
                                            'text-xs',
                                            i.status === 'expired'
                                                ? 'text-destructive'
                                                : 'text-amber-600 dark:text-amber-400',
                                        )}
                                    >
                                        {formatDate(i.expiresOn)} ·{' '}
                                        {daysLabel(i.days)}
                                    </span>
                                </div>
                                <span className="shrink-0 text-right whitespace-nowrap tabular-nums">
                                    {formatQty(i.qty)} {i.baseUnit}
                                </span>
                            </li>
                        ))}
                    </ul>
                )}
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Link
                        href={reports.expiry()}
                        className="text-sm text-muted-foreground hover:underline"
                    >
                        Lihat laporan kedaluwarsa →
                    </Link>
                    {expired.count > 0 && (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={stockDisposals.create({
                                    query: { kedaluwarsa: 1 },
                                })}
                            >
                                Buang yang kedaluwarsa
                            </Link>
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
