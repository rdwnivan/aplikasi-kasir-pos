import { Head, Link, useForm } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import purchaseOrders from '@/routes/purchase-orders';

type Order = {
    id: number;
    number: string;
    supplier: string;
    items: {
        id: number;
        product: string;
        sku: string;
        unit: string;
        baseUnit: string;
        conversion: number;
        qty: number;
        received: number;
        remaining: number;
        unitPrice: number;
    }[];
};

type Line = { purchase_order_item_id: number; qty: number; unit_price: number };

export default function PurchaseOrderReceive({
    order,
    today,
}: {
    order: Order;
    today: string;
}) {
    // Every line starts at what is still outstanding; a line already in full stays at 0.
    const form = useForm<{ received_at: string; note: string; items: Line[] }>({
        received_at: today,
        note: '',
        items: order.items.map((i) => ({
            purchase_order_item_id: i.id,
            qty: i.remaining,
            unit_price: i.unitPrice,
        })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const total = form.data.items.reduce(
        (sum, l) => sum + l.qty * l.unit_price,
        0,
    );
    const anything = form.data.items.some((l) => l.qty > 0);

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            form.data.items.map((l, i) =>
                i === index ? { ...l, ...patch } : l,
            ),
        );

    return (
        <>
            <Head title={`Terima ${order.number}`} />
            <form
                className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        note: data.note || null,
                    }));
                    form.submit(purchaseOrders.receive.store(order.id));
                }}
            >
                <PageHeader
                    title={`Terima barang ${order.number}`}
                    description={`Dari ${order.supplier}. Isi jumlah yang benar-benar datang (boleh sebagian) dan harga sesuai faktur; HPP dihitung dari harga faktur.`}
                />

                <Card>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="received_at">Tanggal terima</Label>
                            <Input
                                id="received_at"
                                type="date"
                                max={today}
                                value={form.data.received_at}
                                onChange={(e) =>
                                    form.setData('received_at', e.target.value)
                                }
                            />
                            <InputError message={errors.received_at} />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="note">Catatan</Label>
                            <Input
                                id="note"
                                placeholder="mis. no. faktur supplier"
                                value={form.data.note}
                                onChange={(e) =>
                                    form.setData('note', e.target.value)
                                }
                            />
                            <InputError message={errors.note} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Barang</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <InputError message={errors.items ?? errors.order} />

                        {order.items.map((item, i) => {
                            const line = form.data.items[i];
                            const done = item.remaining === 0;

                            return (
                                <div
                                    key={item.id}
                                    className={cn(
                                        'grid gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_8rem_11rem_8rem] sm:items-start',
                                        done && 'opacity-60',
                                    )}
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {item.product}
                                        </p>
                                        <p className="text-xs text-muted-foreground tabular-nums">
                                            {item.sku} · dipesan{' '}
                                            {formatQty(item.qty)} {item.unit}
                                            {item.received > 0 &&
                                                `, sudah diterima ${formatQty(item.received)}`}
                                            {' · '}
                                            {done
                                                ? 'lengkap'
                                                : `sisa ${formatQty(item.remaining)}`}
                                        </p>
                                        <InputError
                                            message={
                                                errors[
                                                    `items.${i}.purchase_order_item_id`
                                                ]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs sm:sr-only">
                                            Jumlah diterima ({item.unit})
                                        </Label>
                                        <Input
                                            type="number"
                                            min={0}
                                            max={item.remaining}
                                            disabled={done}
                                            aria-label={`Jumlah diterima ${item.product}`}
                                            value={line.qty}
                                            onChange={(e) =>
                                                setLine(i, {
                                                    qty:
                                                        Number(
                                                            e.target.value,
                                                        ) || 0,
                                                })
                                            }
                                        />
                                        <InputError
                                            message={errors[`items.${i}.qty`]}
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs sm:sr-only">
                                            Harga faktur / {item.unit}
                                        </Label>
                                        <RupiahInput
                                            aria-label={`Harga faktur ${item.product}`}
                                            disabled={done}
                                            value={line.unit_price}
                                            onValueChange={(v) =>
                                                setLine(i, { unit_price: v })
                                            }
                                        />
                                        <InputError
                                            message={
                                                errors[`items.${i}.unit_price`]
                                            }
                                        />
                                    </div>
                                    <div className="text-right text-sm sm:pt-2">
                                        <p className="font-medium tabular-nums">
                                            {formatRupiah(
                                                line.qty * line.unit_price,
                                            )}
                                        </p>
                                        {item.conversion > 1 &&
                                            line.qty > 0 && (
                                                <p className="text-xs text-muted-foreground tabular-nums">
                                                    ={' '}
                                                    {formatQty(
                                                        line.qty *
                                                            item.conversion,
                                                    )}{' '}
                                                    {item.baseUnit}
                                                </p>
                                            )}
                                    </div>
                                </div>
                            );
                        })}

                        <div className="flex justify-end gap-6 border-t pt-3 text-base font-semibold">
                            <span>Total diterima</span>
                            <span className="tabular-nums">
                                {formatRupiah(total)}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={purchaseOrders.show(order.id)}>Batal</Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={form.processing || !anything}
                    >
                        Simpan penerimaan
                    </Button>
                </div>
            </form>
        </>
    );
}

PurchaseOrderReceive.layout = {
    breadcrumbs: [
        { title: 'Pesanan pembelian', href: purchaseOrders.index() },
        { title: 'Terima barang', href: '#' },
    ],
};
