import { Head, Link } from '@inertiajs/react';
import { Plus } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
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
import stockDisposals from '@/routes/stock-disposals';
import type { Paginated } from '@/types';

type Disposal = {
    id: number;
    number: string;
    createdAt: string | null;
    user: string;
    reason: string;
    itemsCount: number;
    totalValue: number;
};

/** Expired goods thrown away (DESIGN.md §19). */
export default function StockDisposalIndex({
    disposals,
}: {
    disposals: Paginated<Disposal>;
}) {
    return (
        <>
            <Head title="Barang dibuang" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Barang dibuang"
                    description="Barang kedaluwarsa (atau rusak) yang dikeluarkan dari batch-nya, dinilai dengan HPP rata-rata. Terpisah dari stok opname supaya kerugian kedaluwarsa terlihat."
                    actions={
                        <Button asChild>
                            <Link href={stockDisposals.create()}>
                                <Plus /> Buang barang
                            </Link>
                        </Button>
                    }
                />

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Alasan</TableHead>
                                <TableHead className="text-right">
                                    Batch
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai
                                </TableHead>
                                <TableHead>Oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {disposals.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada barang yang dibuang.
                                    </TableCell>
                                </TableRow>
                            )}
                            {disposals.data.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell>
                                        <Link
                                            href={stockDisposals.show(d.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {d.number}
                                        </Link>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(d.createdAt)}
                                    </TableCell>
                                    <TableCell className="max-w-xs truncate">
                                        {d.reason}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {d.itemsCount}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(d.totalValue)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {d.user}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={disposals} />
            </div>
        </>
    );
}

StockDisposalIndex.layout = {
    breadcrumbs: [{ title: 'Barang dibuang', href: stockDisposals.index() }],
};
