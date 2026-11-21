import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import products from '@/routes/products';
import type { Paginated } from '@/types';

type Movement = {
    id: number;
    at: string;
    type: string;
    typeLabel: string;
    qty: number;
    unitCost: number;
    stockAfter: number;
    avgCostAfter: number;
    user: string | null;
    note: string | null;
    reference: { label: string; url: string | null } | null;
};

type Product = {
    id: number;
    sku: string;
    name: string;
    baseUnit: string;
    stock: number;
    minStock: number;
    avgCost: number;
    archived: boolean;
};

/** HPP is stored to 4 decimals; show cents only when they exist. */
function formatCost(value: number): string {
    return Number.isInteger(value)
        ? formatRupiah(value)
        : `Rp ${value.toLocaleString('id-ID', { maximumFractionDigits: 2 })}`;
}

export default function StockCard({
    product,
    movements,
}: {
    product: Product;
    movements: Paginated<Movement>;
}) {
    return (
        <>
            <Head title={`Kartu stok ${product.name}`} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Kartu stok: ${product.name}`}
                    description={
                        <>
                            {product.sku}
                            {product.archived && ' · diarsipkan'}
                        </>
                    }
                />

                <div className="grid gap-3 sm:grid-cols-3">
                    <Stat
                        label="Stok sekarang"
                        value={`${formatQty(product.stock)} ${product.baseUnit}`}
                        warn={product.stock <= product.minStock}
                    />
                    <Stat
                        label="Stok minimum"
                        value={`${formatQty(product.minStock)} ${product.baseUnit}`}
                    />
                    <Stat
                        label={`HPP rata-rata / ${product.baseUnit}`}
                        value={formatCost(product.avgCost)}
                    />
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Jenis</TableHead>
                                <TableHead>Dokumen</TableHead>
                                <TableHead className="text-right">
                                    Masuk
                                </TableHead>
                                <TableHead className="text-right">
                                    Keluar
                                </TableHead>
                                <TableHead className="text-right">
                                    Saldo
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP baris
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP rata-rata
                                </TableHead>
                                <TableHead>Oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {movements.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={9}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada pergerakan stok. Stok awal
                                        dimasukkan lewat penerimaan barang.
                                    </TableCell>
                                </TableRow>
                            )}
                            {movements.data.map((m) => (
                                <TableRow key={m.id}>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(m.at)}
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {m.typeLabel}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {m.reference?.url ? (
                                            <Link
                                                href={m.reference.url}
                                                className="underline-offset-2 hover:underline"
                                            >
                                                {m.reference.label}
                                            </Link>
                                        ) : (
                                            (m.reference?.label ??
                                            m.note ??
                                            '–')
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right text-emerald-600 tabular-nums dark:text-emerald-400">
                                        {m.qty > 0 ? formatQty(m.qty) : ''}
                                    </TableCell>
                                    <TableCell className="text-right text-destructive tabular-nums">
                                        {m.qty < 0 ? formatQty(-m.qty) : ''}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatQty(m.stockAfter)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatCost(m.unitCost)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatCost(m.avgCostAfter)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {m.user ?? '–'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={movements} />
            </div>
        </>
    );
}

function Stat({
    label,
    value,
    warn = false,
}: {
    label: string;
    value: string;
    warn?: boolean;
}) {
    return (
        <Card className="py-4">
            <CardContent className="px-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p
                    className={cn(
                        'text-lg font-semibold tabular-nums',
                        warn && 'text-destructive',
                    )}
                >
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

StockCard.layout = {
    breadcrumbs: [
        { title: 'Produk', href: products.index() },
        { title: 'Kartu stok', href: '#' },
    ],
};
