import { Head, Link, router } from '@inertiajs/react';
import { PackagePlus } from 'lucide-react';
import { DateRangeFilter } from '@/components/date-range-filter';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import goodsReceipts from '@/routes/goods-receipts';
import type { Option, Paginated } from '@/types';

type ReceiptRow = {
    id: number;
    number: string;
    receivedAt: string;
    supplier: string | null;
    purchaseOrder: string | null;
    user: string;
    itemsCount: number;
    total: number;
};

export default function GoodsReceiptIndex({
    receipts,
    filters,
    suppliers,
}: {
    receipts: Paginated<ReceiptRow>;
    filters: {
        from: string;
        to: string;
        supplier: number | null;
        source: 'po' | 'langsung' | null;
    };
    suppliers: Option[];
}) {
    return (
        <>
            <Head title="Penerimaan barang" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Penerimaan barang"
                    description="Barang masuk dari supplier. Setiap penerimaan menambah stok dan menghitung ulang HPP rata-rata."
                    actions={
                        <Button asChild>
                            <Link href={goodsReceipts.create()}>
                                <PackagePlus /> Terima barang
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <DateRangeFilter
                        url={goodsReceipts.index.url()}
                        from={filters.from}
                        to={filters.to}
                        extra={{
                            supplier: filters.supplier ?? undefined,
                            asal: filters.source ?? undefined,
                        }}
                    />
                    <Select
                        value={
                            filters.supplier ? String(filters.supplier) : 'all'
                        }
                        onValueChange={(v) =>
                            router.get(
                                goodsReceipts.index.url(),
                                {
                                    from: filters.from,
                                    to: filters.to,
                                    supplier: v === 'all' ? undefined : v,
                                    asal: filters.source ?? undefined,
                                },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <SelectTrigger className="w-full sm:w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua supplier</SelectItem>
                            {suppliers.map((s) => (
                                <SelectItem key={s.id} value={String(s.id)}>
                                    {s.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={filters.source ?? 'all'}
                        onValueChange={(v) =>
                            router.get(
                                goodsReceipts.index.url(),
                                {
                                    from: filters.from,
                                    to: filters.to,
                                    supplier: filters.supplier ?? undefined,
                                    asal: v === 'all' ? undefined : v,
                                },
                                { preserveState: true, replace: true },
                            )
                        }
                    >
                        <SelectTrigger
                            className="w-full sm:w-44"
                            aria-label="Asal penerimaan"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua asal</SelectItem>
                            <SelectItem value="po">Dari PO</SelectItem>
                            <SelectItem value="langsung">Tanpa PO</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Tanggal</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead className="text-right">
                                    Item
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead>Diterima oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {receipts.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada penerimaan di rentang tanggal
                                        ini.
                                    </TableCell>
                                </TableRow>
                            )}
                            {receipts.data.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={goodsReceipts.show(r.id)}
                                            className="hover:underline"
                                        >
                                            {r.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell>
                                        {formatDate(r.receivedAt)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {r.supplier ?? '–'}
                                        {r.purchaseOrder && (
                                            <div className="text-xs">
                                                {r.purchaseOrder}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {r.itemsCount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(r.total)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {r.user}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={receipts} />
            </div>
        </>
    );
}

GoodsReceiptIndex.layout = {
    breadcrumbs: [{ title: 'Penerimaan barang', href: goodsReceipts.index() }],
};
