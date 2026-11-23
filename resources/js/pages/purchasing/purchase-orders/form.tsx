import { Head, Link, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { ProductPicker } from '@/components/inventory/product-picker';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { formatQty } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import purchaseOrders from '@/routes/purchase-orders';
import type { Option, ProductOption, PurchaseOrderLine } from '@/types';

type Draft = {
    id: number;
    supplierId: number;
    expectedAt: string | null;
    note: string | null;
    items: PurchaseOrderLine[];
};

export default function PurchaseOrderForm({
    order,
    suggested,
    products,
    suppliers,
    lastPrices,
    today,
}: {
    order: Draft | null;
    suggested: PurchaseOrderLine[];
    products: ProductOption[];
    suppliers: Option[];
    lastPrices: Record<string, number>;
    today: string;
}) {
    const form = useForm<{
        supplier_id: number | null;
        expected_at: string;
        note: string;
        items: PurchaseOrderLine[];
    }>({
        supplier_id:
            order?.supplierId ??
            (suppliers.length === 1 ? suppliers[0].id : null),
        expected_at: order?.expectedAt ?? '',
        note: order?.note ?? '',
        items: order?.items ?? suggested,
    });
    const errors = form.errors as Record<string, string | undefined>;
    const byId = new Map(products.map((p) => [p.id, p]));
    const total = form.data.items.reduce(
        (sum, l) => sum + l.qty * l.unit_price,
        0,
    );

    /** Last price paid for this unit, else the current average HPP × conversion. */
    const priceFor = (product: ProductOption, unitId: number): number => {
        const last = lastPrices[`${product.id}-${unitId}`];

        if (last !== undefined) {
            return last;
        }

        const unit = product.units.find((u) => u.unitId === unitId);

        return Math.round(product.avgCost * (unit?.conversion ?? 1));
    };

    const setLine = (index: number, patch: Partial<PurchaseOrderLine>) =>
        form.setData(
            'items',
            form.data.items.map((l, i) =>
                i === index ? { ...l, ...patch } : l,
            ),
        );

    const addProduct = (product: ProductOption, scannedUnit: number | null) => {
        const unitId =
            scannedUnit ??
            product.units[product.units.length - 1]?.unitId ??
            product.units[0].unitId;
        const existing = form.data.items.findIndex(
            (l) => l.product_id === product.id && l.unit_id === unitId,
        );

        if (existing >= 0) {
            setLine(existing, { qty: form.data.items[existing].qty + 1 });

            return;
        }

        form.setData('items', [
            ...form.data.items,
            {
                product_id: product.id,
                unit_id: unitId,
                qty: 1,
                unit_price: priceFor(product, unitId),
            },
        ]);
    };

    const title = order ? 'Ubah draft pesanan' : 'Buat pesanan pembelian';

    return (
        <>
            <Head title={title} />
            <form
                className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.transform((data) => ({
                        ...data,
                        expected_at: data.expected_at || null,
                        note: data.note || null,
                    }));
                    form.submit(
                        order
                            ? purchaseOrders.update(order.id)
                            : purchaseOrders.store(),
                    );
                }}
            >
                <PageHeader
                    title={title}
                    description="Disimpan sebagai draft dulu. Setelah dipesan, baris tidak bisa diubah lagi dan barangnya diterima dari halaman pesanan."
                />

                {suggested.length > 0 && !order && (
                    <p className="rounded-lg border border-amber-500/40 bg-amber-500/5 p-3 text-sm">
                        {suggested.length} produk di bawah stok minimum sudah
                        dimasukkan, cukup sampai 2× stok minimum. Hapus baris
                        yang bukan dari supplier ini.
                    </p>
                )}

                <Card>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="supplier">Supplier</Label>
                            <Select
                                value={
                                    form.data.supplier_id
                                        ? String(form.data.supplier_id)
                                        : undefined
                                }
                                onValueChange={(v) =>
                                    form.setData('supplier_id', Number(v))
                                }
                            >
                                <SelectTrigger id="supplier" className="w-full">
                                    <SelectValue placeholder="Pilih supplier" />
                                </SelectTrigger>
                                <SelectContent>
                                    {suppliers.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.supplier_id} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="expected_at">Perkiraan tiba</Label>
                            <Input
                                id="expected_at"
                                type="date"
                                min={today}
                                value={form.data.expected_at}
                                onChange={(e) =>
                                    form.setData('expected_at', e.target.value)
                                }
                            />
                            <InputError message={errors.expected_at} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="note">Catatan</Label>
                            <Input
                                id="note"
                                placeholder="mis. dipesan lewat WhatsApp"
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
                        <ProductPicker
                            products={products}
                            onPick={addProduct}
                            autoFocus={!order && suggested.length === 0}
                        />
                        <InputError message={errors.items ?? errors.order} />

                        {form.data.items.length === 0 && (
                            <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Cari produk atau scan barcode untuk menambah
                                baris.
                            </p>
                        )}

                        {form.data.items.map((line, i) => {
                            const product = byId.get(line.product_id);

                            if (!product) {
                                return null;
                            }

                            const unit = product.units.find(
                                (u) => u.unitId === line.unit_id,
                            );

                            return (
                                <div
                                    key={`${line.product_id}-${i}`}
                                    className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[9rem_7rem_11rem_minmax(0,1fr)_auto] sm:items-start"
                                >
                                    <div className="min-w-0 sm:col-span-5">
                                        <p className="truncate font-medium">
                                            {product.name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {product.sku} · stok{' '}
                                            {formatQty(product.stock)}{' '}
                                            {product.baseUnit}
                                        </p>
                                        <InputError
                                            message={
                                                errors[`items.${i}.unit_id`] ??
                                                errors[`items.${i}.product_id`]
                                            }
                                        />
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs sm:sr-only">
                                            Satuan
                                        </Label>
                                        <Select
                                            value={String(line.unit_id)}
                                            onValueChange={(v) =>
                                                setLine(i, {
                                                    unit_id: Number(v),
                                                    unit_price: priceFor(
                                                        product,
                                                        Number(v),
                                                    ),
                                                })
                                            }
                                        >
                                            <SelectTrigger className="w-full">
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {product.units.map((u) => (
                                                    <SelectItem
                                                        key={u.unitId}
                                                        value={String(u.unitId)}
                                                    >
                                                        {u.name}
                                                        {u.conversion > 1 &&
                                                            ` (${u.conversion})`}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label className="text-xs sm:sr-only">
                                            Jumlah
                                        </Label>
                                        <Input
                                            type="number"
                                            min={1}
                                            aria-label="Jumlah"
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
                                            Harga beli / {unit?.name}
                                        </Label>
                                        <RupiahInput
                                            aria-label="Harga beli"
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
                                        {unit && unit.conversion > 1 && (
                                            <p className="text-xs text-muted-foreground tabular-nums">
                                                ={' '}
                                                {formatQty(
                                                    line.qty * unit.conversion,
                                                )}{' '}
                                                {product.baseUnit}
                                            </p>
                                        )}
                                    </div>
                                    <Button
                                        type="button"
                                        variant="ghost"
                                        size="icon"
                                        title="Hapus baris"
                                        onClick={() =>
                                            form.setData(
                                                'items',
                                                form.data.items.filter(
                                                    (_, j) => j !== i,
                                                ),
                                            )
                                        }
                                    >
                                        <Trash2 />
                                    </Button>
                                </div>
                            );
                        })}

                        {form.data.items.length > 0 && (
                            <div className="flex justify-end gap-6 border-t pt-3 text-base font-semibold">
                                <span>Total</span>
                                <span className="tabular-nums">
                                    {formatRupiah(total)}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link
                            href={
                                order
                                    ? purchaseOrders.show(order.id)
                                    : purchaseOrders.index()
                            }
                        >
                            Batal
                        </Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            form.data.items.length === 0 ||
                            !form.data.supplier_id
                        }
                    >
                        Simpan draft
                    </Button>
                </div>
            </form>
        </>
    );
}

PurchaseOrderForm.layout = {
    breadcrumbs: [
        { title: 'Pesanan pembelian', href: purchaseOrders.index() },
        { title: 'Draft', href: '#' },
    ],
};
