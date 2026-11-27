import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Clock } from 'lucide-react';
import InputError from '@/components/input-error';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import reports from '@/routes/reports';
import type { ShiftSummary } from '@/types/pos';

type PendingQris = { number: string; total: number; cashAmount: number };
type PendingPayment = { number: string; customer: string; amount: number };

const relative = new Intl.RelativeTimeFormat('id-ID', { numeric: 'auto' });

/** "12 menit yang lalu", "2 jam yang lalu", … */
function ago(iso: string): string {
    const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (minutes < 60) {
        return relative.format(-minutes, 'minute');
    }
    const hours = Math.round(minutes / 60);

    return hours < 48
        ? relative.format(-hours, 'hour')
        : relative.format(-Math.round(hours / 24), 'day');
}

/** DESIGN.md §13: an owner closes a shift another user left open. */
export default function OwnerCloseShift({
    shift,
    cashier,
    heldCount,
    pendingQris,
    pendingPayments,
    lastSaleAt,
}: {
    shift: ShiftSummary;
    cashier: string;
    heldCount: number;
    pendingQris: PendingQris[];
    pendingPayments: PendingPayment[];
    lastSaleAt: string | null;
}) {
    const form = useForm({ counted_cash: 0, reason: '' });
    const difference = form.data.counted_cash - shift.expectedCash;
    const errors = form.errors as Record<string, string>;
    const pendingCash = pendingQris.reduce((sum, q) => sum + q.cashAmount, 0);

    return (
        <>
            <Head title={`Tutup shift ${cashier}`} />
            <div className="flex flex-1 items-start justify-center p-4 md:items-center">
                <Card className="w-full max-w-lg">
                    <CardHeader>
                        <CardTitle>Tutup shift {cashier}</CardTitle>
                        <CardDescription>
                            Dibuka {formatDateTime(shift.openedAt)}. Hitung uang
                            di laci kasir ini, lalu masukkan jumlahnya dan
                            alasan shift ditutup oleh owner.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-5">
                        <dl className="space-y-1.5 text-sm">
                            <Row
                                label="Modal awal"
                                value={formatRupiah(shift.openingCash)}
                            />
                            <Row
                                label={`Penjualan tunai (${shift.salesCount} transaksi)`}
                                value={formatRupiah(shift.cashSalesTotal)}
                            />
                            {shift.paymentsCount > 0 && (
                                <Row
                                    label={`Pelunasan kasbon tunai (${shift.paymentsCount})`}
                                    value={formatRupiah(shift.paymentsTotal)}
                                />
                            )}
                            {shift.refundsCount > 0 && (
                                <Row
                                    label={`Retur tunai (${shift.refundsCount})`}
                                    value={formatRupiah(-shift.refundsTotal)}
                                />
                            )}
                            <Separator className="my-2" />
                            <Row
                                label="Kas seharusnya"
                                value={formatRupiah(shift.expectedCash)}
                                strong
                            />
                        </dl>
                        {shift.qrisPaymentsCount > 0 && (
                            <p className="-mt-3 text-xs text-muted-foreground">
                                Pelunasan QRIS ({shift.qrisPaymentsCount}){' '}
                                {formatRupiah(shift.qrisPaymentsTotal)} — tidak
                                masuk laci.
                            </p>
                        )}

                        {lastSaleAt && (
                            <Alert>
                                <Clock />
                                <AlertDescription>
                                    Transaksi terakhir {ago(lastSaleAt)} (
                                    {formatDateTime(lastSaleAt)}). Kalau{' '}
                                    {cashier} masih bertugas, keranjang yang
                                    sedang diisinya akan hilang.
                                </AlertDescription>
                            </Alert>
                        )}

                        {(pendingQris.length > 0 ||
                            pendingPayments.length > 0 ||
                            heldCount > 0) && (
                            <Alert variant="destructive">
                                <AlertTriangle />
                                <AlertDescription>
                                    <ul className="list-disc space-y-1 pl-4">
                                        {pendingQris.map((q) => (
                                            <li key={q.number}>
                                                QRIS {q.number} (
                                                {formatRupiah(q.total)}) masih
                                                menunggu pembayaran: dicek ke
                                                Midtrans, lalu dibatalkan bila
                                                belum dibayar.
                                                {q.cashAmount > 0 &&
                                                    ` Bagian tunai ${formatRupiah(q.cashAmount)} hanya dihitung bila QRIS-nya lunas.`}
                                            </li>
                                        ))}
                                        {pendingPayments.map((p) => (
                                            <li key={p.number}>
                                                QRIS pelunasan {p.number} (
                                                {p.customer},{' '}
                                                {formatRupiah(p.amount)}) masih
                                                menunggu pembayaran: dicek ke
                                                Midtrans; bila sudah dibayar
                                                utangnya berkurang, bila belum
                                                dibatalkan.
                                            </li>
                                        ))}
                                        {heldCount > 0 && (
                                            <li>
                                                {heldCount} transaksi ditahan
                                                akan dihapus.
                                            </li>
                                        )}
                                    </ul>
                                    {pendingCash > 0 && (
                                        <p className="mt-2">
                                            Uang tunai{' '}
                                            {formatRupiah(pendingCash)} mungkin
                                            ada di laci tanpa transaksinya.
                                        </p>
                                    )}
                                </AlertDescription>
                            </Alert>
                        )}

                        <form
                            className="space-y-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.submit(
                                    reports.shifts.close.update(shift.id),
                                );
                            }}
                        >
                            <div className="grid gap-2">
                                <Label htmlFor="counted_cash">
                                    Uang di laci (hasil hitung)
                                </Label>
                                <RupiahInput
                                    id="counted_cash"
                                    autoFocus
                                    className="h-11 text-lg"
                                    value={form.data.counted_cash}
                                    onValueChange={(v) =>
                                        form.setData('counted_cash', v)
                                    }
                                />
                                <InputError
                                    message={
                                        form.errors.counted_cash ?? errors.shift
                                    }
                                />
                            </div>

                            <div
                                className={cn(
                                    'rounded-lg border p-3 text-sm',
                                    difference === 0 &&
                                        'border-emerald-500/40 bg-emerald-500/10',
                                    difference < 0 &&
                                        'border-destructive/40 bg-destructive/10',
                                    difference > 0 &&
                                        'border-amber-500/40 bg-amber-500/10',
                                )}
                            >
                                {difference === 0 &&
                                    'Kas pas, tidak ada selisih.'}
                                {difference < 0 &&
                                    `Kas kurang ${formatRupiah(-difference)}.`}
                                {difference > 0 &&
                                    `Kas lebih ${formatRupiah(difference)}.`}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="reason">Alasan</Label>
                                <Textarea
                                    id="reason"
                                    maxLength={255}
                                    rows={2}
                                    placeholder="Mis. kasir pulang, lupa tutup shift"
                                    value={form.data.reason}
                                    onChange={(e) =>
                                        form.setData('reason', e.target.value)
                                    }
                                />
                                <InputError message={form.errors.reason} />
                            </div>

                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    asChild
                                    className="flex-1"
                                >
                                    <Link href={reports.shifts()}>Batal</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    className="flex-1"
                                    disabled={
                                        form.processing ||
                                        form.data.reason.trim() === ''
                                    }
                                >
                                    Tutup shift
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function Row({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <div
            className={cn(
                'flex justify-between gap-4',
                strong && 'text-base font-semibold',
            )}
        >
            <dt className={strong ? '' : 'text-muted-foreground'}>{label}</dt>
            <dd className="tabular-nums">{value}</dd>
        </div>
    );
}

OwnerCloseShift.layout = {
    breadcrumbs: [
        { title: 'Laporan', href: reports.sales() },
        { title: 'Rekap kasir & shift', href: reports.shifts() },
    ],
};
