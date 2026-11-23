import { Head, Link, router } from '@inertiajs/react';
import { ClipboardPlus } from 'lucide-react';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { LateBadge, PoStatus } from '@/components/purchasing/po-status';
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
import purchaseOrders from '@/routes/purchase-orders';
import type {
    Option,
    Paginated,
    PurchaseOrderStatus,
    StatusOption,
} from '@/types';

type OrderRow = {
    id: number;
    number: string | null;
    status: PurchaseOrderStatus;
    supplier: string;
    user: string;
    createdAt: string | null;
    orderedAt: string | null;
    expectedAt: string | null;
    isLate: boolean;
    itemsCount: number;
    total: number;
    receivedValue: number;
};

type Filters = { status: PurchaseOrderStatus | null; supplier: number | null };

export default function PurchaseOrderIndex({
    orders,
    filters,
    statuses,
    suppliers,
}: {
    orders: Paginated<OrderRow>;
    filters: Filters;
    statuses: StatusOption[];
    suppliers: Option[];
}) {
    const filter = (
        patch: Partial<Record<keyof Filters, string | undefined>>,
    ) =>
        router.get(
            purchaseOrders.index.url(),
            {
                status: filters.status ?? undefined,
                supplier: filters.supplier ?? undefined,
                ...patch,
            },
            { preserveState: true, replace: true },
        );

    return (
        <>
            <Head title="Pesanan pembelian" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Pesanan pembelian"
                    description="Barang yang dipesan ke supplier dan sisa yang masih ditunggu. Stok baru bertambah saat barangnya diterima."
                    actions={
                        <Button asChild>
                            <Link href={purchaseOrders.create()}>
                                <ClipboardPlus /> Buat pesanan
                            </Link>
                        </Button>
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Select
                        value={filters.status ?? 'all'}
                        onValueChange={(v) =>
                            filter({ status: v === 'all' ? undefined : v })
                        }
                    >
                        <SelectTrigger
                            className="w-full sm:w-52"
                            aria-label="Status"
                        >
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua status</SelectItem>
                            {statuses.map((s) => (
                                <SelectItem key={s.value} value={s.value}>
                                    {s.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Select
                        value={
                            filters.supplier ? String(filters.supplier) : 'all'
                        }
                        onValueChange={(v) =>
                            filter({ supplier: v === 'all' ? undefined : v })
                        }
                    >
                        <SelectTrigger
                            className="w-full sm:w-56"
                            aria-label="Supplier"
                        >
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
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Nomor</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Supplier</TableHead>
                                <TableHead>Perkiraan tiba</TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="w-40">Diterima</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada pesanan pembelian.
                                    </TableCell>
                                </TableRow>
                            )}
                            {orders.data.map((o) => {
                                const percent =
                                    o.total > 0
                                        ? Math.round(
                                              (o.receivedValue / o.total) * 100,
                                          )
                                        : 0;

                                return (
                                    <TableRow key={o.id}>
                                        <TableCell className="font-medium">
                                            <Link
                                                href={purchaseOrders.show(o.id)}
                                                className="hover:underline"
                                            >
                                                {o.number ?? `Draft #${o.id}`}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDate(
                                                    o.orderedAt ?? o.createdAt,
                                                )}{' '}
                                                · {o.itemsCount} barang
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <PoStatus
                                                status={o.status}
                                                statuses={statuses}
                                            />
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {o.supplier}
                                        </TableCell>
                                        <TableCell>
                                            <span className="inline-flex flex-wrap items-center gap-2">
                                                {formatDate(o.expectedAt)}
                                                {o.isLate && <LateBadge />}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatRupiah(o.total)}
                                        </TableCell>
                                        <TableCell>
                                            {o.status === 'draft' ? (
                                                <span className="text-muted-foreground">
                                                    –
                                                </span>
                                            ) : (
                                                <div className="space-y-1">
                                                    <div
                                                        className="h-1.5 overflow-hidden rounded-full bg-muted"
                                                        role="progressbar"
                                                        aria-valuenow={percent}
                                                        aria-valuemin={0}
                                                        aria-valuemax={100}
                                                        aria-label="Nilai yang sudah diterima"
                                                    >
                                                        <div
                                                            className="h-full rounded-full bg-primary"
                                                            style={{
                                                                width: `${percent}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <p className="text-xs text-muted-foreground tabular-nums">
                                                        {percent}% ·{' '}
                                                        {formatRupiah(
                                                            o.receivedValue,
                                                        )}
                                                    </p>
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={orders} />
            </div>
        </>
    );
}

PurchaseOrderIndex.layout = {
    breadcrumbs: [{ title: 'Pesanan pembelian', href: purchaseOrders.index() }],
};
