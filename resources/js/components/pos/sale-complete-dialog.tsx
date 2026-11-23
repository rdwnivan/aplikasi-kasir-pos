import { CheckCircle2, Printer } from 'lucide-react';
import { Kbd } from '@/components/pos/kbd';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatRupiah } from '@/lib/money';
import type { LastSale } from '@/types/pos';

type Props = {
    sale: LastSale | null;
    onPrint: (sale: LastSale) => void;
    onClose: () => void;
};

export function SaleCompleteDialog({ sale, onPrint, onClose }: Props) {
    return (
        <Dialog
            open={sale !== null}
            onOpenChange={(open) => !open && onClose()}
        >
            <DialogContent className="sm:max-w-sm">
                {sale && (
                    <>
                        <DialogHeader className="items-center text-center">
                            <CheckCircle2 className="size-12 text-emerald-500" />
                            <DialogTitle>Transaksi berhasil</DialogTitle>
                            <DialogDescription>{sale.number}</DialogDescription>
                        </DialogHeader>
                        <dl className="space-y-1 text-sm">
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">Total</dt>
                                <dd className="tabular-nums">
                                    {formatRupiah(sale.total)}
                                </dd>
                            </div>
                            {sale.method === 'split' ? (
                                <>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            Tunai
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(sale.cashAmount)}
                                        </dd>
                                    </div>
                                    <div className="flex justify-between">
                                        <dt className="text-muted-foreground">
                                            QRIS
                                        </dt>
                                        <dd className="tabular-nums">
                                            {formatRupiah(sale.qrisAmount)}
                                        </dd>
                                    </div>
                                </>
                            ) : (
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        {sale.method === 'qris'
                                            ? 'QRIS'
                                            : 'Tunai'}
                                    </dt>
                                    <dd className="tabular-nums">
                                        {formatRupiah(sale.paid)}
                                    </dd>
                                </div>
                            )}
                        </dl>
                        {sale.method === 'qris' || sale.method === 'split' ? (
                            <div className="rounded-lg bg-emerald-500/10 p-4 text-center text-sm text-emerald-700 dark:text-emerald-400">
                                Pembayaran QRIS diterima.
                            </div>
                        ) : (
                            <div className="rounded-lg bg-muted p-4 text-center">
                                <div className="text-sm text-muted-foreground">
                                    Kembalian
                                </div>
                                <div className="text-4xl font-bold tracking-tight tabular-nums">
                                    {formatRupiah(sale.change)}
                                </div>
                            </div>
                        )}
                        <div className="grid grid-cols-2 gap-2">
                            <Button variant="outline" onClick={onClose}>
                                Transaksi baru <Kbd>Esc</Kbd>
                            </Button>
                            <Button autoFocus onClick={() => onPrint(sale)}>
                                <Printer /> Cetak struk
                            </Button>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
