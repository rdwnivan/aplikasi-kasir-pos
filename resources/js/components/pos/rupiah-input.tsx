import { forwardRef } from 'react';
import { Input } from '@/components/ui/input';
import { formatNumber, parseRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';

type Props = Omit<
    React.ComponentProps<'input'>,
    'value' | 'onChange' | 'type'
> & {
    value: number;
    onValueChange: (value: number) => void;
};

/** Numeric input that shows thousand separators as you type: 150000 → "150.000". */
export const RupiahInput = forwardRef<HTMLInputElement, Props>(
    function RupiahInput({ value, onValueChange, className, ...props }, ref) {
        return (
            <div className="relative">
                <span className="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-muted-foreground">
                    Rp
                </span>
                <Input
                    ref={ref}
                    inputMode="numeric"
                    autoComplete="off"
                    className={cn('pl-10 tabular-nums', className)}
                    value={value === 0 ? '' : formatNumber(value)}
                    onChange={(e) =>
                        onValueChange(
                            Math.min(
                                parseRupiah(e.target.value),
                                1_000_000_000,
                            ),
                        )
                    }
                    {...props}
                />
            </div>
        );
    },
);
