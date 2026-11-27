import { Head, Link, router } from '@inertiajs/react';
import { Pencil, Send, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { OpnameStatus } from '@/components/inventory/opname-status';
import { PageHeader } from '@/components/page-header';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { toastFirstError } from '@/lib/errors';
import { formatDateTime, formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import products from '@/routes/products';
import stockOpnames from '@/routes/stock-opnames';

type Opname = {
    id: number;
    number: string;
    status: 'draft' | 'posted';
    note: string | null;
    user: string;
    createdAt: string | null;
    postedAt: string | null;
    items: {
        id: number;
        productId: number;
        product: string;
        sku: string;
        baseUnit: string;
        systemQty: number;
        countedQty: number;
        difference: number;
        value: number;
        /** Per-batch counts of a product that tracks expiry (DESIGN.md §19). */
        batches: {
            id: number;
            label: string;
            systemQty: number;
            countedQty: number;
            difference: number;
        }[];
    }[];
};

export default function StockOpnameShow({ opname }: { opname: Opname }) {
    const [confirm, setConfirm] = useState<'post' | 'delete' | null>(null);
    const [processing, setProcessing] = useState(false);
    const draft = opname.status === 'draft';
    const totalValue = opname.items.reduce((s, i) => s + i.value, 0);
    const changed = opname.items.filter((i) => i.difference !== 0).length;

    const run = (action: 'post' | 'delete') => {
        const options = {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onError: toastFirstError,
            onFinish: () => {
                setProcessing(false);
                setConfirm(null);
            },
        };

        if (action === 'post') {
            router.post(stockOpnames.post.url(opname.id), {}, options);
        } else {
            router.delete(stockOpnames.destroy.url(opname.id), options);
        }
    };

    return (
        <>
            <Head title={opname.number} />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title={`Stok opname ${opname.number}`}
                    description={
                        <span className="inline-flex flex-wrap items-center gap-2">
                            <OpnameStatus status={opname.status} />
                            Dibuat {formatDateTime(opname.createdAt)} oleh{' '}
                            {opname.user}
                            {opname.postedAt &&
                                ` · diposting ${formatDateTime(opname.postedAt)}`}
                        </span>
                    }
                    actions={
                        draft && (
                            <>
                                <Button
                                    variant="ghost"
                                    onClick={() => setConfirm('delete')}
                                >
                                    <Trash2 /> Hapus draft
                                </Button>
                                <Button variant="outline" asChild>
                                    <Link href={stockOpnames.edit(opname.id)}>
                                        <Pencil /> Ubah
                                    </Link>
                                </Button>
                                <Button onClick={() => setConfirm('post')}>
                                    <Send /> Posting
                                </Button>
                            </>
                        )
                    }
                />

                {opname.note && (
                    <p className="text-sm text-muted-foreground">
                        Catatan: {opname.note}
                    </p>
                )}

                {draft && (
                    <Alert>
                        <AlertDescription>
                            Ini masih draft. Stok sistem di bawah adalah stok
                            saat ini dan bisa berubah karena penjualan; selisih
                            final dihitung saat posting.
                        </AlertDescription>
                    </Alert>
                )}

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Stok sistem
                                </TableHead>
                                <TableHead className="text-right">
                                    Hasil hitung
                                </TableHead>
                                <TableHead className="text-right">
                                    Selisih
                                </TableHead>
                                <TableHead className="text-right">
                                    Nilai selisih (HPP)
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {opname.items.map((item) => (
                                <Fragment key={item.id}>
                                    <TableRow>
                                        <TableCell>
                                            <Link
                                                href={products.stockCard(
                                                    item.productId,
                                                )}
                                                className="font-medium hover:underline"
                                            >
                                                {item.product}
                                            </Link>
                                            <div className="text-xs text-muted-foreground">
                                                {item.sku}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(item.systemQty)}{' '}
                                            {item.baseUnit}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(item.countedQty)}{' '}
                                            {item.baseUnit}
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-medium tabular-nums',
                                                item.difference < 0 &&
                                                    'text-destructive',
                                                item.difference > 0 &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                            )}
                                        >
                                            {item.difference === 0
                                                ? 'pas'
                                                : `${item.difference > 0 ? '+' : ''}${formatQty(item.difference)}`}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {item.value === 0
                                                ? '–'
                                                : formatRupiah(item.value)}
                                        </TableCell>
                                    </TableRow>
                                    {item.batches.map((b) => (
                                        <TableRow
                                            key={`b-${b.id}`}
                                            className="bg-muted/30 text-sm"
                                        >
                                            <TableCell className="pl-8 text-muted-foreground">
                                                Kedaluwarsa {b.label}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatQty(b.systemQty)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {formatQty(b.countedQty)}
                                            </TableCell>
                                            <TableCell className="text-right tabular-nums">
                                                {b.difference === 0
                                                    ? 'pas'
                                                    : `${b.difference > 0 ? '+' : ''}${formatQty(b.difference)}`}
                                            </TableCell>
                                            <TableCell />
                                        </TableRow>
                                    ))}
                                </Fragment>
                            ))}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={3}>
                                    {opname.items.length} produk · {changed} ada
                                    selisih
                                </TableCell>
                                <TableCell className="text-right">
                                    Total
                                </TableCell>
                                <TableCell
                                    className={cn(
                                        'text-right tabular-nums',
                                        totalValue < 0 && 'text-destructive',
                                    )}
                                >
                                    {formatRupiah(totalValue)}
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>

            <ConfirmDialog
                open={confirm === 'post'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Posting ${opname.number}?`}
                description="Stok sistem tiap produk akan disamakan dengan hasil hitung, dan selisihnya dicatat ke kartu stok. Setelah diposting, dokumen tidak bisa diubah."
                confirmLabel="Posting"
                processing={processing}
                onConfirm={() => run('post')}
            />
            <ConfirmDialog
                open={confirm === 'delete'}
                onOpenChange={(open) => !open && setConfirm(null)}
                title={`Hapus draft ${opname.number}?`}
                description="Hasil hitung di draft ini akan hilang. Stok tidak berubah."
                confirmLabel="Hapus"
                destructive
                processing={processing}
                onConfirm={() => run('delete')}
            />
        </>
    );
}

StockOpnameShow.layout = {
    breadcrumbs: [
        { title: 'Stok opname', href: stockOpnames.index() },
        { title: 'Detail', href: '#' },
    ],
};
