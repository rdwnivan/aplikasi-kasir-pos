import { Head, Link, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
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

type Draft = {
    id: number;
    number: string;
    note: string | null;
    counts: Record<string, number>;
};

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
    const [query, setQuery] = useState('');
    const [category, setCategory] = useState<string>('all');
    const [onlyCounted, setOnlyCounted] = useState(false);
    const form = useForm<{
        note: string;
        items: { product_id: number; counted_qty: number }[];
    }>({ note: opname?.note ?? '', items: [] });
    const errors = form.errors as Record<string, string | undefined>;

    const visible = useMemo(() => {
        const q = query.trim().toLowerCase();

        return products.filter(
            (p) =>
                (category === 'all' || String(p.categoryId) === category) &&
                (!onlyCounted || (counts[p.id] ?? '') !== '') &&
                (q === '' ||
                    p.name.toLowerCase().includes(q) ||
                    p.sku.toLowerCase().includes(q) ||
                    p.units.some((u) => u.barcode === query.trim())),
        );
    }, [products, query, category, onlyCounted, counts]);

    const counted = products.filter((p) => (counts[p.id] ?? '') !== '');
    const withDifference = counted.filter(
        (p) => Number(counts[p.id]) !== p.stock,
    ).length;

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.transform((data) => ({
            note: data.note,
            items: counted.map((p) => ({
                product_id: p.id,
                counted_qty: Number(counts[p.id]),
            })),
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

StockOpnameForm.layout = {
    breadcrumbs: [
        { title: 'Stok opname', href: stockOpnames.index() },
        { title: 'Draft', href: '#' },
    ],
};
