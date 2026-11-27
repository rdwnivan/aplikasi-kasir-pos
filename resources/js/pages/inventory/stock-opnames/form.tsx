import { Head, Link, useForm } from '@inertiajs/react';
import { Plus, Trash2 } from 'lucide-react';
import { Fragment, useState } from 'react';
import { BatchStatusBadge, batchLabel } from '@/components/inventory/expiry';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatQty } from '@/lib/format';
import { cn } from '@/lib/utils';
import stockOpnames from '@/routes/stock-opnames';
import type { Option, ProductOption } from '@/types';

type BatchCount = {
    stock_batch_id: number | null;
    expires_on: string | null;
    code: string | null;
    counted_qty: number;
};

type Draft = {
    id: number;
    number: string;
    note: string | null;
    counts: Record<string, number>;
    /** Per-batch counts of products that track expiry (DESIGN.md §19). */
    batchCounts: Record<string, BatchCount[]>;
};

/** A batch found on the shelf that the system does not know yet. */
type FoundBatch = { expires_on: string; code: string; raw: string };

type ProductBatches = {
    /** batch id → counted, blank = not counted (left as it is) */
    known: Record<number, string>;
    found: FoundBatch[];
};

const digits = (value: string) => value.replace(/[^\d]/g, '');

/** What a tracked product's batch counts add up to, and the system stock of those batches. */
function batchTotals(product: ProductOption, b: ProductBatches | undefined) {
    let counted = 0;
    let system = 0;
    let any = false;

    for (const batch of product.batches) {
        const raw = b?.known[batch.id] ?? '';

        if (raw !== '') {
            any = true;
            counted += Number(raw);
            system += batch.qty;
        }
    }

    for (const f of b?.found ?? []) {
        if (f.raw !== '') {
            any = true;
            counted += Number(f.raw);
        }
    }

    return { any, counted, system };
}

export default function StockOpnameForm({
    opname,
    products,
    categories,
}: {
    opname: Draft | null;
    products: ProductOption[];
    categories: Option[];
}) {
    // Blank = not counted (left out of the opname); "0" = counted, none on the shelf.
    const [counts, setCounts] = useState<Record<number, string>>(() =>
        Object.fromEntries(
            Object.entries(opname?.counts ?? {}).map(([id, qty]) => [
                Number(id),
                String(qty),
            ]),
        ),
    );
    const [batches, setBatches] = useState<Record<number, ProductBatches>>(() =>
        Object.fromEntries(
            Object.entries(opname?.batchCounts ?? {}).map(([id, lines]) => [
                Number(id),
                {
                    known: Object.fromEntries(
                        lines
                            .filter((l) => l.stock_batch_id !== null)
                            .map((l) => [
                                l.stock_batch_id,
                                String(l.counted_qty),
                            ]),
                    ),
                    found: lines
                        .filter((l) => l.stock_batch_id === null)
                        .map((l) => ({
                            expires_on: l.expires_on ?? '',
                            code: l.code ?? '',
                            raw: String(l.counted_qty),
                        })),
                },
            ]),
        ),
    );
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string>('all');
    const [onlyCounted, setOnlyCounted] = useState(false);
    const form = useForm<{
        note: string;
        items: { product_id: number; counted_qty: number }[];
    }>({ note: opname?.note ?? '', items: [] });
    const errors = form.errors as Record<string, string | undefined>;

    const isCounted = (p: ProductOption) =>
        p.tracksExpiry
            ? batchTotals(p, batches[p.id]).any
            : (counts[p.id] ?? '') !== '';

    const q = query.trim().toLowerCase();
    const visible = products.filter(
        (p) =>
            (category === 'all' || String(p.categoryId) === category) &&
            (!onlyCounted || isCounted(p)) &&
            (q === '' ||
                p.name.toLowerCase().includes(q) ||
                p.sku.toLowerCase().includes(q) ||
                p.units.some((u) => u.barcode === query.trim())),
    );

    const counted = products.filter(isCounted);
    const withDifference = counted.filter((p) => {
        if (p.tracksExpiry) {
            const t = batchTotals(p, batches[p.id]);

            return t.counted !== t.system;
        }

        return Number(counts[p.id]) !== p.stock;
    }).length;

    const setProductBatches = (
        productId: number,
        patch: Partial<ProductBatches>,
    ) =>
        setBatches({
            ...batches,
            [productId]: {
                known: batches[productId]?.known ?? {},
                found: batches[productId]?.found ?? [],
                ...patch,
            },
        });

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            note: data.note,
            items: counted.map((p) => {
                if (!p.tracksExpiry) {
                    return {
                        product_id: p.id,
                        counted_qty: Number(counts[p.id]),
                    };
                }

                const b = batches[p.id];

                return {
                    product_id: p.id,
                    counted_qty: batchTotals(p, b).counted,
                    batches: [
                        ...p.batches
                            .filter(
                                (batch) => (b?.known[batch.id] ?? '') !== '',
                            )
                            .map((batch) => ({
                                stock_batch_id: batch.id,
                                counted_qty: Number(b?.known[batch.id]),
                            })),
                        ...(b?.found ?? [])
                            .filter((f) => f.raw !== '')
                            .map((f) => ({
                                expires_on: f.expires_on,
                                code: f.code || null,
                                counted_qty: Number(f.raw),
                            })),
                    ],
                };
            }),
        }));

        if (opname) {
            form.submit(stockOpnames.update(opname.id));
        } else {
            form.submit(stockOpnames.store());
        }
    };

    return (
        <>
            <Head
                title={opname ? `Ubah ${opname.number}` : 'Mulai stok opname'}
            />
            <form
                onSubmit={submit}
                className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-4 p-4"
            >
                <PageHeader
                    title={
                        opname
                            ? `Ubah draft ${opname.number}`
                            : 'Mulai stok opname'
                    }
                    description="Isi hasil hitung fisik dalam satuan dasar. Kosongkan produk yang tidak dihitung. Stok baru berubah saat draft diposting."
                />

                <div className="flex flex-wrap items-center gap-2">
                    <Input
                        className="w-full sm:w-64"
                        placeholder="Cari nama, SKU, atau scan barcode…"
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        onKeyDown={(e) =>
                            e.key === 'Enter' && e.preventDefault()
                        }
                    />
                    <Select value={category} onValueChange={setCategory}>
                        <SelectTrigger className="w-full sm:w-48">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">Semua kategori</SelectItem>
                            {categories.map((c) => (
                                <SelectItem key={c.id} value={String(c.id)}>
                                    {c.name}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <label className="flex items-center gap-2 text-sm">
                        <Checkbox
                            checked={onlyCounted}
                            onCheckedChange={(v) => setOnlyCounted(v === true)}
                        />
                        Hanya yang sudah dihitung
                    </label>
                </div>

                <InputError message={errors.items} />

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead className="text-right">
                                    Stok sistem
                                </TableHead>
                                <TableHead className="w-36">
                                    Hasil hitung
                                </TableHead>
                                <TableHead className="text-right">
                                    Selisih
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {visible.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada produk yang cocok.
                                    </TableCell>
                                </TableRow>
                            )}
                            {visible.map((p) => {
                                if (p.tracksExpiry) {
                                    return (
                                        <BatchRows
                                            key={p.id}
                                            product={p}
                                            value={batches[p.id]}
                                            error={
                                                errors[`items.${p.id}.batches`]
                                            }
                                            onChange={(patch) =>
                                                setProductBatches(p.id, patch)
                                            }
                                        />
                                    );
                                }

                                const raw = counts[p.id] ?? '';
                                const diff =
                                    raw === '' ? null : Number(raw) - p.stock;

                                return (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {p.name}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {p.sku}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatQty(p.stock)} {p.baseUnit}
                                        </TableCell>
                                        <TableCell>
                                            <Input
                                                type="number"
                                                min={0}
                                                inputMode="numeric"
                                                aria-label={`Hasil hitung ${p.name}`}
                                                value={raw}
                                                onChange={(e) =>
                                                    setCounts({
                                                        ...counts,
                                                        [p.id]: e.target.value.replace(
                                                            /[^\d]/g,
                                                            '',
                                                        ),
                                                    })
                                                }
                                                onKeyDown={(e) =>
                                                    e.key === 'Enter' &&
                                                    e.preventDefault()
                                                }
                                            />
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-medium tabular-nums',
                                                diff !== null &&
                                                    diff < 0 &&
                                                    'text-destructive',
                                                diff !== null &&
                                                    diff > 0 &&
                                                    'text-emerald-600 dark:text-emerald-400',
                                            )}
                                        >
                                            {diff === null
                                                ? ''
                                                : diff === 0
                                                  ? 'pas'
                                                  : `${diff > 0 ? '+' : ''}${formatQty(diff)}`}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <div className="grid gap-1.5">
                    <label htmlFor="note" className="text-sm font-medium">
                        Catatan
                    </label>
                    <Input
                        id="note"
                        placeholder="mis. opname rak minuman akhir bulan"
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                    />
                    <InputError message={errors.note} />
                </div>

                <div className="sticky bottom-0 flex flex-wrap items-center justify-between gap-3 rounded-xl border bg-background/95 p-3 backdrop-blur">
                    <p className="text-sm text-muted-foreground">
                        {counted.length} produk dihitung · {withDifference} ada
                        selisih
                    </p>
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link
                                href={
                                    opname
                                        ? stockOpnames.show(opname.id)
                                        : stockOpnames.index()
                                }
                            >
                                Batal
                            </Link>
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || counted.length === 0}
                        >
                            Simpan draft
                        </Button>
                    </div>
                </div>
            </form>
        </>
    );
}

function Difference({ diff }: { diff: number | null }) {
    return (
        <TableCell
            className={cn(
                'text-right font-medium tabular-nums',
                diff !== null && diff < 0 && 'text-destructive',
                diff !== null &&
                    diff > 0 &&
                    'text-emerald-600 dark:text-emerald-400',
            )}
        >
            {diff === null
                ? ''
                : diff === 0
                  ? 'pas'
                  : `${diff > 0 ? '+' : ''}${formatQty(diff)}`}
        </TableCell>
    );
}

/**
 * A product that tracks expiry is counted per batch (DESIGN.md §19): one row per open batch
 * (blank = not counted, left as it is) plus batches found on the shelf with their date.
 */
function BatchRows({
    product,
    value,
    error,
    onChange,
}: {
    product: ProductOption;
    value: ProductBatches | undefined;
    error?: string;
    onChange: (patch: Partial<ProductBatches>) => void;
}) {
    const known = value?.known ?? {};
    const found = value?.found ?? [];
    const totals = batchTotals(product, value);
    const setFound = (index: number, patch: Partial<FoundBatch>) =>
        onChange({
            found: found.map((f, i) => (i === index ? { ...f, ...patch } : f)),
        });

    return (
        <Fragment>
            <TableRow className="border-b-0">
                <TableCell>
                    <div className="font-medium">{product.name}</div>
                    <div className="text-xs text-muted-foreground">
                        {product.sku} · dihitung per tanggal kedaluwarsa
                    </div>
                    <InputError message={error} />
                </TableCell>
                <TableCell className="text-right tabular-nums">
                    {formatQty(product.stock)} {product.baseUnit}
                </TableCell>
                <TableCell className="text-sm text-muted-foreground tabular-nums">
                    {totals.any
                        ? `${formatQty(totals.counted)} ${product.baseUnit}`
                        : ''}
                </TableCell>
                <Difference
                    diff={totals.any ? totals.counted - totals.system : null}
                />
            </TableRow>
            {product.batches.map((b) => {
                const raw = known[b.id] ?? '';

                return (
                    <TableRow key={b.id} className="border-b-0 bg-muted/30">
                        <TableCell className="pl-8 text-sm">
                            <span className="mr-2">
                                {batchLabel(b.expiresOn, b.code)}
                            </span>
                            <BatchStatusBadge status={b.status} />
                        </TableCell>
                        <TableCell className="text-right text-sm tabular-nums">
                            {formatQty(b.qty)}
                        </TableCell>
                        <TableCell>
                            <Input
                                type="number"
                                min={0}
                                inputMode="numeric"
                                aria-label={`Hasil hitung ${product.name} ${batchLabel(b.expiresOn, b.code)}`}
                                value={raw}
                                onChange={(e) =>
                                    onChange({
                                        known: {
                                            ...known,
                                            [b.id]: digits(e.target.value),
                                        },
                                    })
                                }
                                onKeyDown={(e) =>
                                    e.key === 'Enter' && e.preventDefault()
                                }
                            />
                        </TableCell>
                        <Difference
                            diff={raw === '' ? null : Number(raw) - b.qty}
                        />
                    </TableRow>
                );
            })}
            {found.map((f, i) => (
                <TableRow key={`found-${i}`} className="border-b-0 bg-muted/30">
                    <TableCell className="pl-8">
                        <div className="flex gap-2">
                            <Input
                                type="date"
                                aria-label="Tanggal kedaluwarsa batch ditemukan"
                                value={f.expires_on}
                                onChange={(e) =>
                                    setFound(i, { expires_on: e.target.value })
                                }
                            />
                            <Input
                                aria-label="Kode batch"
                                placeholder="Kode (opsional)"
                                value={f.code}
                                onChange={(e) =>
                                    setFound(i, { code: e.target.value })
                                }
                            />
                        </div>
                    </TableCell>
                    <TableCell className="text-right text-sm text-muted-foreground">
                        baru
                    </TableCell>
                    <TableCell>
                        <div className="flex gap-1">
                            <Input
                                type="number"
                                min={0}
                                inputMode="numeric"
                                aria-label="Hasil hitung batch ditemukan"
                                value={f.raw}
                                onChange={(e) =>
                                    setFound(i, { raw: digits(e.target.value) })
                                }
                                onKeyDown={(e) =>
                                    e.key === 'Enter' && e.preventDefault()
                                }
                            />
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                title="Hapus batch ini"
                                onClick={() =>
                                    onChange({
                                        found: found.filter((_, j) => j !== i),
                                    })
                                }
                            >
                                <Trash2 />
                            </Button>
                        </div>
                    </TableCell>
                    <Difference diff={f.raw === '' ? null : Number(f.raw)} />
                </TableRow>
            ))}
            <TableRow className="bg-muted/30">
                <TableCell colSpan={4} className="pl-6">
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={() =>
                            onChange({
                                found: [
                                    ...found,
                                    { expires_on: '', code: '', raw: '' },
                                ],
                            })
                        }
                    >
                        <Plus /> Batch ditemukan
                    </Button>
                </TableCell>
            </TableRow>
        </Fragment>
    );
}

StockOpnameForm.layout = {
    breadcrumbs: [
        { title: 'Stok opname', href: stockOpnames.index() },
        { title: 'Draft', href: '#' },
    ],
};
