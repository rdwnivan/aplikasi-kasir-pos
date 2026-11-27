import { Head, Link, useForm } from '@inertiajs/react';
import { Printer, Undo2 } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useOpenKey } from '@/hooks/use-open-key';
import { formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah, roundDiv } from '@/lib/money';
import { paymentLabels, saleStatusLabels } from '@/lib/sales';
import customers from '@/routes/customers';
import pos from '@/routes/pos';

type Item = {
    id: number;
    name: string;
    unit: string;
    qty: number;
    unitPrice: number;
    discountAmount: number;
    lineTotal: number;
    paidShare: number;
    returnedQty: number;
};

type Sale = {
    id: number;
    number: string | null;
    status: string;
    at: string | null;
    cashier: string;
    subtotal: number;
    discountAmount: number;
    taxAmount: number;
    pricesIncludeTax: boolean;
    total: number;
    paymentMethod: string | null;
    paidAmount: number | null;
    changeAmount: number | null;
    cashAmount: number;
    qrisAmount: number;
    /** Kasbon (DESIGN.md §17): what was left owing, and what is still owed now. */
    creditAmount: number;
    customer: { id: number; name: string } | null;
    owed: number;
    items: Item[];
    returns: {
        id: number;
        number: string;
        at: string | null;
        user: string;
        approver: string;
        total: number;
        creditAmount: number;
        reason: string | null;
        items: { name: string; unit: string; qty: number; amount: number }[];
    }[];
};

export default function SaleShow({
    sale,
    canReturn,
}: {
    sale: Sale;
    canReturn: boolean;
}) {
    const [returning, setReturning] = useState(false);
    const returnable = sale.items.some((i) => i.qty > i.returnedQty);
    const refunded = sale.returns.reduce((s, r) => s + r.total, 0);

    return (
        <>
            <Head title={sale.number ?? 'Transaksi'} />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Transaksi ${sale.number ?? `#${sale.id}`}`}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            {sale.status !== 'completed' && (
                                <Badge variant="outline">
                                    {saleStatusLabels[sale.status] ??
                                        sale.status}
                                </Badge>
                            )}
                            {formatDateTime(sale.at)} · kasir {sale.cashier}
                        </span>
                    }
                    actions={
                        sale.status === 'completed' && (
                            <>
                                <Button variant="outline" asChild>
                                    <a
                                        href={pos.receipts.show.url(sale.id, {
                                            query: { copy: 1, print: 1 },
                                        })}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <Printer /> Cetak ulang struk
                                    </a>
                                </Button>
                                {canReturn && returnable && (
                                    <Button onClick={() => setReturning(true)}>
                                        <Undo2 /> Retur
                                    </Button>
                                )}
                            </>
                        )
                    }
                />

                {sale.status === 'completed' && !canReturn && returnable && (
                    <p className="text-sm text-muted-foreground">
                        Buka shift dulu untuk memproses retur, karena uang
                        pengembalian diambil dari laci shift yang berjalan.
                    </p>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Barang</TableHead>
                                <TableHead className="text-right">
                                    Jumlah
                                </TableHead>
                                <TableHead className="text-right">
                                    Harga
                                </TableHead>
                                <TableHead className="text-right">
                                    Total
                                </TableHead>
                                <TableHead className="text-right">
                                    Diretur
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sale.items.map((item) => (
                                <TableRow key={item.id}>
                                    <TableCell className="font-medium">
                                        {item.name}
                                        {item.discountAmount > 0 && (
                                            <div className="text-xs font-normal text-muted-foreground">
                                                diskon{' '}
                                                {formatRupiah(
                                                    item.discountAmount,
                                                )}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatQty(item.qty)} {item.unit}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.unitPrice)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap tabular-nums">
                                        {formatRupiah(item.lineTotal)}
                                    </TableCell>
                                    <TableCell className="text-right whitespace-nowrap text-destructive tabular-nums">
                                        {item.returnedQty > 0
                                            ? `${formatQty(item.returnedQty)} ${item.unit}`
                                            : ''}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Card className="py-4">
                    <CardContent className="space-y-1.5 px-4 text-sm">
                        <Line label="Subtotal" value={sale.subtotal} />
                        {sale.discountAmount > 0 && (
                            <Line
                                label="Diskon transaksi"
                                value={-sale.discountAmount}
                            />
                        )}
                        {sale.taxAmount > 0 && (
                            <Line
                                label={
                                    sale.pricesIncludeTax
                                        ? 'PPN (sudah termasuk)'
                                        : 'PPN'
                                }
                                value={sale.taxAmount}
                                muted={sale.pricesIncludeTax}
                            />
                        )}
                        <Line label="Total" value={sale.total} strong />
                        {sale.paymentMethod === 'credit' ? (
                            <>
                                {sale.cashAmount > 0 && (
                                    <Line
                                        label="Uang muka tunai"
                                        value={sale.cashAmount}
                                    />
                                )}
                                <Line
                                    label={`Kasbon ${sale.customer?.name ?? ''}`.trim()}
                                    value={sale.creditAmount}
                                />
                                <Line
                                    label="Sisa utang transaksi ini"
                                    value={sale.owed}
                                    strong
                                />
                                {sale.customer && (
                                    <p className="text-right text-xs">
                                        <Link
                                            href={customers.show(
                                                sale.customer.id,
                                            )}
                                            className="text-muted-foreground hover:underline"
                                        >
                                            Kartu piutang {sale.customer.name}
                                        </Link>
                                    </p>
                                )}
                            </>
                        ) : sale.paymentMethod === 'split' ? (
                            <>
                                <Line
                                    label="Dibayar tunai"
                                    value={sale.cashAmount}
                                />
                                <Line
                                    label="Dibayar QRIS"
                                    value={sale.qrisAmount}
                                />
                            </>
                        ) : (
                            sale.paymentMethod && (
                                <Line
                                    label={`Dibayar (${paymentLabels[sale.paymentMethod] ?? sale.paymentMethod})`}
                                    value={sale.paidAmount ?? sale.total}
                                />
                            )
                        )}
                        {sale.changeAmount !== null &&
                            sale.changeAmount > 0 && (
                                <Line
                                    label="Kembali"
                                    value={sale.changeAmount}
                                />
                            )}
                        {refunded > 0 && (
                            <Line
                                label="Total retur"
                                value={-refunded}
                                danger
                            />
                        )}
                    </CardContent>
                </Card>

                {sale.returns.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Retur</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            {sale.returns.map((r) => (
                                <div
                                    key={r.id}
                                    className="rounded-lg border p-3 text-sm"
                                >
                                    <div className="flex flex-wrap justify-between gap-2">
                                        <span className="font-medium">
                                            {r.number}
                                        </span>
                                        <span className="font-semibold text-destructive tabular-nums">
                                            {formatRupiah(-r.total)}
                                        </span>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {formatDateTime(r.at)} · oleh {r.user} ·
                                        disetujui {r.approver}
                                        {r.reason && ` · ${r.reason}`}
                                    </p>
                                    {r.creditAmount > 0 && (
                                        <p className="text-xs">
                                            Mengurangi utang{' '}
                                            {formatRupiah(r.creditAmount)} ·
                                            tunai{' '}
                                            {formatRupiah(
                                                r.total - r.creditAmount,
                                            )}
                                        </p>
                                    )}
                                    <ul className="mt-2 space-y-0.5">
                                        {r.items.map((i, idx) => (
                                            <li
                                                key={idx}
                                                className="flex justify-between gap-2"
                                            >
                                                <span>
                                                    {formatQty(i.qty)} {i.unit}{' '}
                                                    {i.name}
                                                </span>
                                                <span className="tabular-nums">
                                                    {formatRupiah(i.amount)}
                                                </span>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                )}
            </div>

            <ReturnDialog
                sale={sale}
                open={returning}
                onOpenChange={setReturning}
            />
        </>
    );
}

function Line({
    label,
    value,
    strong = false,
    muted = false,
    danger = false,
}: {
    label: string;
    value: number;
    strong?: boolean;
    muted?: boolean;
    danger?: boolean;
}) {
    return (
        <div
            className={
                strong
                    ? 'flex justify-between border-t pt-1.5 text-base font-semibold'
                    : `flex justify-between ${muted ? 'text-muted-foreground' : ''} ${danger ? 'text-destructive' : ''}`
            }
        >
            <span>{label}</span>
            <span className="tabular-nums">{formatRupiah(value)}</span>
        </div>
    );
}

function ReturnDialog({
    sale,
    open,
    onOpenChange,
}: {
    sale: Sale;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const key = useOpenKey(open);

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-lg">
                <ReturnForm
                    key={key}
                    sale={sale}
                    onDone={() => onOpenChange(false)}
                />
            </DialogContent>
        </Dialog>
    );
}

function ReturnForm({ sale, onDone }: { sale: Sale; onDone: () => void }) {
    const form = useForm<{
        qty: Record<number, number>;
        owner_pin: string;
        reason: string;
    }>({ qty: {}, owner_pin: '', reason: '' });
    const errors = form.errors as Record<string, string | undefined>;

    // Preview only; the server computes the refund with the same rule.
    const estimate = sale.items.reduce((sum, item) => {
        const qty = form.data.qty[item.id] ?? 0;

        return qty > 0 ? sum + roundDiv(item.paidShare * qty, item.qty) : sum;
    }, 0);
    const chosen = Object.values(form.data.qty).some((q) => q > 0);
    // Kasbon: the return comes off what is still owed first; only the rest is cash.
    const offDebt = Math.min(estimate, sale.owed);
    const cashBack = estimate - offDebt;

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.transform((data) => ({
                    items: Object.entries(data.qty)
                        .filter(([, q]) => q > 0)
                        .map(([id, q]) => ({
                            sale_item_id: Number(id),
                            qty: q,
                        })),
                    owner_pin: data.owner_pin,
                    reason: data.reason,
                }));
                form.submit(pos.sales.returns.store(sale.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                    onError: () => form.setData('owner_pin', ''),
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Retur {sale.number}</DialogTitle>
                <DialogDescription>
                    Pilih barang yang dikembalikan. Stok kembali ke gudang dan
                    {sale.owed > 0
                        ? ' nilainya mengurangi sisa kasbon lebih dulu; hanya kelebihannya yang dikembalikan tunai dari laci shift ini.'
                        : ' uang dikembalikan tunai dari laci shift ini.'}
                </DialogDescription>
            </DialogHeader>

            <div className="max-h-72 space-y-2 overflow-y-auto">
                {sale.items.map((item) => {
                    const left = item.qty - item.returnedQty;

                    return (
                        <div
                            key={item.id}
                            className="flex items-center justify-between gap-3 rounded-lg border p-2"
                        >
                            <div className="min-w-0 text-sm">
                                <p className="truncate font-medium">
                                    {item.name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    terjual {formatQty(item.qty)} {item.unit}
                                    {item.returnedQty > 0 &&
                                        ` · sudah diretur ${formatQty(item.returnedQty)}`}
                                </p>
                                <InputError
                                    message={errors[`items.${item.id}`]}
                                />
                            </div>
                            <Input
                                type="number"
                                min={0}
                                max={left}
                                disabled={left === 0}
                                aria-label={`Jumlah retur ${item.name}`}
                                className="w-20"
                                value={form.data.qty[item.id] ?? 0}
                                onChange={(e) =>
                                    form.setData('qty', {
                                        ...form.data.qty,
                                        [item.id]: Math.max(
                                            0,
                                            Math.min(
                                                left,
                                                Number(e.target.value) || 0,
                                            ),
                                        ),
                                    })
                                }
                            />
                        </div>
                    );
                })}
            </div>
            <InputError message={errors.items ?? errors.sale ?? errors.shift} />

            <div className="grid gap-3 sm:grid-cols-2">
                <div className="grid gap-1.5">
                    <Label htmlFor="reason">Alasan</Label>
                    <Input
                        id="reason"
                        placeholder="mis. kemasan rusak"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                    />
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="owner_pin">PIN owner</Label>
                    <Input
                        id="owner_pin"
                        type="password"
                        inputMode="numeric"
                        autoComplete="off"
                        maxLength={6}
                        value={form.data.owner_pin}
                        onChange={(e) =>
                            form.setData(
                                'owner_pin',
                                e.target.value.replace(/\D/g, ''),
                            )
                        }
                    />
                    <InputError message={errors.owner_pin} />
                </div>
            </div>

            <DialogFooter className="items-center sm:justify-between">
                {sale.owed > 0 ? (
                    <p className="text-sm">
                        Mengurangi utang ±{' '}
                        <span className="font-semibold tabular-nums">
                            {formatRupiah(offDebt)}
                        </span>
                        <br />
                        Dikembalikan tunai ±{' '}
                        <span className="font-semibold tabular-nums">
                            {formatRupiah(cashBack)}
                        </span>
                    </p>
                ) : (
                    <p className="text-sm">
                        Uang kembali ±{' '}
                        <span className="font-semibold tabular-nums">
                            {formatRupiah(estimate)}
                        </span>
                    </p>
                )}
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        !chosen ||
                        form.data.owner_pin.length < 4
                    }
                >
                    Proses retur
                </Button>
            </DialogFooter>
        </form>
    );
}

SaleShow.layout = {
    breadcrumbs: [
        { title: 'Riwayat transaksi', href: pos.sales.index() },
        { title: 'Detail', href: '#' },
    ],
};
