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
import { Label } from '@/components/ui/label';
import { formatRupiah, suggestedPayments } from '@/lib/money';
import { cn } from '@/lib/utils';

type Props = {
    open: boolean;
    total: number;
    processing: boolean;
    error?: string;
    onOpenChange: (open: boolean) => void;
    onPay: (paid: number) => void;
};

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

function PaymentForm({ total, processing, error, onPay }: Props) {
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
                    {formatRupiah(Math.abs(paid - total))}
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
