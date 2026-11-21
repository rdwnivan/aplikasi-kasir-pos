import { Minus, Percent, Plus, ShoppingCart, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatRupiah } from '@/lib/money';
import type { PricedCart } from '@/lib/pricing';
import { cn } from '@/lib/utils';
import type { CartItem, CatalogItem } from '@/types/pos';

type Props = {
    cart: CartItem[];
    catalog: Map<number, CatalogItem>;
    priced: PricedCart;
    shortProducts: Set<number>;
    onQty: (index: number, qty: number) => void;
    onRemove: (index: number) => void;
    onDiscount: (index: number) => void;
};

export function CartPanel({
    cart,
    catalog,
    priced,
    shortProducts,
    onQty,
    onRemove,
    onDiscount,
}: Props) {
    if (cart.length === 0) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-2 p-8 text-center text-sm text-muted-foreground">
                <ShoppingCart className="size-8 opacity-50" />
                Keranjang kosong. Scan barcode atau cari produk.
            </div>
        );
    }

    return (
        <ul className="flex-1 divide-y overflow-y-auto">
            {cart.map((line, index) => {
                const item = catalog.get(line.productUnitId);
                const pricedLine = priced.lines[index];

                if (!item || !pricedLine) {
                    return null;
                }

                const short = shortProducts.has(item.productId);

                return (
                    <li
                        key={`${line.productUnitId}-${index}`}
                        className="px-4 py-3"
                    >
                        <div className="flex items-start gap-2">
                            <div className="min-w-0 flex-1">
                                <div className="truncate font-medium">
                                    {item.name}
                                </div>
                                <div className="text-xs text-muted-foreground">
                                    {formatRupiah(item.price)} / {item.unit}
                                    {short && (
                                        <span className="ml-2 font-medium text-destructive">
                                            Stok tidak cukup
                                        </span>
                                    )}
                                </div>
                            </div>
                            <div className="text-right">
                                <div className="font-semibold tabular-nums">
                                    {formatRupiah(pricedLine.total)}
                                </div>
                                {pricedLine.discount > 0 && (
                                    <div className="text-xs text-emerald-600 tabular-nums dark:text-emerald-400">
                                        hemat{' '}
                                        {formatRupiah(pricedLine.discount)}
                                    </div>
                                )}
                            </div>
                        </div>
                        <div className="mt-2 flex items-center gap-1">
                            <Button
                                size="icon"
                                variant="outline"
                                className="size-8"
                                aria-label="Kurangi"
                                onClick={() => onQty(index, line.qty - 1)}
                                disabled={line.qty <= 1}
                            >
                                <Minus />
                            </Button>
                            <Input
                                aria-label={`Jumlah ${item.name}`}
                                inputMode="numeric"
                                className={cn(
                                    'h-8 w-16 text-center tabular-nums',
                                    short && 'border-destructive',
                                )}
                                value={line.qty}
                                onChange={(e) => {
                                    const qty = Number.parseInt(
                                        e.target.value.replace(/\D/g, ''),
                                        10,
                                    );
                                    onQty(index, Number.isNaN(qty) ? 1 : qty);
                                }}
                                onFocus={(e) => e.target.select()}
                            />
                            <Button
                                size="icon"
                                variant="outline"
                                className="size-8"
                                aria-label="Tambah"
                                onClick={() => onQty(index, line.qty + 1)}
                            >
                                <Plus />
                            </Button>
                            <span className="ml-1 text-xs text-muted-foreground">
                                {item.unit}
                            </span>
                            <div className="ml-auto flex gap-1">
                                <Button
                                    size="icon"
                                    variant={
                                        line.discountType
                                            ? 'secondary'
                                            : 'ghost'
                                    }
                                    className="size-8"
                                    aria-label="Diskon item"
                                    onClick={() => onDiscount(index)}
                                >
                                    <Percent />
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8 text-destructive hover:text-destructive"
                                    aria-label="Hapus item"
                                    onClick={() => onRemove(index)}
                                >
                                    <Trash2 />
                                </Button>
                            </div>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
