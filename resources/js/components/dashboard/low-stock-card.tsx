import { Link } from '@inertiajs/react';
import { AlertTriangle, PackageCheck } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatQty } from '@/lib/format';
import goodsReceipts from '@/routes/goods-receipts';
import products from '@/routes/products';

export type LowStockItem = {
    id: number;
    sku: string;
    name: string;
    baseUnit: string;
    stock: number;
    minStock: number;
};

export function LowStockCard({
    count,
    items,
}: {
    count: number;
    items: LowStockItem[];
}) {
    return (
        <Card>
            <CardHeader className="flex flex-row items-start justify-between gap-3">
                <div className="space-y-1.5">
                    <CardTitle className="flex items-center gap-2">
                        {count > 0 ? (
                            <AlertTriangle className="size-4 text-destructive" />
                        ) : (
                            <PackageCheck className="size-4 text-emerald-600" />
                        )}
                        Stok menipis
                    </CardTitle>
                    <CardDescription>
                        {count > 0
                            ? `${count} produk di bawah atau sama dengan stok minimum.`
                            : 'Semua produk aktif di atas stok minimum.'}
                    </CardDescription>
                </div>
                {count > 0 && (
                    <Button size="sm" variant="outline" asChild>
                        <Link href={goodsReceipts.create()}>Terima barang</Link>
                    </Button>
                )}
            </CardHeader>
            {items.length > 0 && (
                <CardContent className="space-y-3">
                    <ul className="divide-y text-sm">
                        {items.map((p) => (
                            <li
                                key={p.id}
                                className="flex items-center justify-between gap-3 py-2"
                            >
                                <Link
                                    href={products.stockCard(p.id)}
                                    className="min-w-0 hover:underline"
                                >
                                    <span className="block truncate font-medium">
                                        {p.name}
                                    </span>
                                    <span className="text-xs text-muted-foreground">
                                        {p.sku}
                                    </span>
                                </Link>
                                <span className="text-right whitespace-nowrap tabular-nums">
                                    <span
                                        className={
                                            p.stock <= 0
                                                ? 'font-semibold text-destructive'
                                                : 'font-semibold'
                                        }
                                    >
                                        {p.stock <= 0
                                            ? 'Habis'
                                            : `${formatQty(p.stock)} ${p.baseUnit}`}
                                    </span>
                                    <span className="block text-xs text-muted-foreground">
                                        min. {formatQty(p.minStock)}
                                    </span>
                                </span>
                            </li>
                        ))}
                    </ul>
                    {count > items.length && (
                        <Link
                            href={products.index({ query: { status: 'low' } })}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            Lihat semua {count} produk →
                        </Link>
                    )}
                </CardContent>
            )}
        </Card>
    );
}
