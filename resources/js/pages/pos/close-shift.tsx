import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle } from 'lucide-react';
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
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import pos from '@/routes/pos';
import type { ShiftSummary } from '@/types/pos';

const timeFormat = new Intl.DateTimeFormat('id-ID', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

export default function CloseShift({
    shift,
    heldCount,
}: {
    shift: ShiftSummary;
    heldCount: number;
}) {
    const form = useForm({ counted_cash: 0, note: '' });
    const difference = form.data.counted_cash - shift.expectedCash;
    const counted = form.data.counted_cash > 0;

    return (
        <>
            <Head title="Tutup shift" />
            <div className="flex flex-1 items-start justify-center p-4 md:items-center">
                <Card className="w-full max-w-md">
                    <CardHeader>
                        <CardTitle>Tutup shift</CardTitle>
                        <CardDescription>
                            Dibuka {timeFormat.format(new Date(shift.openedAt))}
                            . Hitung semua uang di laci, lalu masukkan
                            jumlahnya.
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

                        {heldCount > 0 && (
                            <Alert variant="destructive">
                                <AlertTriangle />
                                <AlertDescription>
                                    Masih ada {heldCount} transaksi yang
                                    ditahan. Selesaikan atau hapus dulu di layar
                                    kasir.
                                </AlertDescription>
                            </Alert>
                        )}

                        <form
                            className="space-y-5"
                            onSubmit={(e) => {
                                e.preventDefault();
                                form.submit(pos.shift.update());
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
                                        form.errors.counted_cash ??
                                        (form.errors as Record<string, string>)
                                            .shift
                                    }
                                />
                            </div>

                            {counted && (
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
                            )}

                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    asChild
                                    className="flex-1"
                                >
                                    <Link href={pos.index()}>Batal</Link>
                                </Button>
                                <Button
                                    type="submit"
                                    className="flex-1"
                                    disabled={
                                        form.processing ||
                                        heldCount > 0 ||
                                        !counted
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

CloseShift.layout = {
    breadcrumbs: [
        { title: 'Kasir', href: pos.index() },
        { title: 'Tutup shift', href: pos.shift.edit() },
    ],
};
