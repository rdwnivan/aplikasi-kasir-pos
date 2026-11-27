import { router } from '@inertiajs/react';
import {
    Banknote,
    NotebookPen,
    QrCode,
    SplitSquareHorizontal,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';
import { useOpenKey } from '@/hooks/use-open-key';
import { Kbd } from '@/components/pos/kbd';
import { RupiahInput } from '@/components/pos/rupiah-input';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatRupiah, suggestedPayments } from '@/lib/money';
import { cn } from '@/lib/utils';
import posCustomers from '@/routes/pos/customers';
import type { CreditCustomer } from '@/types/pos';

type Props = {
    open: boolean;
    total: number;
    processing: boolean;
    error?: string;
    qrisEnabled: boolean;
    customers: CreditCustomer[];
    onOpenChange: (open: boolean) => void;
    onPay: (paid: number) => void;
    /** Without an amount: QRIS for the whole total. With one: that much cash, QRIS for the rest. */
    onQris: (cashAmount?: number) => void;
    /** Kasbon for a customer, with an optional cash down payment (DESIGN.md §17). */
    onCredit: (customerId: number, downPayment: number) => void;
};

type Method = 'cash' | 'qris' | 'split' | 'credit';

export function PaymentDialog(props: Props) {
    const key = useOpenKey(props.open);

    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <PaymentForm key={key} {...props} />
            </DialogContent>
        </Dialog>
    );
}

function PaymentForm(props: Props) {
    const [method, setMethod] = useState<Method>('cash');

    return (
        <div className="space-y-5">
            <ToggleGroup
                type="single"
                variant="outline"
                className="w-full"
                value={method}
                onValueChange={(v) => v && setMethod(v as Method)}
            >
                <ToggleGroupItem value="cash" className="flex-1">
                    <Banknote /> Tunai
                </ToggleGroupItem>
                <ToggleGroupItem value="qris" className="flex-1">
                    <QrCode /> QRIS
                </ToggleGroupItem>
                <ToggleGroupItem value="split" className="flex-1">
                    <SplitSquareHorizontal />
                    <span className="hidden sm:inline">Tunai + QRIS</span>
                    <span className="sm:hidden">Campur</span>
                </ToggleGroupItem>
                <ToggleGroupItem value="credit" className="flex-1">
                    <NotebookPen /> Kasbon
                </ToggleGroupItem>
            </ToggleGroup>
            {method === 'cash' && <CashForm {...props} />}
            {method === 'qris' && <QrisForm {...props} />}
            {method === 'split' && <SplitForm {...props} />}
            {method === 'credit' && <CreditForm {...props} />}
        </div>
    );
}

/** Kasbon: pick (or add) a customer, take an optional exact cash down payment. */
function CreditForm({ total, processing, error, customers, onCredit }: Props) {
    const [search, setSearch] = useState('');
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [down, setDown] = useState(0);
    const [adding, setAdding] = useState(false);
    // Just added from the dialog: picked once the reloaded list has it (newest of that name).
    const [addedName, setAddedName] = useState<string | null>(null);

    const selected =
        customers.find((c) => c.id === selectedId) ??
        (addedName === null
            ? null
            : (customers
                  .filter((c) => c.name === addedName)
                  .sort((a, b) => b.id - a.id)[0] ?? null));
    const credit = total - down;
    const overLimit = selected !== null && credit > selected.available;
    const valid = selected !== null && down >= 0 && down < total && !overLimit;

    const terms = search.toLowerCase().split(/\s+/).filter(Boolean);
    const digits = search.replace(/\D+/g, '');
    const matches = customers
        .filter(
            (c) =>
                terms.every((t) => c.name.toLowerCase().includes(t)) ||
                (digits.length >= 3 && (c.phone ?? '').includes(digits)),
        )
        .slice(0, 6);

    return (
        <form
            className="space-y-4"
            onSubmit={(e) => {
                e.preventDefault();
                if (valid && !processing) {
                    onCredit(selected.id, down);
                }
            }}
        >
            <DialogHeader>
                <DialogTitle>Kasbon</DialogTitle>
                <DialogDescription>Total belanja</DialogDescription>
                <div className="text-4xl font-bold tracking-tight tabular-nums">
                    {formatRupiah(total)}
                </div>
            </DialogHeader>

            {selected ? (
                <div className="flex items-start justify-between gap-3 rounded-lg border p-3">
                    <div className="min-w-0">
                        <div className="truncate font-medium">
                            {selected.name}
                        </div>
                        <div className="text-xs text-muted-foreground">
                            Utang {formatRupiah(selected.balance)} · sisa batas{' '}
                            {formatRupiah(selected.available)}
                        </div>
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        onClick={() => {
                            setSelectedId(null);
                            setAddedName(null);
                        }}
                    >
                        Ganti
                    </Button>
                </div>
            ) : adding ? (
                <NewCustomerForm
                    onCancel={() => setAdding(false)}
                    onCreated={(name) => {
                        setAdding(false);
                        setAddedName(name);
                    }}
                />
            ) : (
                <div className="grid gap-2">
                    <Label htmlFor="credit-customer">Pelanggan</Label>
                    <Input
                        id="credit-customer"
                        autoFocus
                        placeholder="Cari nama atau nomor HP…"
                        value={search}
                        onChange={(e) => setSearch(e.target.value)}
                    />
                    <ul className="max-h-48 divide-y overflow-y-auto rounded-lg border">
                        {matches.map((c) => (
                            <li key={c.id}>
                                <button
                                    type="button"
                                    className="flex w-full items-baseline justify-between gap-3 px-3 py-2 text-left text-sm hover:bg-muted focus-visible:bg-muted focus-visible:outline-none"
                                    onClick={() => setSelectedId(c.id)}
                                >
                                    <span className="min-w-0 truncate">
                                        {c.name}
                                        {c.phone && (
                                            <span className="ml-1 text-xs text-muted-foreground">
                                                {c.phone}
                                            </span>
                                        )}
                                    </span>
                                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                                        utang {formatRupiah(c.balance)}
                                    </span>
                                </button>
                            </li>
                        ))}
                        {matches.length === 0 && (
                            <li className="px-3 py-2 text-sm text-muted-foreground">
                                Tidak ada pelanggan yang cocok.
                            </li>
                        )}
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="justify-self-start"
                        onClick={() => setAdding(true)}
                    >
                        <UserPlus /> Pelanggan baru
                    </Button>
                </div>
            )}

            <div className="grid gap-2">
                <Label htmlFor="credit-down">Uang muka tunai (boleh 0)</Label>
                <RupiahInput
                    id="credit-down"
                    className="h-11 text-lg"
                    value={down}
                    onValueChange={setDown}
                />
            </div>

            <div
                className={cn(
                    'flex items-baseline justify-between rounded-lg border p-3',
                    !selected
                        ? 'text-muted-foreground'
                        : valid
                          ? 'border-amber-500/40 bg-amber-500/10'
                          : 'border-destructive/40 bg-destructive/10',
                )}
            >
                <span className="text-sm">
                    {down >= total
                        ? 'Uang muka harus kurang dari total'
                        : overLimit
                          ? `Melebihi sisa batas ${formatRupiah(selected?.available ?? 0)}`
                          : 'Jadi kasbon'}
                </span>
                <span className="text-2xl font-semibold tabular-nums">
                    {formatRupiah(Math.max(0, credit))}
                </span>
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}

            <Button
                type="submit"
                size="lg"
                className="w-full"
                disabled={!valid || processing}
            >
                <NotebookPen />
                {processing ? 'Menyimpan…' : 'Simpan kasbon'}
            </Button>
        </form>
    );
}

/** Name + phone only; the server gives a cashier-added customer the default limit. */
function NewCustomerForm({
    onCancel,
    onCreated,
}: {
    onCancel: () => void;
    onCreated: (name: string) => void;
}) {
    const [name, setName] = useState('');
    const [phone, setPhone] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);

    function save() {
        router.post(
            posCustomers.store.url(),
            { name, phone: phone || null },
            {
                preserveState: true,
                preserveScroll: true,
                only: ['customers', 'flash'],
                onStart: () => setSaving(true),
                onFinish: () => setSaving(false),
                onSuccess: () => onCreated(name.trim()),
                onError: (e) => setErrors(e),
            },
        );
    }

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <Label htmlFor="new-customer-name">Nama pelanggan baru</Label>
            <Input
                id="new-customer-name"
                autoFocus
                value={name}
                onChange={(e) => setName(e.target.value)}
                aria-invalid={errors.name ? true : undefined}
            />
            {errors.name && (
                <p className="text-sm text-destructive">{errors.name}</p>
            )}
            <Label htmlFor="new-customer-phone">Nomor HP (opsional)</Label>
            <Input
                id="new-customer-phone"
                inputMode="tel"
                value={phone}
                onChange={(e) => setPhone(e.target.value)}
                aria-invalid={errors.phone ? true : undefined}
            />
            {errors.phone && (
                <p className="text-sm text-destructive">{errors.phone}</p>
            )}
            <p className="text-xs text-muted-foreground">
                Batas kasbon awal mengikuti pengaturan toko; owner bisa
                mengubahnya di menu Pelanggan.
            </p>
            <div className="flex justify-end gap-2">
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    onClick={onCancel}
                >
                    Batal
                </Button>
                <Button
                    type="button"
                    size="sm"
                    disabled={name.trim() === '' || saving}
                    onClick={save}
                >
                    {saving ? 'Menyimpan…' : 'Simpan pelanggan'}
                </Button>
            </div>
        </div>
    );
}

function QrisForm({ total, processing, error, qrisEnabled, onQris }: Props) {
    return (
        <div className="space-y-5">
            <DialogHeader>
                <DialogTitle>Pembayaran QRIS</DialogTitle>
                <DialogDescription>Total belanja</DialogDescription>
                <div className="text-4xl font-bold tracking-tight tabular-nums">
                    {formatRupiah(total)}
                </div>
            </DialogHeader>
            {qrisEnabled ? (
                <p className="text-sm text-muted-foreground">
                    Kode QR dibuat lewat Midtrans dan berlaku 15 menit. Stok
                    baru berkurang setelah pembayaran diterima.
                </p>
            ) : (
                <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                    QRIS belum aktif. Isi MIDTRANS_SERVER_KEY dan
                    MIDTRANS_CLIENT_KEY di file .env.
                </p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
            <Button
                type="button"
                size="lg"
                className="w-full"
                disabled={!qrisEnabled || processing}
                onClick={() => onQris()}
            >
                <QrCode /> {processing ? 'Membuat QRIS…' : 'Tampilkan QRIS'}
            </Button>
        </div>
    );
}

/** Part cash (taken first, exact amount), the rest by QRIS. DESIGN.md §11. */
function SplitForm({ total, processing, error, qrisEnabled, onQris }: Props) {
    const [cash, setCash] = useState(0);
    const rest = total - cash;
    const valid = cash > 0 && cash < total;

    return (
        <form
            className="space-y-5"
            onSubmit={(e) => {
                e.preventDefault();
                if (valid && qrisEnabled && !processing) {
                    onQris(cash);
                }
            }}
        >
            <DialogHeader>
                <DialogTitle>Tunai + QRIS</DialogTitle>
                <DialogDescription>Total belanja</DialogDescription>
                <div className="text-4xl font-bold tracking-tight tabular-nums">
                    {formatRupiah(total)}
                </div>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="split-cash">Tunai diterima</Label>
                <RupiahInput
                    id="split-cash"
                    autoFocus
                    className="h-12 text-xl"
                    value={cash}
                    onValueChange={setCash}
                />
                <p className="text-sm text-muted-foreground">
                    Terima uang tunai dengan jumlah pas (tanpa kembalian)
                    sebelum menampilkan QRIS. Kalau QRIS batal, uang ini
                    dikembalikan.
                </p>
            </div>

            <div
                className={cn(
                    'flex items-baseline justify-between rounded-lg border p-3',
                    cash === 0
                        ? 'text-muted-foreground'
                        : valid
                          ? 'border-emerald-500/40 bg-emerald-500/10'
                          : 'border-destructive/40 bg-destructive/10',
                )}
            >
                <span className="text-sm">
                    {cash >= total
                        ? 'Tunai harus kurang dari total'
                        : 'Sisa lewat QRIS'}
                </span>
                <span className="text-2xl font-semibold tabular-nums">
                    {formatRupiah(Math.max(0, rest))}
                </span>
            </div>

            {!qrisEnabled && (
                <p className="rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
                    QRIS belum aktif. Isi MIDTRANS_SERVER_KEY di file .env.
                </p>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}

            <Button
                type="submit"
                size="lg"
                className="w-full"
                disabled={!valid || !qrisEnabled || processing}
            >
                <QrCode />
                {processing
                    ? 'Membuat QRIS…'
                    : `Tampilkan QRIS ${valid ? formatRupiah(rest) : ''}`.trim()}
            </Button>
        </form>
    );
}

function CashForm({ total, processing, error, onPay }: Props) {
    const [paid, setPaid] = useState(0);
    const enough = paid >= total;

    return (
        <form
            className="space-y-5"
            onSubmit={(e) => {
                e.preventDefault();
                if (enough && !processing) {
                    onPay(paid);
                }
            }}
        >
            <DialogHeader>
                <DialogTitle>Pembayaran tunai</DialogTitle>
                <DialogDescription>Total belanja</DialogDescription>
                <div className="text-4xl font-bold tracking-tight tabular-nums">
                    {formatRupiah(total)}
                </div>
            </DialogHeader>

            <div className="grid gap-2">
                <Label htmlFor="paid">Uang diterima</Label>
                <RupiahInput
                    id="paid"
                    autoFocus
                    className="h-12 text-xl"
                    value={paid}
                    onValueChange={setPaid}
                />
                <div className="flex flex-wrap gap-2">
                    {suggestedPayments(total).map((amount) => (
                        <Button
                            key={amount}
                            type="button"
                            size="sm"
                            variant={paid === amount ? 'default' : 'outline'}
                            onClick={() => setPaid(amount)}
                        >
                            {amount === total
                                ? 'Uang pas'
                                : formatRupiah(amount)}
                        </Button>
                    ))}
                </div>
            </div>

            <div
                className={cn(
                    'flex items-baseline justify-between rounded-lg border p-3',
                    paid === 0
                        ? 'text-muted-foreground'
                        : enough
                          ? 'border-emerald-500/40 bg-emerald-500/10'
                          : 'border-destructive/40 bg-destructive/10',
                )}
            >
                <span className="text-sm">
                    {paid > 0 && !enough ? 'Kurang' : 'Kembalian'}
                </span>
                <span className="text-2xl font-semibold tabular-nums">
                    {formatRupiah(paid === 0 ? 0 : Math.abs(paid - total))}
                </span>
            </div>

            {error && <p className="text-sm text-destructive">{error}</p>}

            <Button
                type="submit"
                size="lg"
                className="w-full"
                disabled={!enough || processing}
            >
                {processing ? 'Memproses…' : 'Selesaikan transaksi'}
                <Kbd className="ml-1 bg-primary-foreground/20 text-primary-foreground">
                    Enter
                </Kbd>
            </Button>
        </form>
    );
}
