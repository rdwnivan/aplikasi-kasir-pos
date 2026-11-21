import { Head, Link } from '@inertiajs/react';
import { DailyBarChart } from '@/components/charts/daily-bar-chart';
import { LowStockCard } from '@/components/dashboard/low-stock-card';
import type { LowStockItem } from '@/components/dashboard/low-stock-card';
import { StatCard } from '@/components/reports/report-nav';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatQty } from '@/lib/format';
import { formatRupiah, formatRupiahShort } from '@/lib/money';
import { dashboard } from '@/routes';
import goodsReceipts from '@/routes/goods-receipts';
import products from '@/routes/products';
import reports from '@/routes/reports';
import stockOpnames from '@/routes/stock-opnames';

type Overview = {
    range: { from: string; to: string };
    today: { gross: number; transactions: number; grossProfit: number };
    totals: {
        gross: number;
        transactions: number;
        netSales: number;
        grossProfit: number;
        margin: number;
        averageTicket: number;
    };
    daily: {
        date: string;
        gross: number;
        grossProfit: number;
        transactions: number;
    }[];
    topProducts: {
        productId: number;
        name: string;
        baseUnit: string;
        netQty: number;
        revenue: number;
    }[];
    inventoryValue: number;
};

type Props = {
    lowStock: { count: number; items: LowStockItem[] } | null;
    overview: Overview | null;
};

export default function Dashboard({ lowStock, overview }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                {overview ? (
                    <OwnerOverview overview={overview} />
                ) : (
                    <div className="flex flex-wrap gap-2">
                        <Button asChild>
                            <Link href={goodsReceipts.create()}>
                                Terima barang
                            </Link>
                        </Button>
                        <Button variant="outline" asChild>
                            <Link href={stockOpnames.create()}>
                                Mulai stok opname
                            </Link>
                        </Button>
                    </div>
                )}
                {lowStock && (
                    <div className="grid gap-4 lg:grid-cols-2">
                        <LowStockCard
                            count={lowStock.count}
                            items={lowStock.items}
                        />
                    </div>
                )}
            </div>
        </>
    );
}

function OwnerOverview({ overview: o }: { overview: Overview }) {
    const topMax = o.topProducts[0]?.netQty ?? 0;

    return (
        <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <StatCard
                    label="Penjualan hari ini"
                    value={formatRupiah(o.today.gross)}
                    hint={`${o.today.transactions} transaksi`}
                />
                <StatCard
                    label="Penjualan 30 hari"
                    value={formatRupiah(o.totals.gross)}
                    hint={`${o.totals.transactions} transaksi · rata-rata ${formatRupiah(o.totals.averageTicket)}`}
                />
                <StatCard
                    label="Laba kotor 30 hari"
                    value={formatRupiah(o.totals.grossProfit)}
                    hint={`margin ${o.totals.margin.toLocaleString('id-ID')}% dari penjualan bersih`}
                    tone={o.totals.grossProfit < 0 ? 'negative' : 'default'}
                />
                <StatCard
                    label="Nilai persediaan"
                    value={formatRupiah(o.inventoryValue)}
                    hint="stok × HPP rata-rata"
                />
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Omzet harian</CardTitle>
                        <CardDescription>
                            30 hari terakhir, termasuk PPN
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DailyBarChart
                            label="Omzet harian 30 hari terakhir"
                            data={o.daily.map((d) => ({
                                date: d.date,
                                value: d.gross,
                                note: `${d.transactions} transaksi`,
                            }))}
                            format={formatRupiah}
                            formatAxis={formatRupiahShort}
                        />
                    </CardContent>
                </Card>
                <Card>
                    <CardHeader>
                        <CardTitle>Laba kotor harian</CardTitle>
                        <CardDescription>
                            Penjualan bersih (tanpa PPN, setelah retur) − HPP
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <DailyBarChart
                            label="Laba kotor harian 30 hari terakhir"
                            data={o.daily.map((d) => ({
                                date: d.date,
                                value: d.grossProfit,
                            }))}
                            format={formatRupiah}
                            formatAxis={formatRupiahShort}
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader className="flex flex-row items-start justify-between gap-3">
                    <div className="space-y-1.5">
                        <CardTitle>Produk terlaris</CardTitle>
                        <CardDescription>
                            30 hari terakhir, jumlah bersih setelah retur
                        </CardDescription>
                    </div>
                    <Button size="sm" variant="outline" asChild>
                        <Link href={reports.products()}>Laporan lengkap</Link>
                    </Button>
                </CardHeader>
                <CardContent>
                    {o.topProducts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            Belum ada penjualan 30 hari terakhir.
                        </p>
                    ) : (
                        <ul className="space-y-2.5">
                            {o.topProducts.map((p) => (
                                <li
                                    key={p.productId}
                                    className="grid grid-cols-[minmax(0,12rem)_1fr_auto] items-center gap-3 text-sm"
                                >
                                    <Link
                                        href={products.stockCard(p.productId)}
                                        className="truncate hover:underline"
                                    >
                                        {p.name}
                                    </Link>
                                    <div className="h-2 overflow-hidden rounded-full bg-muted">
                                        <div
                                            className="h-full rounded-full bg-chart-2"
                                            style={{
                                                width: `${topMax > 0 ? Math.max(0, (p.netQty / topMax) * 100) : 0}%`,
                                            }}
                                        />
                                    </div>
                                    <span className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(p.netQty)} {p.baseUnit}
                                        <span className="ml-2 text-muted-foreground">
                                            {formatRupiah(p.revenue)}
                                        </span>
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </CardContent>
            </Card>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
