import { useState } from 'react';
import { useOpenKey } from '@/hooks/use-open-key';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Button } from '@/components/ui/button';
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
import { formatRupiah } from '@/lib/money';
import { discountAmount } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import type { DiscountType } from '@/types/pos';

type Props = {
    open: boolean;
    title: string;
    base: number;
    type: DiscountType | null;
    value: number;
    onOpenChange: (open: boolean) => void;
    onApply: (type: DiscountType | null, value: number) => void;
};

export function DiscountDialog(props: Props) {
    const key = useOpenKey(props.open);

    return (
        <Dialog open={props.open} onOpenChange={props.onOpenChange}>
            <DialogContent className="sm:max-w-sm">
                <DiscountForm key={key} {...props} />
            </DialogContent>
        </Dialog>
    );
}

function DiscountForm({
    title,
    base,
    type: initialType,
    value: initialValue,
    onApply,
}: Props) {
    const [type, setType] = useState<DiscountType>(initialType ?? 'percent');
    const [value, setValue] = useState(initialValue);
    const amount = discountAmount(base, type, value);
    const invalid =
        (type === 'percent' && value > 100) ||
        (type === 'amount' && value > base);

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                if (!invalid) {
                    onApply(value > 0 ? type : null, value);
                }
            }}
            className="space-y-4"
        >
            <DialogHeader>
                <DialogTitle>{title}</DialogTitle>
                <DialogDescription>Dari {formatRupiah(base)}</DialogDescription>
            </DialogHeader>

            <div className="grid grid-cols-2 gap-1 rounded-lg bg-muted p-1">
                {(['percent', 'amount'] as const).map((option) => (
                    <button
                        key={option}
                        type="button"
                        onClick={() => {
                            setType(option);
                            setValue(0);
                        }}
                        className={cn(
                            'rounded-md py-1.5 text-sm font-medium transition-colors',
                            type === option
                                ? 'bg-background shadow-sm'
                                : 'text-muted-foreground',
                        )}
                    >
                        {option === 'percent' ? 'Persen (%)' : 'Nominal (Rp)'}
                    </button>
                ))}
            </div>

            <div className="grid gap-2">
                <Label htmlFor="discount-value">
                    {type === 'percent' ? 'Diskon (%)' : 'Diskon (Rp)'}
                </Label>
                {type === 'percent' ? (
                    <Input
                        id="discount-value"
                        autoFocus
                        inputMode="numeric"
                        className="h-11 text-lg tabular-nums"
                        value={value === 0 ? '' : value}
                        onChange={(e) =>
                            setValue(
                                Math.min(
                                    Number(
                                        e.target.value.replace(/\D/g, '') || 0,
                                    ),
                                    999,
                                ),
                            )
                        }
                    />
                ) : (
                    <RupiahInput
                        id="discount-value"
                        autoFocus
                        className="h-11 text-lg"
                        value={value}
                        onValueChange={setValue}
                    />
                )}
                <p
                    className={cn(
                        'text-sm',
                        invalid ? 'text-destructive' : 'text-muted-foreground',
                    )}
                >
                    {invalid
                        ? type === 'percent'
                            ? 'Maksimal 100%.'
                            : 'Diskon melebihi harga.'
                        : `Potongan ${formatRupiah(amount)}`}
                </p>
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
                <Button
                    type="button"
                    variant="ghost"
                    onClick={() => onApply(null, 0)}
                >
                    Hapus diskon
                </Button>
                <Button type="submit" disabled={invalid}>
                    Terapkan
                </Button>
            </DialogFooter>
        </form>
    );
}
