import { Head, router } from '@inertiajs/react';
import { DateRangeFilter } from '@/components/date-range-filter';
import { PageHeader } from '@/components/page-header';
import { ReportNav, StatCard } from '@/components/reports/report-nav';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDate } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import reports from '@/routes/reports';

type Row = {
    period: string;
    transactions: number;
    gross: number;
    cash: number;
    qris: number;
    refunds: number;
    tax: number;
    netSales: number;
    cogs: number;
    grossProfit: number;
    margin: number;
};

type Totals = Omit<Row, 'period'> & { averageTicket: number };

const monthFormat = new Intl.DateTimeFormat('id-ID', {
    month: 'long',
    year: 'numeric',
});

function periodLabel(period: string): string {
    return period.length === 7
        ? monthFormat.format(new Date(`${period}-01T00:00:00+07:00`))
        : formatDate(period);
}

export default function SalesReport({
    filters,
    report,
}: {
    filters: { from: string; to: string; group: 'day' | 'month' };
    report: { rows: Row[]; totals: Totals };
}) {
    const t = report.totals;
    const url = reports.sales.url();

    return (
        <>
            <Head title="Laporan penjualan" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Laporan"
                    description="Laba kotor dihitung dari penjualan tanpa PPN (PPN bukan pendapatan toko), dikurangi retur dan HPP."
                />
                <ReportNav />

                <div className="flex flex-wrap items-center gap-2">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={filters.group}
                        onValueChange={(v) =>
                            v &&
                            router.get(
                                url,
                                { group: v },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <ToggleGroupItem value="day" className="px-3">
                            Harian
                        </ToggleGroupItem>
                        <ToggleGroupItem value="month" className="px-3">
                            Bulanan
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <DateRangeFilter
                        url={url}
                        from={filters.from}
                        to={filters.to}
                        extra={{ group: filters.group }}
                    />
                </div>

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <StatCard
                        label="Penjualan (termasuk PPN)"
                        value={formatRupiah(t.gross)}
                        hint={`${t.transactions} transaksi · rata-rata ${formatRupiah(t.averageTicket)}`}
                    />
                    <StatCard
                        label="Penjualan bersih"
                        value={formatRupiah(t.netSales)}
                        hint={`setelah retur ${formatRupiah(t.refunds)} dan PPN ${formatRupiah(t.tax)}`}
                    />
                    <StatCard label="HPP" value={formatRupiah(t.cogs)} />
                    <StatCard
                        label="Laba kotor"
                        value={formatRupiah(t.grossProfit)}
                        hint={`margin ${t.margin.toLocaleString('id-ID')}%`}
                        tone={t.grossProfit < 0 ? 'negative' : 'positive'}
                    />
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>
                                    {filters.group === 'month'
                                        ? 'Bulan'
                                        : 'Tanggal'}
                                </TableHead>
                                <TableHead className="text-right">
                                    Trx
                                </TableHead>
                                <TableHead className="text-right">
                                    Penjualan
                                </TableHead>
                                <TableHead className="text-right">
                                    Tunai
                                </TableHead>
                                <TableHead className="text-right">
                                    QRIS
                                </TableHead>
                                <TableHead className="text-right">
                                    Retur
                                </TableHead>
                                <TableHead className="text-right">
                                    PPN
                                </TableHead>
                                <TableHead className="text-right">
                                    Bersih
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP
                                </TableHead>
                                <TableHead className="text-right">
                                    Laba kotor
                                </TableHead>
                                <TableHead className="text-right">
                                    Margin
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {[...report.rows].reverse().map((r) => (
                                <TableRow
                                    key={r.period}
                                    className={cn(
                                        r.transactions === 0 &&
                                            r.refunds === 0 &&
                                            'text-muted-foreground',
                                    )}
                                >
                                    <TableCell className="whitespace-nowrap">
                                        {periodLabel(r.period)}
                                    </TableCell>
                                    <Num value={r.transactions} plain />
                                    <Num value={r.gross} />
                                    <Num value={r.cash} />
                                    <Num value={r.qris} />
                                    <Num value={-r.refunds} />
                                    <Num value={r.tax} />
                                    <Num value={r.netSales} />
                                    <Num value={r.cogs} />
                                    <Num value={r.grossProfit} strong />
                                    <TableCell className="text-right tabular-nums">
                                        {r.netSales === 0
                                            ? '–'
                                            : `${r.margin.toLocaleString('id-ID')}%`}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell>Total</TableCell>
                                <Num value={t.transactions} plain />
                                <Num value={t.gross} />
                                <Num value={t.cash} />
                                <Num value={t.qris} />
                                <Num value={-t.refunds} />
                                <Num value={t.tax} />
                                <Num value={t.netSales} />
                                <Num value={t.cogs} />
                                <Num value={t.grossProfit} strong />
                                <TableCell className="text-right tabular-nums">
                                    {t.margin.toLocaleString('id-ID')}%
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </>
    );
}

function Num({
    value,
    plain = false,
    strong = false,
}: {
    value: number;
    plain?: boolean;
    strong?: boolean;
}) {
    return (
        <TableCell
            className={cn(
                'text-right whitespace-nowrap tabular-nums',
                strong && 'font-medium',
                value < 0 && 'text-destructive',
            )}
        >
            {value === 0
                ? '–'
                : plain
                  ? value.toLocaleString('id-ID')
                  : formatRupiah(value)}
        </TableCell>
    );
}

SalesReport.layout = {
    breadcrumbs: [{ title: 'Laporan', href: reports.sales() }],
};
