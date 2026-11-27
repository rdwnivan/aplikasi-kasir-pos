import { Head, Link } from '@inertiajs/react';
import { DateRangeFilter } from '@/components/date-range-filter';
import { PageHeader } from '@/components/page-header';
import { ExportButtons, ReportNav } from '@/components/reports/report-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import reports from '@/routes/reports';

type ShiftRow = {
    id: number;
    cashier: string;
    openedAt: string;
    closedAt: string | null;
    transactions: number;
    openingCash: number;
    cashSales: number;
    qrisSales: number;
    creditSales: number;
    payments: number;
    qrisPayments: number;
    refunds: number;
    expectedCash: number;
    countedCash: number | null;
    difference: number | null;
    /** Set only when someone other than the cashier closed it (DESIGN.md §13). */
    closedBy: string | null;
    closeReason: string | null;
};

type CashierRow = {
    cashier: string;
    shifts: number;
    transactions: number;
    cashSales: number;
    qrisSales: number;
    creditSales: number;
    payments: number;
    qrisPayments: number;
    refunds: number;
    difference: number | null;
};

function Diff({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">–</span>;
    }

    return (
        <span
            className={cn(
                'font-medium',
                value < 0 && 'text-destructive',
                value > 0 && 'text-amber-600 dark:text-amber-400',
            )}
        >
            {value === 0 ? 'pas' : formatRupiah(value)}
        </span>
    );
}

export default function ShiftsReport({
    filters,
    report,
}: {
    filters: { from: string; to: string };
    report: { shifts: ShiftRow[]; cashiers: CashierRow[] };
}) {
    return (
        <>
            <Head title="Rekap kasir & shift" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Laporan"
                    description="Shift dipilih berdasarkan waktu dibuka. Selisih = uang dihitung − kas seharusnya."
                />
                <ReportNav />
                <div className="flex flex-wrap items-center gap-2">
                    <DateRangeFilter
                        url={reports.shifts.url()}
                        from={filters.from}
                        to={filters.to}
                    />
                    <ExportButtons
                        excel={reports.export.shifts.url({ query: filters })}
                        pdf={reports.pdf.shifts.url({ query: filters })}
                    />
                </div>

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Per kasir</h2>
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kasir</TableHead>
                                    <TableHead className="text-right">
                                        Shift
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Trx
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Tunai
                                    </TableHead>
                                    <TableHead className="text-right">
                                        QRIS
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Kasbon
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pelunasan tunai
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pelunasan QRIS
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Retur
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Total selisih
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {report.cashiers.length === 0 && (
                                    <TableRow>
                                        <TableCell
                                            colSpan={10}
                                            className="py-8 text-center text-muted-foreground"
                                        >
                                            Tidak ada shift di rentang tanggal
                                            ini.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {report.cashiers.map((c) => (
                                    <TableRow key={c.cashier}>
                                        <TableCell className="font-medium">
                                            {c.cashier}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.shifts}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {c.transactions}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(c.cashSales)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(c.qrisSales)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {c.creditSales
                                                ? formatRupiah(c.creditSales)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {c.payments
                                                ? formatRupiah(c.payments)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {c.qrisPayments
                                                ? formatRupiah(c.qrisPayments)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {c.refunds
                                                ? formatRupiah(-c.refunds)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            <Diff value={c.difference} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>

                <section className="space-y-2">
                    <h2 className="text-sm font-medium">Per shift</h2>
                    <div className="rounded-xl border">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Kasir</TableHead>
                                    <TableHead>Dibuka</TableHead>
                                    <TableHead>Ditutup</TableHead>
                                    <TableHead className="text-right">
                                        Trx
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Modal
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Tunai
                                    </TableHead>
                                    <TableHead className="text-right">
                                        QRIS
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Kasbon
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pelunasan tunai
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Pelunasan QRIS
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Retur
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Kas seharusnya
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Dihitung
                                    </TableHead>
                                    <TableHead className="text-right">
                                        Selisih
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {report.shifts.map((s) => (
                                    <TableRow key={s.id}>
                                        <TableCell className="font-medium">
                                            {s.cashier}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {formatDateTime(s.openedAt)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-muted-foreground">
                                            {s.closedAt ? (
                                                <>
                                                    {formatDateTime(s.closedAt)}
                                                    {s.closedBy && (
                                                        <div
                                                            className="max-w-56 text-xs whitespace-normal"
                                                            title={
                                                                s.closeReason ??
                                                                undefined
                                                            }
                                                        >
                                                            oleh {s.closedBy}
                                                            {s.closeReason &&
                                                                `: ${s.closeReason}`}
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                <div className="flex items-center gap-2">
                                                    <Badge variant="outline">
                                                        Berjalan
                                                    </Badge>
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        className="h-7"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={reports.shifts.close.edit(
                                                                s.id,
                                                            )}
                                                        >
                                                            Tutup shift
                                                        </Link>
                                                    </Button>
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {s.transactions}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(s.openingCash)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(s.cashSales)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(s.qrisSales)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {s.creditSales
                                                ? formatRupiah(s.creditSales)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {s.payments
                                                ? formatRupiah(s.payments)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {s.qrisPayments
                                                ? formatRupiah(s.qrisPayments)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {s.refunds
                                                ? formatRupiah(-s.refunds)
                                                : '–'}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {formatRupiah(s.expectedCash)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            {s.countedCash === null
                                                ? '–'
                                                : formatRupiah(s.countedCash)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap tabular-nums">
                                            <Diff value={s.difference} />
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                </section>
            </div>
        </>
    );
}

ShiftsReport.layout = {
    breadcrumbs: [{ title: 'Laporan', href: reports.sales() }],
};
