import { Head, Link } from '@inertiajs/react';
import { BatchStatusBadge, daysLabel } from '@/components/inventory/expiry';
import { PageHeader } from '@/components/page-header';
import {
    ExportButtons,
    ReportNav,
    StatCard,
} from '@/components/reports/report-nav';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useRoles } from '@/hooks/use-roles';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import products from '@/routes/products';
import reports from '@/routes/reports';
import stockDisposals from '@/routes/stock-disposals';
import type { BatchStatus } from '@/types';

type Row = {
    batchId: number;
    productId: number;
    sku: string;
    product: string;
    code: string | null;
    expiresOn: string | null;
    days: number | null;
    qty: number;
    baseUnit: string;
    avgCost: number;
    value: number;
    status: BatchStatus;
    statusLabel: string;
};

type Totals = { count: number; qty: number; value: number };

const filters: { status: BatchStatus | null; label: string }[] = [
    { status: null, label: 'Semua' },
    { status: 'expired', label: 'Kedaluwarsa' },
    { status: 'soon', label: 'Hampir' },
    { status: 'ok', label: 'Aman' },
    { status: 'undated', label: 'Tanpa tanggal' },
];

/** Batches with stock by expiry status (DESIGN.md §19); owner and warehouse staff. */
export default function ExpiryReport({
    report,
    filters: current,
    asOf,
}: {
    report: {
        rows: Row[];
        totals: Record<BatchStatus | 'all', Totals>;
        warnDays: number;
    };
    filters: { status: BatchStatus | null };
    asOf: string;
}) {
    const { isOwner } = useRoles();
    const query = current.status ? { status: current.status } : {};
    const shownValue = report.rows.reduce((s, r) => s + r.value, 0);

    return (
        <>
            <Head title="Laporan kedaluwarsa" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={isOwner ? 'Laporan' : 'Kedaluwarsa'}
                    description={`Batch bersisa dari produk yang dilacak kedaluwarsanya, per ${formatDateTime(asOf)}. Hampir kedaluwarsa = ${report.warnDays} hari lagi atau kurang. Nilai = sisa × HPP rata-rata.`}
                />
                {isOwner && <ReportNav />}

                <div className="flex flex-wrap items-center gap-2">
                    <div className="flex flex-wrap gap-1">
                        {filters.map((f) => (
                            <Button
                                key={f.label}
                                size="sm"
                                variant={
                                    current.status === f.status
                                        ? 'default'
                                        : 'outline'
                                }
                                asChild
                            >
                                <Link
                                    href={reports.expiry({
                                        query: f.status
                                            ? { status: f.status }
                                            : {},
                                    })}
                                    preserveScroll
                                >
                                    {f.label}
                                </Link>
                            </Button>
                        ))}
                    </div>
                    <ExportButtons
                        excel={reports.export.expiry.url({ query })}
                        pdf={reports.pdf.expiry.url({ query })}
                    />
                </div>

                <div className="grid gap-3 sm:grid-cols-4">
                    <StatCard
                        label="Kedaluwarsa"
                        value={formatRupiah(report.totals.expired.value)}
                        hint={`${report.totals.expired.count} batch · ${formatQty(report.totals.expired.qty)} unit`}
                        tone={
                            report.totals.expired.count > 0
                                ? 'negative'
                                : 'default'
                        }
                    />
                    <StatCard
                        label={`≤ ${report.warnDays} hari lagi`}
                        value={formatRupiah(report.totals.soon.value)}
                        hint={`${report.totals.soon.count} batch · ${formatQty(report.totals.soon.qty)} unit`}
                    />
                    <StatCard
                        label="Tanpa tanggal"
                        value={formatRupiah(report.totals.undated.value)}
                        hint={`${report.totals.undated.count} batch · beri tanggal lewat stok opname`}
                    />
                    <StatCard
                        label="Semua batch"
                        value={formatRupiah(report.totals.all.value)}
                        hint={`${report.totals.all.count} batch`}
                    />
                </div>

                {report.totals.expired.count > 0 && (
                    <div>
                        <Button variant="outline" asChild>
                            <Link
                                href={stockDisposals.create({
                                    query: { kedaluwarsa: 1 },
                                })}
                            >
                                Buang yang kedaluwarsa
                            </Link>
                        </Button>
                    </div>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead>Kedaluwarsa</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="text-right">
                                    Sisa
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada batch untuk filter ini. Produk
                                        dilacak lewat sakelar "Lacak tanggal
                                        kedaluwarsa" di form produk.
                                    </TableCell>
                                </TableRow>
                            )}
                            {report.rows.map((r) => (
                                <TableRow key={r.batchId}>
                                    <TableCell>
                                        <Link
                                            href={products.stockCard(
                                                r.productId,
                                                { query: { batch: r.batchId } },
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {r.product}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {r.sku}
                                            {r.code && ` · batch ${r.code}`}
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {r.expiresOn
                                            ? formatDate(r.expiresOn)
                                            : '–'}
                                        <div
                                            className={cn(
                                                'text-xs text-muted-foreground',
                                                r.status === 'expired' &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {daysLabel(r.days)}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <BatchStatusBadge status={r.status} />
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(r.qty)} {r.baseUnit}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(r.value)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        {report.rows.length > 0 && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={4}>Total</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(shownValue)}
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>
            </div>
        </>
    );
}

ExpiryReport.layout = {
    breadcrumbs: [{ title: 'Kedaluwarsa', href: reports.expiry() }],
};
