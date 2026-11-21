import { PackageSearch } from 'lucide-react';
import { useEffect, useRef } from 'react';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import type { CatalogItem } from '@/types/pos';

type Props = {
    items: CatalogItem[];
    highlighted: number;
    query: string;
    onPick: (item: CatalogItem) => void;
    onHover: (index: number) => void;
};

export function ProductResults({
    items,
    highlighted,
    query,
    onPick,
    onHover,
}: Props) {
    const listRef = useRef<HTMLUListElement>(null);

    useEffect(() => {
        listRef.current
            ?.querySelector<HTMLElement>(`[data-index="${highlighted}"]`)
            ?.scrollIntoView({ block: 'nearest' });
    }, [highlighted]);

    if (items.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center text-sm text-muted-foreground">
                <PackageSearch className="size-8 opacity-50" />
                {query ? (
                    <>Tidak ada produk yang cocok dengan “{query}”.</>
                ) : (
                    'Katalog masih kosong.'
                )}
            </div>
        );
    }

    return (
        <ul
            ref={listRef}
            role="listbox"
            aria-label="Hasil pencarian produk"
            className="flex-1 overflow-y-auto p-2"
        >
            {items.map((item, index) => {
                const outOfStock = item.stock < item.conversion;

                return (
                    <li
                        key={item.id}
                        role="option"
                        aria-selected={index === highlighted}
                        data-index={index}
                    >
                        <button
                            type="button"
                            onClick={() => onPick(item)}
                            onMouseMove={() => onHover(index)}
                            className={cn(
                                'flex w-full items-center gap-3 rounded-lg px-3 py-2.5 text-left transition-colors',
                                index === highlighted
                                    ? 'bg-accent'
                                    : 'hover:bg-accent/60',
                            )}
                        >
                            <div className="min-w-0 flex-1">
                                <div className="truncate font-medium">
                                    {item.name}
                                </div>
                                <div className="flex gap-2 text-xs text-muted-foreground">
                                    <span>{item.sku}</span>
                                    {item.barcode && (
                                        <span className="hidden sm:inline">
                                            {item.barcode}
                                        </span>
                                    )}
                                    <span
                                        className={cn(
                                            outOfStock &&
                                                'font-medium text-destructive',
                                        )}
                                    >
                                        Stok {formatStock(item)}
                                    </span>
                                </div>
                            </div>
                            <div className="text-right">
                                <div className="font-semibold tabular-nums">
                                    {formatRupiah(item.price)}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    per {item.unit}
                                    {item.conversion > 1 &&
                                        ` (${item.conversion})`}
                                </div>
                            </div>
                        </button>
                    </li>
                );
            })}
        </ul>
    );
}

function formatStock(item: CatalogItem): string {
    if (item.conversion === 1) {
        return String(item.stock);
    }

    return `${Math.floor(item.stock / item.conversion)} ${item.unit}`;
}
