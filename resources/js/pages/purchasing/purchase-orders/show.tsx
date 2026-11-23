import { Head, Link, router, useForm } from '@inertiajs/react';
import { Ban, PackageCheck, Pencil, Send, Trash2, XCircle } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { LateBadge, PoStatus } from '@/components/purchasing/po-status';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { toastFirstError } from '@/lib/errors';
import { formatDate, formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import goodsReceipts from '@/routes/goods-receipts';
import products from '@/routes/products';
import purchaseOrders from '@/routes/purchase-orders';
import type { PurchaseOrderStatus, StatusOption } from '@/types';

type Order = {
    id: number;
    number: string | null;
    status: PurchaseOrderStatus;
    supplier: string;
    user: string;
    note: string | null;
    closeReason: string | null;
    createdAt: string | null;
    orderedAt: string | null;
    finishedAt: string | null;
    expectedAt: string | null;
    isLate: boolean;
    total: number;
    receivedValue: number;
    items: {
        id: number;
        productId: number;
        product: string;
        sku: string;
        unit: string;
        qty: number;
        received: number;
        remaining: number;
        unitPrice: number;
        subtotal: number;
    }[];
    receipts: {
        id: number;
        number: string;
        receivedAt: string;
        user: string;
        total: number;
    }[];
};

type Can = {
    edit: boolean;
    order: boolean;
    receive: boolean;
    close: boolean;
    cancel: boolean;
};

export default function PurchaseOrderShow({
    order,
    statuses,
    can,
}: {
    order: Order;
    statuses: StatusOption[];
    can: Can;
}) {
    const [confirm, setConfirm] = useState<'order' | 'delete' | null>(null);
    const [ending, setEnding] = useState<'close' | 'cancel' | null>(null);
    const [processing, setProcessing] = useState(false);
    const title = order.number ?? `Draft #${order.id}`;

    const run = (action: 'order' | 'delete') => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: toastFirstError,
            onFinish: () => {
                setProcessing(false);
                setConfirm(null);
            },
        };

        if (action === 'order') {
            router.post(purchaseOrders.order.url(order.id), {}, options);
        } else {
            router.delete(purchaseOrders.destroy.url(order.id), options);
        }
    };

    return (
        <>
            <Head title={title} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Pesanan ${title}`}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <PoStatus
                                status={order.status}
                                statuses={statuses}
                            />
                            {order.isLate && <LateBadge />}
                            Dibuat {formatDateTime(order.createdAt)} oleh{' '}
                            {order.user}
                        </span>
                    }
                    actions={
                        <>
                            {can.edit && (
                                <Button variant="outline" asChild>
                                    <Link href={purchaseOrders.edit(order.id)}>
                                        <Pencil /> Ubah
                                    </Link>
                                </Button>
                            )}
                            {can.edit && (
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirm('delete')}
                                >
                                    <Trash2 /> Hapus
                                </Button>
                            )}
                            {can.order && (
                                <Button onClick={() => setConfirm('order')}>
                                    <Send /> Pesan
                                </Button>
                            )}
                            {can.cancel && (
                                <Button
                                    variant="outline"
                                    onClick={() => setEnding('cancel')}
                                >
                                    <Ban /> Batalkan
                                </Button>
                            )}
                            {can.close && (
                                <Button
                                    variant="outline"
                                    onClick={() => setEnding('close')}
                                >
                                    <XCircle /> Tutup sisa
                                </Button>
                            )}
                            {can.receive && (
                                <Button asChild>
                                    <Link
                                        href={purchaseOrders.receive(order.id)}
                                    >
                                        <PackageCheck /> Terima barang
                                    </Link>
                                </Button>
                            )}
                        </>
                    }
                />

                <Card className="py-4">
                    <CardContent className="grid gap-4 px-4 text-sm sm:grid-cols-4">
                        <Info label="Supplier" value={order.supplier} />
                        <Info
                            label="Dipesan"
                            value={formatDateTime(order.orderedAt)}
                        />
                        <Info
                            label="Perkiraan tiba"
                            value={formatDate(order.expectedAt)}
                        />
                        <Info label="Catatan" value={order.note ?? '–'} />
                        {order.closeReason && (
                            <div className="sm:col-span-4">
                                <Info
                                    label={
                                        order.status === 'cancelled'
                                            ? `Dibatalkan ${formatDateTime(order.finishedAt)}`
                                            : `Ditutup ${formatDateTime(order.finishedAt)}`
                                    }
                                    value={order.closeReason}
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Dipesan
                                </TableHead>
                                <TableHead className="text-right">
                                    Diterima
                                </TableHead>
                                <TableHead className="text-right">
                                    Sisa
                                </TableHead>
                                <TableHead className="text-right">
                                    Harga
                                </TableHead>
                                <TableHead className="text-right">
                                    Subtotal
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {order.items.map((item) => (
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
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(item.received)}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right whitespace-nowrap tabular-nums',
                                            item.remaining > 0 &&
                                                order.status !== 'draft' &&
                                                'font-medium',
                                            item.remaining === 0 &&
                                                'text-muted-foreground',
                                        )}
                                    >
                                        {formatQty(item.remaining)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.unitPrice)} /{' '}
                                        {item.unit}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.subtotal)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5} className="text-right">
                                    Total pesanan
                                </TableCell>
                                <TableCell className="text-right whitespace-nowrap tabular-nums">
                                    {formatRupiah(order.total)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>

                {order.status !== 'draft' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Penerimaan</CardTitle>
                        </CardHeader>
                        <CardContent className="text-sm">
                            {order.receipts.length === 0 ? (
                                <p className="text-muted-foreground">
                                    Belum ada barang yang diterima.
                                </p>
                            ) : (
                                <ul className="divide-y">
                                    {order.receipts.map((r) => (
                                        <li
                                            key={r.id}
                                            className="flex flex-wrap items-center justify-between gap-2 py-2"
                                        >
                                            <span>
                                                <Link
                                                    href={goodsReceipts.show(
                                                        r.id,
                                                    )}
                                                    className="font-medium hover:underline"
                                                >
                                                    {r.number}
                                                </Link>{' '}
                                                <span className="text-muted-foreground">
                                                    · {formatDate(r.receivedAt)}{' '}
                                                    · {r.user}
                                                </span>
                                            </span>
                                            <span className="tabular-nums">
                                                {formatRupiah(r.total)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <p className="mt-2 text-xs text-muted-foreground">
                                Nilai penerimaan memakai harga di faktur, jadi
                                bisa berbeda dari harga pesanan.
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>

            <ConfirmDialog
                open={confirm === 'order'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Pesan ke supplier?"
                description={`Pesanan mendapat nomor dan barisnya dikunci. Total ${formatRupiah(order.total)} ke ${order.supplier}.`}
                confirmLabel="Pesan"
                processing={processing}
                onConfirm={() => run('order')}
            />
            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title="Hapus draft ini?"
                description="Draft pesanan dihapus permanen."
                confirmLabel="Hapus"
                destructive
                processing={processing}
                onConfirm={() => run('delete')}
            />
            <EndOrderDialog
                orderId={order.id}
                mode={ending}
                onClose={() => setEnding(null)}
            />
        </>
    );
}

function EndOrderDialog({
    orderId,
    mode,
    onClose,
}: {
    orderId: number;
    mode: 'close' | 'cancel' | null;
    onClose: () => void;
}) {
    const form = useForm({ reason: '' });
    const cancelling = mode === 'cancel';

    return (
        <Dialog
            open={mode !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                <form
                    className="space-y-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.submit(
                            cancelling
                                ? purchaseOrders.cancel(orderId)
                                : purchaseOrders.close(orderId),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    form.reset();
                                    onClose();
                                },
                                onError: (errors) => {
                                    if (!errors.reason) {
                                        toastFirstError(errors);
                                    }
                                },
                            },
                        );
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {cancelling
                                ? 'Batalkan pesanan?'
                                : 'Tutup sisa pesanan?'}
                        </DialogTitle>
                        <DialogDescription>
                            {cancelling
                                ? 'Belum ada barang yang diterima. Pesanan tidak bisa menerima barang lagi setelah dibatalkan.'
                                : 'Sisa yang belum datang tidak akan diterima lagi. Barang yang sudah diterima tetap tercatat.'}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-1.5">
                        <Label htmlFor="reason">Alasan</Label>
                        <Textarea
                            id="reason"
                            autoFocus
                            value={form.data.reason}
                            onChange={(e) =>
                                form.setData('reason', e.target.value)
                            }
                            placeholder={
                                cancelling
                                    ? 'mis. supplier tidak bisa mengirim'
                                    : 'mis. stok supplier habis'
                            }
                        />
                        <InputError message={form.errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={onClose}
                        >
                            Kembali
                        </Button>
                        <Button
                            type="submit"
                            variant="destructive"
                            disabled={form.processing}
                        >
                            {cancelling ? 'Batalkan pesanan' : 'Tutup sisa'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
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

PurchaseOrderShow.layout = {
    breadcrumbs: [
        { title: 'Pesanan pembelian', href: purchaseOrders.index() },
        { title: 'Detail', href: '#' },
    ],
};
