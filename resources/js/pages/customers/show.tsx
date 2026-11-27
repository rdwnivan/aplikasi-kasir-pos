import { Head, Link, router, useForm } from '@inertiajs/react';
import { Eraser, HandCoins, Pencil, Power, Printer } from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { CustomerFormDialog } from '@/components/customers/customer-form-dialog';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { StatCard } from '@/components/reports/report-nav';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useOpenKey } from '@/hooks/use-open-key';
import { toastFirstError } from '@/lib/errors';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import customers from '@/routes/customers';
import pos from '@/routes/pos';

type Customer = {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    note: string | null;
    balance: number;
    creditLimit: number;
    available: number;
    active: boolean;
    createdBy: string | null;
    createdAt: string | null;
};

type OpenSale = {
    id: number;
    number: string;
    at: string;
    total: number;
    credit: number;
    owed: number;
};

type Entry = {
    id: number;
    at: string;
    type: 'sale' | 'payment' | 'sale_return' | 'write_off';
    label: string;
    saleId: number;
    saleNumber: string;
    document: {
        kind: 'sale' | 'payment' | 'return';
        id: number;
        number: string;
        /** Payments only: 'cash' or 'qris' (DESIGN.md §18). */
        method?: 'cash' | 'qris';
    } | null;
    note: string | null;
    user: string | null;
    amount: number;
    balanceAfter: number;
};

type Props = {
    customer: Customer;
    openSales: OpenSale[];
    entries: Entry[];
    /** A QRIS pelunasan waiting: other payments and write-offs are refused until it ends. */
    waitingQris: { number: string; amount: number; cashier: string } | null;
    canManage: boolean;
};

/** Kartu piutang: every change to one customer's kasbon, newest first (DESIGN.md §17). */
export default function CustomerShow({
    customer,
    openSales,
    entries,
    waitingQris,
    canManage,
}: Props) {
    const [editing, setEditing] = useState(false);
    const [toggling, setToggling] = useState(false);
    const [writeOff, setWriteOff] = useState<OpenSale | 'all' | null>(null);

    return (
        <>
            <Head title={customer.name} />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={customer.name}
                    description={[
                        customer.phone,
                        customer.address,
                        customer.note,
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {customer.balance > 0 && (
                                <Button asChild>
                                    <Link
                                        href={pos.payments.create({
                                            query: { pelanggan: customer.id },
                                        })}
                                    >
                                        <HandCoins /> Terima pelunasan
                                    </Link>
                                </Button>
                            )}
                            {canManage && (
                                <>
                                    <Button
                                        variant="outline"
                                        onClick={() => setEditing(true)}
                                    >
                                        <Pencil /> Ubah
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => setToggling(true)}
                                    >
                                        <Power />
                                        {customer.active
                                            ? 'Nonaktifkan'
                                            : 'Aktifkan lagi'}
                                    </Button>
                                    {customer.balance > 0 && (
                                        <Button
                                            variant="outline"
                                            onClick={() => setWriteOff('all')}
                                        >
                                            <Eraser /> Hapus utang
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    }
                />
                {!customer.active && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        Pelanggan ini nonaktif: tidak bisa kasbon lagi, tetapi
                        utangnya tetap bisa dilunasi.
                    </p>
                )}
                {waitingQris && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                        QRIS pelunasan {waitingQris.number} (
                        {formatRupiah(waitingQris.amount)}, kasir{' '}
                        {waitingQris.cashier}) menunggu pembayaran. Sampai QR
                        itu lunas, dibatalkan, atau kedaluwarsa, pelunasan lain,
                        hapus utang, dan retur kasbon pelanggan ini ditolak.
                    </p>
                )}

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Utang"
                        value={formatRupiah(customer.balance)}
                        tone={customer.balance > 0 ? 'negative' : 'default'}
                    />
                    <StatCard
                        label="Batas kasbon"
                        value={formatRupiah(customer.creditLimit)}
                    />
                    <StatCard
                        label="Sisa batas"
                        value={formatRupiah(customer.available)}
                        hint={
                            customer.createdBy
                                ? `Ditambahkan oleh ${customer.createdBy}, ${formatDate(customer.createdAt)}`
                                : undefined
                        }
                    />
                </div>

                <section className="rounded-xl border">
                    <h2 className="border-b px-4 py-3 text-sm font-medium">
                        Kasbon belum lunas
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Transaksi</TableHead>
                                <TableHead className="text-right">
                                    Total belanja
                                </TableHead>
                                <TableHead className="text-right">
                                    Kasbon
                                </TableHead>
                                <TableHead className="text-right">
                                    Sisa
                                </TableHead>
                                {canManage && <TableHead className="w-0" />}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {openSales.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={5}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Semua kasbon sudah lunas.
                                    </TableCell>
                                </TableRow>
                            )}
                            {openSales.map((s) => (
                                <TableRow key={s.id}>
                                    <TableCell>
                                        <Link
                                            href={pos.sales.show(s.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {s.number}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {formatDateTime(s.at)}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(s.total)}
                                    </TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(s.credit)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium tabular-nums">
                                        {formatRupiah(s.owed)}
                                    </TableCell>
                                    {canManage && (
                                        <TableCell>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() => setWriteOff(s)}
                                            >
                                                Hapus
                                            </Button>
                                        </TableCell>
                                    )}
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>

                <section className="rounded-xl border">
                    <h2 className="border-b px-4 py-3 text-sm font-medium">
                        Kartu piutang
                    </h2>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Waktu</TableHead>
                                <TableHead>Keterangan</TableHead>
                                <TableHead className="text-right">
                                    Mutasi
                                </TableHead>
                                <TableHead className="text-right">
                                    Saldo
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {entries.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-6 text-center text-muted-foreground"
                                    >
                                        Belum ada kasbon.
                                    </TableCell>
                                </TableRow>
                            )}
                            {entries.map((e) => (
                                <TableRow key={e.id}>
                                    <TableCell className="whitespace-nowrap">
                                        {formatDateTime(e.at)}
                                        <div className="text-xs text-muted-foreground">
                                            {e.user}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline">
                                            {e.label}
                                        </Badge>{' '}
                                        <EntryDocument entry={e} />
                                        {e.note && (
                                            <div className="text-xs text-muted-foreground">
                                                {e.note}
                                            </div>
                                        )}
                                    </TableCell>
                                    <TableCell
                                        className={cn(
                                            'text-right whitespace-nowrap tabular-nums',
                                            e.amount < 0
                                                ? 'text-emerald-600 dark:text-emerald-400'
                                                : '',
                                        )}
                                    >
                                        {e.amount > 0 ? '+' : ''}
                                        {formatRupiah(e.amount)}
                                    </TableCell>
                                    <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                        {formatRupiah(e.balanceAfter)}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </section>
            </div>

            <CustomerFormDialog
                customer={editing ? customer : null}
                defaultLimit={customer.creditLimit}
                onClose={() => setEditing(false)}
            />
            <ConfirmDialog
                open={toggling}
                onOpenChange={setToggling}
                title={
                    customer.active
                        ? `Nonaktifkan ${customer.name}?`
                        : `Aktifkan lagi ${customer.name}?`
                }
                description={
                    customer.active
                        ? 'Pelanggan nonaktif tidak bisa kasbon lagi. Utangnya tetap tercatat dan bisa dilunasi.'
                        : 'Pelanggan bisa kasbon lagi sampai batasnya.'
                }
                onConfirm={() =>
                    router.post(
                        customer.active
                            ? customers.deactivate.url(customer.id)
                            : customers.reactivate.url(customer.id),
                        {},
                        {
                            preserveScroll: true,
                            onError: toastFirstError,
                            onFinish: () => setToggling(false),
                        },
                    )
                }
            />
            <WriteOffDialog
                customer={customer}
                target={writeOff}
                onClose={() => setWriteOff(null)}
            />
        </>
    );
}

function EntryDocument({ entry }: { entry: Entry }) {
    const doc = entry.document;

    if (doc?.kind === 'payment') {
        return (
            <>
                <a
                    href={pos.payments.receipt.url(doc.id)}
                    target="_blank"
                    rel="noreferrer"
                    className="inline-flex items-center gap-1 hover:underline"
                >
                    {doc.number} <Printer className="size-3" />
                </a>{' '}
                <span className="text-xs text-muted-foreground">
                    {doc.method === 'qris' ? 'QRIS · ' : 'Tunai · '}
                    untuk {entry.saleNumber}
                </span>
            </>
        );
    }

    return (
        <>
            <Link
                href={pos.sales.show(entry.saleId)}
                className="hover:underline"
            >
                {entry.saleNumber}
            </Link>
            {doc?.kind === 'return' && (
                <span className="text-xs text-muted-foreground">
                    {' '}
                    · {doc.number}
                </span>
            )}
        </>
    );
}

function WriteOffDialog({
    customer,
    target,
    onClose,
}: {
    customer: Customer;
    target: OpenSale | 'all' | null;
    onClose: () => void;
}) {
    const key = useOpenKey(target !== null);

    return (
        <Dialog
            open={target !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-md">
                {target !== null && (
                    <WriteOffForm
                        key={key}
                        customer={customer}
                        target={target}
                        onDone={onClose}
                    />
                )}
            </DialogContent>
        </Dialog>
    );
}

function WriteOffForm({
    customer,
    target,
    onDone,
}: {
    customer: Customer;
    target: OpenSale | 'all';
    onDone: () => void;
}) {
    const form = useForm({
        reason: '',
        sale_id: target === 'all' ? null : target.id,
    });
    const amount = target === 'all' ? customer.balance : target.owed;

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(customers.writeOff.url(customer.id), {
                    preserveScroll: true,
                    onSuccess: onDone,
                });
            }}
        >
            <DialogHeader>
                <DialogTitle>Hapus utang {formatRupiah(amount)}?</DialogTitle>
                <DialogDescription>
                    {target === 'all'
                        ? `Seluruh sisa utang ${customer.name} dihapuskan.`
                        : `Sisa kasbon ${target.number} dihapuskan.`}{' '}
                    Dicatat di kartu piutang sebagai beban piutang tak tertagih;
                    laporan penjualan dan laba kotor tidak berubah.
                </DialogDescription>
            </DialogHeader>
            <div className="grid gap-2">
                <Label htmlFor="write-off-reason">Alasan</Label>
                <Textarea
                    id="write-off-reason"
                    autoFocus
                    placeholder="mis. Pindah kota, tidak bisa dihubungi"
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
                <InputError
                    message={
                        form.errors.reason ??
                        (form.errors as Record<string, string | undefined>).sale
                    }
                />
            </div>
            <DialogFooter>
                <Button
                    type="submit"
                    variant="destructive"
                    disabled={form.processing || form.data.reason.trim() === ''}
                >
                    Hapus utang
                </Button>
            </DialogFooter>
        </form>
    );
}

CustomerShow.layout = {
    breadcrumbs: [{ title: 'Pelanggan & kasbon', href: customers.index() }],
};
