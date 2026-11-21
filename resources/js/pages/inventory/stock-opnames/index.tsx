import { Head, Link, router } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { OpnameStatus } from '@/components/inventory/opname-status';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateTime } from '@/lib/format';
import stockOpnames from '@/routes/stock-opnames';
import type { Paginated } from '@/types';

type OpnameRow = {
    id: number;
    number: string;
    status: 'draft' | 'posted';
    createdAt: string | null;
    postedAt: string | null;
    user: string;
    itemsCount: number;
    note: string | null;
};

export default function StockOpnameIndex({
    opnames,
    filters,
}: {
    opnames: Paginated<OpnameRow>;
    filters: { status: 'draft' | 'posted' | null };
}) {
    return (
        <>
            <Head title="Stok opname" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Stok opname"
                    description="Hitung fisik stok. Draft bisa diubah kapan saja; saat diposting, selisihnya dicatat ke kartu stok."
                    actions={
                        <Button asChild>
                            <Link href={stockOpnames.create()}>
                                <ClipboardList /> Mulai opname
                            </Link>
                        </Button>
                    }
                />

                <ToggleGroup
                    type="single"
                    variant="outline"
                    className="self-start"
                    value={filters.status ?? 'all'}
                    onValueChange={(v) =>
                        router.get(
                            stockOpnames.index.url(),
                            { status: v === 'all' || v === '' ? undefined : v },
                            { preserveState: true, replace: true },
                        )
                    }
                >
                    <ToggleGroupItem value="all" className="px-3">
                        Semua
                    </ToggleGroupItem>
                    <ToggleGroupItem value="draft" className="px-3">
                        Draft
                    </ToggleGroupItem>
                    <ToggleGroupItem value="posted" className="px-3">
                        Diposting
                    </ToggleGroupItem>
                </ToggleGroup>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Dibuat</TableHead>
                                <TableHead>Diposting</TableHead>
                                <TableHead className="text-right">
                                    Produk
                                </TableHead>
                                <TableHead>Oleh</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {opnames.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada stok opname.
                                    </TableCell>
                                </TableRow>
                            )}
                            {opnames.data.map((o) => (
                                <TableRow key={o.id}>
                                    <TableCell className="font-medium">
                                        <Link
                                            href={stockOpnames.show(o.id)}
                                            className="hover:underline"
                                        >
                                            {o.number}
                                        </Link>
                                        {o.note && (
                                            <div className="max-w-xs truncate text-xs font-normal text-muted-foreground">
                                                {o.note}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell>
                                        <OpnameStatus status={o.status} />
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(o.createdAt)}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {formatDateTime(o.postedAt)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {o.itemsCount}
                                    </TableCell>
                                    <TableCell className="text-muted-foreground">
                                        {o.user}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={opnames} />
            </div>
        </>
    );
}

StockOpnameIndex.layout = {
    breadcrumbs: [{ title: 'Stok opname', href: stockOpnames.index() }],
};
