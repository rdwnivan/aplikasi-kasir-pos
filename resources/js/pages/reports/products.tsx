import { Head, Link } from '@inertiajs/react';
import { DateRangeFilter } from '@/components/date-range-filter';
import { PageHeader } from '@/components/page-header';
import { ExportButton, ReportNav } from '@/components/reports/report-nav';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import products from '@/routes/products';
import reports from '@/routes/reports';

export type TopProductRow = {
    productId: number;
    sku: string;
    name: string;
    baseUnit: string;
    soldQty: number;
    returnedQty: number;
    netQty: number;
    revenue: number;
    netSales: number;
    cogs: number;
    profit: number;
    margin: number;
};

export default function ProductsReport({
    filters,
    rows,
}: {
    filters: { from: string; to: string };
    rows: TopProductRow[];
}) {
    const top = rows[0]?.netQty ?? 0;

    return (
        <>
            <Head title="Produk terlaris" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Laporan"
                    description="Diurutkan dari jumlah terjual (satuan dasar) dikurangi retur."
                />
                <ReportNav />
                <div className="flex flex-wrap items-center gap-2">
                    <DateRangeFilter
                        url={reports.products.url()}
                        from={filters.from}
                        to={filters.to}
                    />
                    <ExportButton
                        href={reports.export.products.url({ query: filters })}
                    />
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">#</TableHead>
                                <TableHead>Produk</TableHead>
                                <TableHead className="w-1/4">
                                    Terjual bersih
                                </TableHead>
                                <TableHead className="text-right">
                                    Retur
                                </TableHead>
                                <TableHead className="text-right">
                                    Omzet
                                </TableHead>
                                <TableHead className="text-right">
                                    Bersih
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP
                                </TableHead>
                                <TableHead className="text-right">
                                    Laba
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada penjualan di rentang tanggal
                                        ini.
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((r, i) => (
                                <TableRow key={r.productId}>
                                    <TableCell className="text-muted-foreground tabular-nums">
                                        {i + 1}
                                    </TableCell>
                                    <TableCell>
                                        <Link
                                            href={products.stockCard(
                                                r.productId,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {r.name}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {r.sku}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-2">
                                            <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                                <div
                                                    className="h-full rounded-full bg-primary"
                                                    style={{
                                                        width: `${top > 0 ? Math.max(0, (r.netQty / top) * 100) : 0}%`,
                                                    }}
                                                />
                                            </div>
                                            <span className="w-20 text-right text-sm whitespace-nowrap tabular-nums">
                                                {formatQty(r.netQty)}{' '}
                                                {r.baseUnit}
                                            </span>
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {r.returnedQty > 0
                                            ? formatQty(r.returnedQty)
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(r.revenue)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(r.netSales)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(r.cogs)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        <div
                                            className={cn(
                                                'font-medium',
                                                r.profit < 0 &&
                                                    'text-destructive',
                                            )}
                                        >
                                            {formatRupiah(r.profit)}
                                        </div>
                                        <div className="text-xs text-muted-foreground">
                                            {`${r.margin.toLocaleString('id-ID')}%`}
                                        </div>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
                <p className="text-xs text-muted-foreground">
                    Omzet = yang dibayar pelanggan untuk produk ini (bagian dari
                    total transaksi setelah diskon, termasuk PPN) dikurangi
                    refund. Bersih = omzet tanpa PPN. Laba = bersih − HPP, sama
                    seperti laporan penjualan &amp; laba (HPP dibulatkan per
                    produk, jadi totalnya bisa selisih beberapa rupiah).
                </p>
            </div>
        </>
    );
}

ProductsReport.layout = {
    breadcrumbs: [{ title: 'Laporan', href: reports.sales() }],
};
