import { Head, Link } from '@inertiajs/react';
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
import { formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import products from '@/routes/products';
import reports from '@/routes/reports';

type Row = {
    productId: number;
    sku: string;
    name: string;
    category: string;
    baseUnit: string;
    stock: number;
    avgCost: number;
    value: number;
};

export default function InventoryReport({
    report,
    asOf,
}: {
    report: {
        rows: Row[];
        categories: { name: string; value: number; products: number }[];
        total: number;
        units: number;
    };
    asOf: string;
}) {
    return (
        <>
            <Head title="Nilai persediaan" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Laporan"
                    description={`Stok × HPP rata-rata saat ini (${formatDateTime(asOf)}). Produk aktif saja.`}
                />
                <ReportNav />

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Nilai persediaan"
                        value={formatRupiah(report.total)}
                    />
                    <StatCard
                        label="Produk aktif"
                        value={String(report.units)}
                    />
                    <StatCard
                        label="Kategori terbesar"
                        value={report.categories[0]?.name ?? '–'}
                        hint={
                            report.categories[0]
                                ? formatRupiah(report.categories[0].value)
                                : undefined
                        }
                    />
                </div>

                <div className="rounded-xl border p-4">
                    <h2 className="mb-3 text-sm font-medium">Per kategori</h2>
                    <ul className="space-y-2">
                        {report.categories.map((c) => (
                            <li
                                key={c.name}
                                className="grid grid-cols-[10rem_1fr_8rem] items-center gap-3 text-sm"
                            >
                                <span className="truncate">{c.name}</span>
                                <div className="h-2 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className="h-full rounded-full bg-primary"
                                        style={{
                                            width: `${report.total > 0 ? (c.value / report.total) * 100 : 0}%`,
                                        }}
                                    />
                                </div>
                                <span className="text-right tabular-nums">
                                    {formatRupiah(c.value)}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead>Kategori</TableHead>
                                <TableHead className="text-right">
                                    Stok
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP rata-rata
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.rows.map((r) => (
                                <TableRow key={r.productId}>
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
                                    <TableCell className="text-muted-foreground">
                                        {r.category}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(r.stock)} {r.baseUnit}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {r.avgCost.toLocaleString('id-ID', {
                                            style: 'currency',
                                            currency: 'IDR',
                                            maximumFractionDigits: 2,
                                        })}
                                    </TableCell>
                                    <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                        {formatRupiah(r.value)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4}>Total</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatRupiah(report.total)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </>
    );
}

InventoryReport.layout = {
    breadcrumbs: [{ title: 'Laporan', href: reports.sales() }],
};
