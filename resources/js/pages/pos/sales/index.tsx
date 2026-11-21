import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { DateRangeFilter } from '@/components/date-range-filter';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
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
import { formatDateTime } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { paymentLabels, saleStatusLabels } from '@/lib/sales';
import pos from '@/routes/pos';
import type { Option, Paginated } from '@/types';

type SaleRow = {
    id: number;
    number: string | null;
    status: string;
    at: string | null;
    cashier: string;
    itemsCount: number;
    total: number;
    paymentMethod: string | null;
    returnedTotal: number;
};

type Filters = {
    q: string;
    from?: string;
    to?: string;
    cashier?: number | null;
    method?: string | null;
    shift?: number | null;
};

type ShiftOption = { id: number; openedAt: string; closedAt: string | null };

export default function SaleIndex({
    sales,
    filters,
    isOwner,
    cashiers = [],
    shifts = [],
}: {
    sales: Paginated<SaleRow>;
    filters: Filters;
    isOwner: boolean;
    cashiers?: Option[];
    shifts?: ShiftOption[];
}) {
    const [q, setQ] = useState(filters.q);
    const url = pos.sales.index.url();
    const go = (next: Partial<Filters>) =>
        router.get(
            url,
            Object.fromEntries(
                Object.entries({ ...filters, q, ...next }).filter(
                    ([, v]) => v !== null && v !== undefined && v !== '',
                ),
            ),
            { preserveState: true, preserveScroll: true, replace: true },
        );

    return (
        <>
            <Head title="Riwayat transaksi" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Riwayat transaksi"
                    description={
                        isOwner
                            ? 'Semua transaksi. Buka detail untuk cetak ulang struk atau retur.'
                            : 'Transaksi di shift Anda. Buka detail untuk cetak ulang struk atau retur.'
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            go({});
                        }}
                    >
                        <Input
                            className="w-full sm:w-52"
                            placeholder="Cari nomor INV…"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    {isOwner && filters.from && filters.to && (
                        <>
                            <DateRangeFilter
                                url={url}
                                from={filters.from}
                                to={filters.to}
                                extra={{
                                    cashier: filters.cashier ?? undefined,
                                    method: filters.method ?? undefined,
                                    q: q || undefined,
                                }}
                            />
                            <Select
                                value={
                                    filters.cashier
                                        ? String(filters.cashier)
                                        : 'all'
                                }
                                onValueChange={(v) =>
                                    go({
                                        cashier: v === 'all' ? null : Number(v),
                                    })
                                }
                            >
                                <SelectTrigger className="w-full sm:w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Semua kasir
                                    </SelectItem>
                                    {cashiers.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Select
                                value={filters.method ?? 'all'}
                                onValueChange={(v) =>
                                    go({ method: v === 'all' ? null : v })
                                }
                            >
                                <SelectTrigger className="w-full sm:w-36">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        Semua metode
                                    </SelectItem>
                                    {Object.entries(paymentLabels).map(
                                        ([k, label]) => (
                                            <SelectItem key={k} value={k}>
                                                {label}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </>
                    )}
                    {!isOwner && shifts.length > 0 && (
                        <Select
                            value={
                                filters.shift
                                    ? String(filters.shift)
                                    : undefined
                            }
                            onValueChange={(v) => go({ shift: Number(v) })}
                        >
                            <SelectTrigger className="w-full sm:w-72">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {shifts.map((s) => (
                                    <SelectItem key={s.id} value={String(s.id)}>
                                        Shift {formatDateTime(s.openedAt)}
                                        {s.closedAt ? '' : ' (berjalan)'}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    )}
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Waktu</TableHead>
                                {isOwner && <TableHead>Kasir</TableHead>}
                                <TableHead>Bayar</TableHead>
                                <TableHead className="text-right">
                                    Item
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="text-right">
                                    Retur
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sales.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={7}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada transaksi.
                                    </TableCell>
                                </TableRow>
                            )}
                            {sales.data.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell className="font-medium whitespace-nowrap">
                                        <Link
                                            href={pos.sales.show(s.id)}
                                            className="hover:underline"
                                        >
                                            {s.number ?? `#${s.id}`}
                                        </Link>
                                        {s.status !== 'completed' && (
                                            <Badge
                                                variant="outline"
                                                className="ml-2"
                                            >
                                                {saleStatusLabels[s.status] ??
                                                    s.status}
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {formatDateTime(s.at)}
                                    </TableCell>
                                    {isOwner && (
                                        <TableCell className="text-muted-foreground">
                                            {s.cashier}
                                        </TableCell>
                                    )}
                                    <TableCell>
                                        {s.paymentMethod
                                            ? (paymentLabels[s.paymentMethod] ??
                                              s.paymentMethod)
                                            : '–'}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {s.itemsCount}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(s.total)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap text-destructive tabular-nums">
                                        {s.returnedTotal > 0
                                            ? formatRupiah(-s.returnedTotal)
                                            : ''}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={sales} />
            </div>
        </>
    );
}

SaleIndex.layout = {
    breadcrumbs: [{ title: 'Riwayat transaksi', href: pos.sales.index() }],
};
