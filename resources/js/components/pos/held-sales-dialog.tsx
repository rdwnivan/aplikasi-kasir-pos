import { Clock, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatRupiah } from '@/lib/money';
import type { HeldSale } from '@/types/pos';

const timeFormat = new Intl.DateTimeFormat('id-ID', { timeStyle: 'short' });

type Props = {
    open: boolean;
    heldSales: HeldSale[];
    cartIsEmpty: boolean;
    onOpenChange: (open: boolean) => void;
    onResume: (sale: HeldSale) => void;
    onDiscard: (sale: HeldSale) => void;
};

export function HeldSalesDialog({
    open,
    heldSales,
    cartIsEmpty,
    onOpenChange,
    onResume,
    onDiscard,
}: Props) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Transaksi ditahan</DialogTitle>
                    <DialogDescription>
                        {cartIsEmpty
                            ? 'Pilih transaksi untuk dilanjutkan.'
                            : 'Kosongkan atau tahan keranjang saat ini dulu sebelum melanjutkan yang lain.'}
                    </DialogDescription>
                </DialogHeader>

                {heldSales.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        Tidak ada transaksi yang ditahan.
                    </p>
                ) : (
                    <ul className="-mx-2 max-h-96 divide-y overflow-y-auto">
                        {heldSales.map((sale) => (
                            <li
                                key={sale.id}
                                className="flex items-center gap-3 px-2 py-3"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="truncate font-medium">
                                        {sale.label || 'Tanpa nama'}
                                    </div>
                                    <div className="flex items-center gap-1 text-xs text-muted-foreground">
                                        <Clock className="size-3" />
                                        {sale.heldAt
                                            ? timeFormat.format(
                                                  new Date(sale.heldAt),
                                              )
                                            : '-'}
                                        {' · '}
                                        {sale.items.length} item ·{' '}
                                        {formatRupiah(sale.total)}
                                    </div>
                                </div>
                                <Button
                                    size="sm"
                                    disabled={!cartIsEmpty}
                                    onClick={() => onResume(sale)}
                                >
                                    Lanjutkan
                                </Button>
                                <Button
                                    size="icon"
                                    variant="ghost"
                                    className="size-8 text-destructive hover:text-destructive"
                                    aria-label={`Hapus transaksi ${sale.label ?? ''}`}
                                    onClick={() => onDiscard(sale)}
                                >
                                    <Trash2 />
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </DialogContent>
        </Dialog>
    );
}
