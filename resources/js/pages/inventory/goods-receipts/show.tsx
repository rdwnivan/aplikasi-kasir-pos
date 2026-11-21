import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import goodsReceipts from '@/routes/goods-receipts';
import products from '@/routes/products';

type Receipt = {
    id: number;
    number: string;
    receivedAt: string;
    createdAt: string | null;
    supplier: string | null;
    user: string;
    note: string | null;
    total: number;
    items: {
        id: number;
        productId: number;
        product: string;
        sku: string;
        unit: string;
        baseUnit: string;
        qty: number;
        conversion: number;
        unitPrice: number;
        subtotal: number;
    }[];
};

export default function GoodsReceiptShow({ receipt }: { receipt: Receipt }) {
    return (
        <>
            <Head title={receipt.number} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Penerimaan ${receipt.number}`}
                    description={`Dicatat ${formatDateTime(receipt.createdAt)} oleh ${receipt.user}`}
                />

                <Card className="py-4">
                    <CardContent className="grid gap-4 px-4 text-sm sm:grid-cols-3">
                        <Info
                            label="Tanggal terima"
                            value={formatDate(receipt.receivedAt)}
                        />
                        <Info
                            label="Supplier"
                            value={receipt.supplier ?? '–'}
                        />
                        <Info label="Catatan" value={receipt.note ?? '–'} />
                    </CardContent>
                </Card>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Jumlah
                                </TableHead>
                                <TableHead className="text-right">
                                    Harga beli
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP / satuan dasar
                                </TableHead>
                                <TableHead className="text-right">
                                    Subtotal
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipt.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell>
                                        <Link
                                            href={products.stockCard(
                                                item.productId,
                                            )}
                                            className="font-medium hover:underline"
                                        >
                                            {item.product}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {item.sku}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(item.qty)} {item.unit}
                                        {item.conversion > 1 && (
                                            <div className="text-xs text-muted-foreground">
                                                ={' '}
                                                {formatQty(
                                                    item.qty * item.conversion,
                                                )}{' '}
                                                {item.baseUnit}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.unitPrice)} /{' '}
                                        {item.unit}
                                    </TableCell>
                                    <TableCell className="text-right text-muted-foreground tabular-nums">
                                        {formatRupiah(
                                            Math.round(
                                                item.unitPrice /
                                                    item.conversion,
                                            ),
                                        )}{' '}
                                        / {item.baseUnit}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.subtotal)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={4} className="text-right">
                                    Total
                                </TableCell>
                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                    {formatRupiah(receipt.total)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </>
    );
}

function Info({ label, value }: { label: string; value: string }) {
    return (
        <div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="font-medium">{value}</p>
        </div>
    );
}

GoodsReceiptShow.layout = {
    breadcrumbs: [
        { title: 'Penerimaan barang', href: goodsReceipts.index() },
        { title: 'Detail', href: '#' },
    ],
};
