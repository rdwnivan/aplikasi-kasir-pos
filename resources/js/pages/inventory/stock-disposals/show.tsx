import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
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
import stockDisposals from '@/routes/stock-disposals';

type Disposal = {
    id: number;
    number: string;
    createdAt: string | null;
    user: string;
    reason: string;
    totalValue: number;
    items: {
        id: number;
        productId: number;
        product: string;
        sku: string;
        baseUnit: string;
        batch: string;
        qty: number;
        unitCost: number;
        value: number;
    }[];
};

export default function StockDisposalShow({
    disposal,
}: {
    disposal: Disposal;
}) {
    return (
        <>
            <Head title={disposal.number} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Barang dibuang ${disposal.number}`}
                    description={`${formatDateTime(disposal.createdAt)} oleh ${disposal.user} · ${disposal.reason}`}
                />

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead>Batch</TableHead>
                                <TableHead className="text-right">
                                    Jumlah
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {disposal.items.map((i) => (
                                <TableRow key={i.id}>
                                    <TableCell>
                                        <Link
                                            href={products.stockCard(
                                                i.productId,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {i.product}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {i.sku}
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap">
                                        {i.batch}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(i.qty)} {i.baseUnit}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(Math.round(i.unitCost))}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(i.value)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4}>Total</TableCell>
                                <TableCell className="text-right tabular-nums">
                                    {formatRupiah(disposal.totalValue)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </>
    );
}

StockDisposalShow.layout = {
    breadcrumbs: [
        { title: 'Barang dibuang', href: stockDisposals.index() },
        { title: 'Detail', href: '#' },
    ],
};
