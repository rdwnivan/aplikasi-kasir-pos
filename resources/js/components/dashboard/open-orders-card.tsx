import { Link } from '@inertiajs/react';
import { ClipboardList } from 'lucide-react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { formatRupiah } from '@/lib/money';
import purchaseOrders from '@/routes/purchase-orders';

export type OpenOrders = {
    count: number;
    late: number;
    outstandingValue: number;
};

export function OpenOrdersCard({ count, late, outstandingValue }: OpenOrders) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <ClipboardList className="size-4 text-muted-foreground" />
                    PO belum selesai
                </CardTitle>
                <CardDescription>
                    {count > 0
                        ? 'Pesanan pembelian yang barangnya masih ditunggu.'
                        : 'Tidak ada barang yang masih ditunggu dari supplier.'}
                </CardDescription>
            </CardHeader>
            {count > 0 && (
                <CardContent className="space-y-3">
                    <dl className="grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Pesanan
                            </dt>
                            <dd className="text-lg font-semibold tabular-nums">
                                {count}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Terlambat
                            </dt>
                            <dd
                                className={
                                    late > 0
                                        ? 'text-lg font-semibold text-destructive tabular-nums'
                                        : 'text-lg font-semibold tabular-nums'
                                }
                            >
                                {late}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                Nilai sisa
                            </dt>
                            <dd className="text-lg font-semibold tabular-nums">
                                {formatRupiah(outstandingValue)}
                            </dd>
                        </div>
                    </dl>
                    <Link
                        href={purchaseOrders.index()}
                        className="text-sm text-muted-foreground hover:underline"
                    >
                        Lihat pesanan pembelian →
                    </Link>
                </CardContent>
            )}
        </Card>
    );
}
