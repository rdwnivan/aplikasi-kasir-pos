import { Head, Link, useForm } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { BatchStatusBadge } from '@/components/inventory/expiry';
import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
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
import stockDisposals from '@/routes/stock-disposals';
import type { BatchStatus } from '@/types';

type Batch = {
    id: number;
    productId: number;
    product: string;
    sku: string;
    baseUnit: string;
    label: string;
    expiresOn: string | null;
    status: BatchStatus;
    qty: number;
    avgCost: number;
};

type Line = { stock_batch_id: number; qty: number };

/** Throw goods away from their batches (DESIGN.md §19); quantities in base units. */
export default function StockDisposalCreate({
    batches,
    lines,
}: {
    batches: Batch[];
    lines: { batchId: number; qty: number }[];
}) {
    const form = useForm<{ reason: string; items: Line[] }>({
        reason: lines.length > 0 ? 'Kedaluwarsa' : '',
        items: lines.map((l) => ({ stock_batch_id: l.batchId, qty: l.qty })),
    });
    const errors = form.errors as Record<string, string | undefined>;
    const byId = new Map(batches.map((b) => [b.id, b]));
    const chosen = new Set(form.data.items.map((l) => l.stock_batch_id));
    const left = batches.filter((b) => !chosen.has(b.id));
    const value = form.data.items.reduce(
        (s, l) =>
            s + Math.round(l.qty * (byId.get(l.stock_batch_id)?.avgCost ?? 0)),
        0,
    );

    const setLine = (index: number, patch: Partial<Line>) =>
        form.setData(
            'items',
            form.data.items.map((l, i) =>
                i === index ? { ...l, ...patch } : l,
            ),
        );

    return (
        <>
            <Head title="Buang barang" />
            <form
                className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-4 p-4"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.submit(stockDisposals.store());
                }}
            >
                <PageHeader
                    title="Buang barang"
                    description="Pilih batch dan jumlah yang dibuang (satuan dasar). Stok berkurang dan dinilai dengan HPP rata-rata saat ini; laba kotor tidak berubah."
                />

                <Card>
                    <CardHeader>
                        <CardTitle>Batch</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        <Select
                            value=""
                            onValueChange={(v) => {
                                const b = byId.get(Number(v));

                                if (b) {
                                    form.setData('items', [
                                        ...form.data.items,
                                        { stock_batch_id: b.id, qty: b.qty },
                                    ]);
                                }
                            }}
                            disabled={left.length === 0}
                        >
                            <SelectTrigger className="w-full">
                                <SelectValue
                                    placeholder={
                                        batches.length === 0
                                            ? 'Tidak ada batch bersisa'
                                            : 'Tambah batch…'
                                    }
                                />
                            </SelectTrigger>
                            <SelectContent>
                                {left.map((b) => (
                                    <SelectItem key={b.id} value={String(b.id)}>
                                        {b.product} · {b.label} ·{' '}
                                        {formatQty(b.qty)} {b.baseUnit}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={errors.items} />

                        {form.data.items.length === 0 && (
                            <p className="rounded-lg border border-dashed p-6 text-center text-sm text-muted-foreground">
                                Pilih batch yang barangnya dibuang.
                            </p>
                        )}

                        {form.data.items.map((line, i) => {
                            const b = byId.get(line.stock_batch_id);

                            if (!b) {
                                return null;
                            }

                            return (
                                <div
                                    key={b.id}
                                    className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[minmax(0,1fr)_8rem_8rem_auto] sm:items-center"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {b.product}
                                        </p>
                                        <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            {b.label} · sisa {formatQty(b.qty)}{' '}
                                            {b.baseUnit}
                                            <BatchStatusBadge
                                                status={b.status}
                                            />
                                        </p>
                                    </div>
                                    <div className="grid gap-1">
                                        <Input
                                            type="number"
                                            min={1}
                                            max={b.qty}
                                            aria-label={`Jumlah dibuang ${b.product}`}
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
                                    <p className="text-right text-sm tabular-nums">
                                        {formatRupiah(
                                            Math.round(line.qty * b.avgCost),
                                        )}
                                    </p>
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
                                <span>Nilai (HPP)</span>
                                <span className="tabular-nums">
                                    {formatRupiah(value)}
                                </span>
                            </div>
                        )}
                    </CardContent>
                </Card>

                <div className="grid gap-1.5">
                    <Label htmlFor="reason">Alasan</Label>
                    <Input
                        id="reason"
                        placeholder="mis. kedaluwarsa, kemasan rusak"
                        value={form.data.reason}
                        onChange={(e) => form.setData('reason', e.target.value)}
                        maxLength={255}
                    />
                    <InputError message={errors.reason} />
                </div>

                <div className="flex justify-end gap-2">
                    <Button variant="outline" asChild>
                        <Link href={stockDisposals.index()}>Batal</Link>
                    </Button>
                    <Button
                        type="submit"
                        disabled={
                            form.processing ||
                            form.data.items.length === 0 ||
                            form.data.reason.trim() === ''
                        }
                    >
                        Buang barang
                    </Button>
                </div>
            </form>
        </>
    );
}

StockDisposalCreate.layout = {
    breadcrumbs: [
        { title: 'Barang dibuang', href: stockDisposals.index() },
        { title: 'Buang barang', href: stockDisposals.create() },
    ],
};
