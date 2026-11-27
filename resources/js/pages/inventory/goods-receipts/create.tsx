import { Head, Link, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { ExpiryBatchesInput } from '@/components/inventory/expiry';
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
import goodsReceipts from '@/routes/goods-receipts';
import type { BatchInput, Option, ProductOption } from '@/types';

type Line = {
    product_id: number;
    unit_id: number;
    qty: number;
    unit_price: number;
    /** Only for products that track expiry (DESIGN.md §19). */
    batches?: BatchInput[];
};

/** A line's quantity changed: a single expiry date follows it. */
function withQty(line: Line, qty: number): Partial<Line> {
    return line.batches?.length === 1
        ? { qty, batches: [{ ...line.batches[0], qty }] }
        : { qty };
}

/** Suggested buy price for a unit: current average HPP × conversion. */
function suggestedPrice(product: ProductOption, unitId: number): number {
    const unit = product.units.find((u) => u.unitId === unitId);

    return Math.round(product.avgCost * (unit?.conversion ?? 1));
}

export default function GoodsReceiptCreate({
    products,
    suppliers,
    today,
}: {
    products: ProductOption[];
    suppliers: Option[];
    today: string;
}) {
    const form = useForm<{
        supplier_id: number | null;
        received_at: string;
        note: string;
        items: Line[];
    }>({
        supplier_id: suppliers.length === 1 ? suppliers[0].id : null,
        received_at: today,
        note: '',
        items: [],
    });
    const errors = form.errors as Record<string, string | undefined>;
    const byId = new Map(products.map((p) => [p.id, p]));
    const total = form.data.items.reduce(
        (sum, l) => sum + l.qty * l.unit_price,
        0,
    );

    const setLine = (index: number, patch: Partial<Line>) =>
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
            const line = form.data.items[existing];
            setLine(existing, withQty(line, line.qty + 1));

            return;
        }

        form.setData('items', [
            ...form.data.items,
            {
                product_id: product.id,
                unit_id: unitId,
                qty: 1,
                unit_price: suggestedPrice(product, unitId),
                ...(product.tracksExpiry && {
                    batches: [{ expires_on: '', code: '', qty: 1 }],
                }),
            },
        ]);
    };

    return (
        <>
            <Head title="Terima barang" />
            <form
                className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.submit(goodsReceipts.store());
                }}
            >
                <PageHeader
                    title="Terima barang"
                    description="Masukkan barang yang datang. Harga beli per satuan yang dipilih; stok dan HPP rata-rata diperbarui saat disimpan."
                />

                <Card>
                    <CardContent className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="supplier">Supplier</Label>
                            <Select
                                value={
                                    form.data.supplier_id
                                        ? String(form.data.supplier_id)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'supplier_id',
                                        v === 'none' ? null : Number(v),
                                    )
                                }
                            >
                                <SelectTrigger id="supplier" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Tanpa supplier
                                    </SelectItem>
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
                        <div className="grid gap-1.5">
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
                        <ProductPicker
                            products={products}
                            onPick={addProduct}
                            autoFocus
                        />
                        <InputError message={errors.items} />

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
                                                    unit_price: suggestedPrice(
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
                                                setLine(
                                                    i,
                                                    withQty(
                                                        line,
                                                        Number(
                                                            e.target.value,
                                                        ) || 0,
                                                    ),
                                                )
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
                                    {line.batches && (
                                        <div className="sm:col-span-5">
                                            <ExpiryBatchesInput
                                                batches={line.batches}
                                                lineQty={line.qty}
                                                unit={unit?.name ?? ''}
                                                minDate={form.data.received_at}
                                                error={
                                                    errors[`items.${i}.batches`]
                                                }
                                                onChange={(batches) =>
                                                    setLine(i, { batches })
                                                }
                                            />
                                        </div>
                                    )}
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
                        <Link href={goodsReceipts.index()}>Batal</Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing || form.data.items.length === 0
                        }
                    >
                        Simpan penerimaan
                    </Button>
                </div>
            </form>
        </>
    );
}

GoodsReceiptCreate.layout = {
    breadcrumbs: [
        { title: 'Penerimaan barang', href: goodsReceipts.index() },
        { title: 'Terima barang', href: goodsReceipts.create() },
    ],
};
