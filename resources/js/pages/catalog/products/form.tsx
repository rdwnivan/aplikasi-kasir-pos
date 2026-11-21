import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { RupiahInput } from '@/components/pos/rupiah-input';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import categoriesRoutes from '@/routes/categories';
import products from '@/routes/products';
import unitsRoutes from '@/routes/units';
import type { Option } from '@/types';

type UnitRow = {
    unit_id: number | null;
    conversion: number;
    price: number;
    barcode: string;
};

type ProductData = {
    id: number;
    sku: string;
    name: string;
    category_id: number | null;
    base_unit_id: number;
    min_stock: number;
    is_active: boolean;
    stock: number;
    avgCost: number;
    hasHistory: boolean;
    units: UnitRow[];
};

type FormData = {
    sku: string;
    name: string;
    category_id: number | null;
    base_unit_id: number | null;
    min_stock: number;
    is_active: boolean;
    units: UnitRow[];
};

/** The base unit's row is always first; the server stores it with conversion 1. */
function initialUnits(product: ProductData | null): UnitRow[] {
    if (!product) {
        return [{ unit_id: null, conversion: 1, price: 0, barcode: '' }];
    }

    const base = product.units.find((u) => u.unit_id === product.base_unit_id);
    const others = product.units.filter(
        (u) => u.unit_id !== product.base_unit_id,
    );

    return [
        base ?? {
            unit_id: product.base_unit_id,
            conversion: 1,
            price: 0,
            barcode: '',
        },
        ...others,
    ];
}

export default function ProductForm({
    product,
    categories,
    units,
}: {
    product: ProductData | null;
    categories: Option[];
    units: Option[];
}) {
    const form = useForm<FormData>({
        sku: product?.sku ?? '',
        name: product?.name ?? '',
        category_id: product?.category_id ?? null,
        base_unit_id: product?.base_unit_id ?? null,
        min_stock: product?.min_stock ?? 0,
        is_active: product?.is_active ?? true,
        units: initialUnits(product),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const unitName = (id: number | null) =>
        units.find((u) => u.id === id)?.name ?? '';
    const baseName = unitName(form.data.base_unit_id);
    const baseLocked = product?.hasHistory ?? false;

    const setRow = (index: number, patch: Partial<UnitRow>) =>
        form.setData(
            'units',
            form.data.units.map((row, i) =>
                i === index ? { ...row, ...patch } : row,
            ),
        );

    const setBaseUnit = (id: number) => {
        form.setData((data) => ({
            ...data,
            base_unit_id: id,
            units: [
                { ...data.units[0], unit_id: id, conversion: 1 },
                ...data.units.slice(1).filter((row) => row.unit_id !== id),
            ],
        }));
    };

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            ...data,
            units: data.units.map((row, i) =>
                i === 0
                    ? { ...row, unit_id: data.base_unit_id, conversion: 1 }
                    : row,
            ),
        }));

        if (product) {
            form.submit(products.update(product.id));
        } else {
            form.submit(products.store());
        }
    };

    return (
        <>
            <Head title={product ? `Ubah ${product.name}` : 'Tambah produk'} />
            <form
                onSubmit={submit}
                className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4"
            >
                <PageHeader
                    title={product ? `Ubah ${product.name}` : 'Tambah produk'}
                    description={
                        product
                            ? `Stok ${formatQty(product.stock)} ${baseName} · HPP rata-rata ${formatRupiah(Math.round(product.avgCost))}. Stok hanya berubah lewat penerimaan barang, penjualan, retur, dan stok opname.`
                            : 'Stok awal dimasukkan lewat penerimaan barang setelah produk dibuat.'
                    }
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Data produk</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 sm:grid-cols-2">
                        <Field
                            label="Nama produk"
                            error={errors.name}
                            id="name"
                        >
                            <Input
                                id="name"
                                autoFocus
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                                placeholder="mis. Indomie Goreng 85g"
                            />
                        </Field>
                        <Field label="SKU" error={errors.sku} id="sku">
                            <Input
                                id="sku"
                                value={form.data.sku}
                                onChange={(e) =>
                                    form.setData('sku', e.target.value)
                                }
                                placeholder="mis. MIE-001"
                            />
                        </Field>
                        <Field
                            label="Kategori"
                            error={errors.category_id}
                            id="category"
                            hint={
                                <Link
                                    href={categoriesRoutes.index()}
                                    className="underline"
                                >
                                    Kelola kategori
                                </Link>
                            }
                        >
                            <Select
                                value={
                                    form.data.category_id
                                        ? String(form.data.category_id)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'category_id',
                                        v === 'none' ? null : Number(v),
                                    )
                                }
                            >
                                <SelectTrigger id="category" className="w-full">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">
                                        Tanpa kategori
                                    </SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                        <Field
                            label="Stok minimum (satuan dasar)"
                            error={errors.min_stock}
                            id="min_stock"
                        >
                            <Input
                                id="min_stock"
                                type="number"
                                min={0}
                                value={form.data.min_stock}
                                onChange={(e) =>
                                    form.setData(
                                        'min_stock',
                                        Number(e.target.value) || 0,
                                    )
                                }
                            />
                        </Field>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <Checkbox
                                checked={form.data.is_active}
                                onCheckedChange={(v) =>
                                    form.setData('is_active', v === true)
                                }
                            />
                            Aktif (muncul di layar kasir)
                        </label>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Satuan & harga jual</CardTitle>
                        <CardDescription>
                            Stok dicatat dalam satuan dasar. Satuan lain (pak,
                            dus) berisi sejumlah satuan dasar dan boleh punya
                            harga grosir sendiri.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        <Field
                            label="Satuan dasar"
                            error={errors.base_unit_id}
                            id="base_unit"
                            hint={
                                <Link
                                    href={unitsRoutes.index()}
                                    className="underline"
                                >
                                    Kelola satuan
                                </Link>
                            }
                        >
                            <Select
                                disabled={baseLocked}
                                value={
                                    form.data.base_unit_id
                                        ? String(form.data.base_unit_id)
                                        : undefined
                                }
                                onValueChange={(v) => setBaseUnit(Number(v))}
                            >
                                <SelectTrigger
                                    id="base_unit"
                                    className="sm:w-64"
                                >
                                    <SelectValue placeholder="Pilih satuan dasar" />
                                </SelectTrigger>
                                <SelectContent>
                                    {units.map((u) => (
                                        <SelectItem
                                            key={u.id}
                                            value={String(u.id)}
                                        >
                                            {u.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {baseLocked && (
                                <p className="text-xs text-muted-foreground">
                                    Terkunci karena produk sudah punya riwayat
                                    stok.
                                </p>
                            )}
                        </Field>

                        {errors.units && (
                            <Alert variant="destructive">
                                <AlertDescription>
                                    {errors.units}
                                </AlertDescription>
                            </Alert>
                        )}

                        <div className="space-y-3">
                            {form.data.units.map((row, i) => (
                                <div
                                    key={i}
                                    className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_7rem_1fr_1fr_auto] sm:items-start"
                                >
                                    <Field
                                        label="Satuan"
                                        error={errors[`units.${i}.unit_id`]}
                                    >
                                        {i === 0 ? (
                                            <Input
                                                disabled
                                                value={
                                                    baseName
                                                        ? `${baseName} (dasar)`
                                                        : 'Pilih satuan dasar'
                                                }
                                            />
                                        ) : (
                                            <Select
                                                value={
                                                    row.unit_id
                                                        ? String(row.unit_id)
                                                        : undefined
                                                }
                                                onValueChange={(v) =>
                                                    setRow(i, {
                                                        unit_id: Number(v),
                                                    })
                                                }
                                            >
                                                <SelectTrigger className="w-full">
                                                    <SelectValue placeholder="Pilih" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {units
                                                        .filter(
                                                            (u) =>
                                                                u.id !==
                                                                form.data
                                                                    .base_unit_id,
                                                        )
                                                        .map((u) => (
                                                            <SelectItem
                                                                key={u.id}
                                                                value={String(
                                                                    u.id,
                                                                )}
                                                            >
                                                                {u.name}
                                                            </SelectItem>
                                                        ))}
                                                </SelectContent>
                                            </Select>
                                        )}
                                    </Field>
                                    <Field
                                        label={`Isi (${baseName || 'dasar'})`}
                                        error={errors[`units.${i}.conversion`]}
                                    >
                                        <Input
                                            type="number"
                                            min={1}
                                            disabled={i === 0}
                                            value={i === 0 ? 1 : row.conversion}
                                            onChange={(e) =>
                                                setRow(i, {
                                                    conversion:
                                                        Number(
                                                            e.target.value,
                                                        ) || 0,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Harga jual"
                                        error={errors[`units.${i}.price`]}
                                    >
                                        <RupiahInput
                                            value={row.price}
                                            onValueChange={(v) =>
                                                setRow(i, { price: v })
                                            }
                                        />
                                    </Field>
                                    <Field
                                        label="Barcode"
                                        error={errors[`units.${i}.barcode`]}
                                    >
                                        <Input
                                            value={row.barcode}
                                            placeholder="opsional"
                                            onChange={(e) =>
                                                setRow(i, {
                                                    barcode: e.target.value,
                                                })
                                            }
                                            onKeyDown={(e) => {
                                                // Scanners end with Enter; don't submit the form.
                                                if (e.key === 'Enter') {
                                                    e.preventDefault();
                                                }
                                            }}
                                        />
                                    </Field>
                                    <div className="flex sm:pt-6">
                                        {i > 0 && (
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="icon"
                                                title="Hapus satuan"
                                                onClick={() =>
                                                    form.setData(
                                                        'units',
                                                        form.data.units.filter(
                                                            (_, j) => j !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                <Trash2 />
                                            </Button>
                                        )}
                                    </div>
                                </div>
                            ))}
                        </div>

                        <Button
                            type="button"
                            variant="outline"
                            onClick={() =>
                                form.setData('units', [
                                    ...form.data.units,
                                    {
                                        unit_id: null,
                                        conversion: 2,
                                        price: 0,
                                        barcode: '',
                                    },
                                ])
                            }
                        >
                            <Plus /> Tambah satuan
                        </Button>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={products.index()}>Batal</Link>
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        Simpan
                    </Button>
                </div>
            </form>
        </>
    );
}

function Field({
    label,
    error,
    id,
    hint,
    children,
}: {
    label: string;
    error?: string;
    id?: string;
    hint?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="grid content-start gap-1.5">
            <div className="flex items-baseline justify-between gap-2">
                <Label htmlFor={id}>{label}</Label>
                {hint && (
                    <span className="text-xs text-muted-foreground">
                        {hint}
                    </span>
                )}
            </div>
            {children}
            <InputError message={error} />
        </div>
    );
}

ProductForm.layout = {
    breadcrumbs: [{ title: 'Produk', href: products.index() }],
};
