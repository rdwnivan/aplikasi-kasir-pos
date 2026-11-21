import { Search } from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import { Input } from '@/components/ui/input';
import { formatQty } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ProductOption } from '@/types';

type Props = {
    products: ProductOption[];
    /** Called with the product and, for a barcode scan, the unit that barcode belongs to. */
    onPick: (product: ProductOption, unitId: number | null) => void;
    excludeIds?: number[];
    placeholder?: string;
    autoFocus?: boolean;
};

/**
 * Search box for adding products to a document. A USB scanner types the barcode and Enter,
 * which picks the matching product and unit straight away.
 */
export function ProductPicker({
    products,
    onPick,
    excludeIds = [],
    placeholder = 'Cari produk atau scan barcode…',
    autoFocus = false,
}: Props) {
    const [query, setQuery] = useState('');
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const inputRef = useRef<HTMLInputElement>(null);

    const results = useMemo(() => {
        const q = query.trim().toLowerCase();

        if (q === '') {
            return [];
        }

        return products
            .filter(
                (p) =>
                    !excludeIds.includes(p.id) &&
                    (p.name.toLowerCase().includes(q) ||
                        p.sku.toLowerCase().includes(q) ||
                        p.units.some((u) => u.barcode === query.trim())),
            )
            .slice(0, 8);
    }, [products, query, excludeIds]);

    const pick = (product: ProductOption, unitId: number | null) => {
        onPick(product, unitId);
        setQuery('');
        setActive(0);
        setOpen(false);
        inputRef.current?.focus();
    };

    const onEnter = () => {
        const code = query.trim();

        for (const product of products) {
            const unit = product.units.find((u) => u.barcode === code);

            if (unit) {
                pick(product, unit.unitId);

                return;
            }
        }

        const exactSku = products.find(
            (p) => p.sku.toLowerCase() === code.toLowerCase(),
        );
        const chosen = exactSku ?? results[active];

        if (chosen) {
            pick(chosen, null);
        }
    };

    return (
        <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
            <Input
                ref={inputRef}
                autoFocus={autoFocus}
                className="pl-9"
                placeholder={placeholder}
                value={query}
                onChange={(e) => {
                    setQuery(e.target.value);
                    setActive(0);
                    setOpen(true);
                }}
                onFocus={() => setOpen(true)}
                onBlur={() => setTimeout(() => setOpen(false), 150)}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                        onEnter();
                    } else if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        setActive((i) => Math.min(i + 1, results.length - 1));
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        setActive((i) => Math.max(i - 1, 0));
                    } else if (e.key === 'Escape') {
                        setOpen(false);
                    }
                }}
            />
            {open && query.trim() !== '' && (
                <div className="absolute z-20 mt-1 w-full overflow-hidden rounded-md border bg-popover shadow-md">
                    {results.length === 0 ? (
                        <p className="px-3 py-2 text-sm text-muted-foreground">
                            Produk tidak ditemukan.
                        </p>
                    ) : (
                        <ul>
                            {results.map((p, i) => (
                                <li key={p.id}>
                                    <button
                                        type="button"
                                        className={cn(
                                            'flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm',
                                            i === active && 'bg-accent',
                                        )}
                                        onMouseEnter={() => setActive(i)}
                                        onMouseDown={(e) => e.preventDefault()}
                                        onClick={() => pick(p, null)}
                                    >
                                        <span>
                                            <span className="font-medium">
                                                {p.name}
                                            </span>
                                            <span className="ml-2 text-xs text-muted-foreground">
                                                {p.sku}
                                            </span>
                                        </span>
                                        <span className="text-xs whitespace-nowrap text-muted-foreground tabular-nums">
                                            stok {formatQty(p.stock)}{' '}
                                            {p.baseUnit}
                                        </span>
                                    </button>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}
        </div>
    );
}
