import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, HandCoins, Printer, Search } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import pos from '@/routes/pos';
import type { ShiftSummary } from '@/types/pos';

type Debtor = {
    id: number;
    name: string;
    phone: string | null;
    balance: number;
    active: boolean;
    openSales: {
        id: number;
        number: string;
        at: string;
        ageDays: number;
        credit: number;
        owed: number;
    }[];
};

type LastPayment = {
    id: number;
    number: string;
    amount: number;
    customer: string;
    balance: number;
};

type Props = {
    shift: ShiftSummary;
    debtors: Debtor[];
    customerId: number | null;
    lastPayment: LastPayment | null;
};

/**
 * Pelunasan kasbon at the till: cash goes into this shift's drawer, oldest kasbon first
 * (DESIGN.md §17).
 */
export default function Payments({
    shift,
    debtors,
    customerId,
    lastPayment,
}: Props) {
    const [search, setSearch] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(customerId);
    const [done, setDone] = useState<LastPayment | null>(lastPayment);
    const [shownFor, setShownFor] = useState(lastPayment?.id ?? null);
    // A new payment arrives as a prop after the redirect: show it once.
    if ((lastPayment?.id ?? null) !== shownFor) {
        setShownFor(lastPayment?.id ?? null);
        setDone(lastPayment);
    }

    const selected = debtors.find((d) => d.id === selectedId) ?? null;
    const terms = search.toLowerCase().split(/\s+/).filter(Boolean);
    const digits = search.replace(/\D+/g, '');
    const matches = debtors.filter(
        (d) =>
            terms.every((t) => d.name.toLowerCase().includes(t)) ||
            (digits.length >= 3 && (d.phone ?? '').includes(digits)),
    );

    return (
        <>
            <Head title="Pelunasan kasbon" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Pelunasan kasbon"
                    description={`Uang tunai masuk ke laci shift Anda (kas seharusnya sekarang ${formatRupiah(shift.expectedCash)}${shift.paymentsCount > 0 ? `, termasuk ${shift.paymentsCount} pelunasan ${formatRupiah(shift.paymentsTotal)}` : ''}). Pembayaran menutup kasbon tertua lebih dulu.`}
                />

                <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
                    <section className="flex min-h-0 flex-col rounded-xl border">
                        <div className="relative border-b p-3">
                            <Search className="pointer-events-none absolute top-1/2 left-6 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                className="pl-9"
                                autoFocus
                                placeholder="Cari pelanggan berutang…"
                                aria-label="Cari pelanggan berutang"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <ul className="max-h-[60vh] divide-y overflow-y-auto">
                            {matches.length === 0 && (
                                <li className="p-4 text-sm text-muted-foreground">
                                    {debtors.length === 0
                                        ? 'Tidak ada pelanggan yang berutang.'
                                        : 'Tidak ada yang cocok.'}
                                </li>
                            )}
                            {matches.map((d) => (
                                <li key={d.id}>
                                    <button
                                        type="button"
                                        onClick={() => setSelectedId(d.id)}
                                        className={cn(
                                            'flex w-full items-baseline justify-between gap-3 px-4 py-3 text-left hover:bg-muted',
                                            d.id === selectedId && 'bg-muted',
                                        )}
                                    >
                                        <span className="min-w-0">
                                            <span className="block truncate font-medium">
                                                {d.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {d.openSales.length} kasbon ·
                                                tertua{' '}
                                                {d.openSales[0]?.ageDays ?? 0}{' '}
                                                hari
                                            </span>
                                        </span>
                                        <span className="shrink-0 font-semibold tabular-nums">
                                            {formatRupiah(d.balance)}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    </section>

                    {selected ? (
                        <PaymentForm key={selected.id} debtor={selected} />
                    ) : (
                        <div className="grid place-items-center rounded-xl border border-dashed p-8 text-sm text-muted-foreground">
                            Pilih pelanggan di sebelah kiri.
                        </div>
                    )}
                </div>
            </div>

            <Dialog
                open={done !== null}
                onOpenChange={(open) => !open && setDone(null)}
            >
                <DialogContent className="sm:max-w-sm">
                    {done && (
                        <>
                            <DialogHeader className="items-center text-center">
                                <CheckCircle2 className="size-12 text-emerald-500" />
                                <DialogTitle>Pelunasan diterima</DialogTitle>
                                <DialogDescription>
                                    {done.number} · {done.customer}
                                </DialogDescription>
                            </DialogHeader>
                            <div className="rounded-lg bg-muted p-4 text-center">
                                <div className="text-sm text-muted-foreground">
                                    Dibayar
                                </div>
                                <div className="text-3xl font-bold tracking-tight tabular-nums">
                                    {formatRupiah(done.amount)}
                                </div>
                                <div className="mt-1 text-sm">
                                    {done.balance === 0
                                        ? 'Kasbon lunas semua.'
                                        : `Sisa utang ${formatRupiah(done.balance)}`}
                                </div>
                            </div>
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    variant="outline"
                                    onClick={() => setDone(null)}
                                >
                                    Tutup
                                </Button>
                                <Button
                                    autoFocus
                                    onClick={() => {
                                        window.open(
                                            pos.payments.receipt.url(done.id, {
                                                query: { print: 1 },
                                            }),
                                            '_blank',
                                            'noopener,width=420,height=640',
                                        );
                                        setDone(null);
                                    }}
                                >
                                    <Printer /> Cetak bukti
                                </Button>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function PaymentForm({ debtor }: { debtor: Debtor }) {
    const form = useForm({ customer_id: debtor.id, amount: 0, note: '' });
    const amount = form.data.amount;
    const valid = amount >= 1 && amount <= debtor.balance;

    // Where the money would go, oldest first (the server does the same).
    let left = amount;
    const allocation = debtor.openSales.map((s) => {
        const take = Math.min(left, s.owed);
        left -= take;

        return { ...s, take };
    });

    return (
        <form
            className="space-y-4 rounded-xl border p-4"
            onSubmit={(e) => {
                e.preventDefault();
                if (valid && !form.processing) {
                    form.post(pos.payments.store.url(), {
                        preserveScroll: true,
                        onSuccess: () => form.reset('amount', 'note'),
                    });
                }
            }}
        >
            <div className="flex items-baseline justify-between gap-3">
                <div>
                    <h2 className="font-semibold">{debtor.name}</h2>
                    <p className="text-xs text-muted-foreground">
                        {debtor.phone ?? 'Tanpa nomor HP'}
                        {!debtor.active && (
                            <Badge variant="secondary" className="ml-2">
                                Nonaktif
                            </Badge>
                        )}
                    </p>
                </div>
                <div className="text-right">
                    <div className="text-xs text-muted-foreground">Utang</div>
                    <div className="text-2xl font-bold tabular-nums">
                        {formatRupiah(debtor.balance)}
                    </div>
                </div>
            </div>

            <ul className="divide-y rounded-lg border text-sm">
                {allocation.map((s) => (
                    <li
                        key={s.id}
                        className="grid grid-cols-[1fr_auto_auto] items-baseline gap-3 px-3 py-2"
                    >
                        <span className="min-w-0">
                            <span className="block truncate">{s.number}</span>
                            <span className="block text-xs text-muted-foreground">
                                {formatDate(s.at)} · {s.ageDays} hari
                            </span>
                        </span>
                        <span
                            className={cn(
                                'text-xs tabular-nums',
                                s.take > 0
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-muted-foreground',
                            )}
                        >
                            {s.take > 0
                                ? s.take === s.owed
                                    ? 'lunas'
                                    : `−${formatRupiah(s.take)}`
                                : ''}
                        </span>
                        <span className="text-right font-medium tabular-nums">
                            {formatRupiah(s.owed)}
                        </span>
                    </li>
                ))}
            </ul>

            <div className="grid gap-2">
                <Label htmlFor="payment-amount">Jumlah dibayar (tunai)</Label>
                <RupiahInput
                    id="payment-amount"
                    className="h-12 text-xl"
                    value={amount}
                    onValueChange={(v) => form.setData('amount', v)}
                />
                <div className="flex gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant={
                            amount === debtor.balance ? 'default' : 'outline'
                        }
                        onClick={() => form.setData('amount', debtor.balance)}
                    >
                        Lunasi semua
                    </Button>
                </div>
                {amount > debtor.balance && (
                    <p className="text-sm text-destructive">
                        Melebihi utang {formatRupiah(debtor.balance)}. Terima
                        sejumlah utangnya saja; kembalikan sisanya.
                    </p>
                )}
                <InputError
                    message={
                        form.errors.amount ??
                        (form.errors as Record<string, string | undefined>)
                            .shift
                    }
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="payment-note">Catatan (opsional)</Label>
                <Input
                    id="payment-note"
                    value={form.data.note}
                    onChange={(e) => form.setData('note', e.target.value)}
                />
            </div>

            <Button
                type="submit"
                size="lg"
                className="w-full"
                disabled={!valid || form.processing}
            >
                <HandCoins />
                {form.processing
                    ? 'Memproses…'
                    : `Terima ${valid ? formatRupiah(amount) : ''}`.trim()}
            </Button>
        </form>
    );
}

Payments.layout = {
    breadcrumbs: [
        { title: 'Kasir', href: pos.index() },
        { title: 'Pelunasan kasbon', href: pos.payments.create() },
    ],
};
