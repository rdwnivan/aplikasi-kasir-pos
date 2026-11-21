import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    PackagePlus,
    Pencil,
    RotateCcw,
    ScrollText,
} from 'lucide-react';
import { useState } from 'react';
import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHeader } from '@/components/page-header';
import { Pagination } from '@/components/pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { toastFirstError } from '@/lib/errors';
import { formatRupiah } from '@/lib/money';
import products from '@/routes/products';
import type { Option, Paginated } from '@/types';

type ProductRow = {
    id: number;
    sku: string;
    name: string;
    category: string | null;
    baseUnit: string;
    stock: number;
    minStock: number;
    avgCost: number;
    isActive: boolean;
    archived: boolean;
    units: { unit: string; conversion: number; price: number }[];
};

type Filters = {
    q: string;
    category: number | null;
    status: 'all' | 'active' | 'inactive' | 'archived' | 'low';
};

const statusLabels: Record<Filters['status'], string> = {
    all: 'Semua',
    active: 'Aktif',
    inactive: 'Nonaktif',
    low: 'Stok menipis',
    archived: 'Arsip',
};

export default function ProductIndex({
    products: page,
    filters,
    categories,
}: {
    products: Paginated<ProductRow>;
    filters: Filters;
    categories: Option[];
}) {
    const [q, setQ] = useState(filters.q);
    const [deleting, setDeleting] = useState<ProductRow | null>(null);
    const [processing, setProcessing] = useState(false);

    const apply = (next: Partial<Filters>) => {
        const merged = { ...filters, q, ...next };
        router.get(
            products.index.url(),
            {
                q: merged.q || undefined,
                category: merged.category ?? undefined,
                status: merged.status === 'all' ? undefined : merged.status,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <>
            <Head title="Produk" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Produk"
                    description="Katalog barang, satuan bertingkat, dan harga jual."
                    actions={
                        <Button asChild>
                            <Link href={products.create()}>
                                <PackagePlus /> Tambah produk
                            </Link>
                        </Button>
                    }
                />

                <form
                    className="flex flex-wrap gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        apply({});
                    }}
                >
                    <Input
                        className="w-full sm:w-64"
                        placeholder="Cari nama, SKU, atau barcode…"
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                    />
                    <Select
                        value={
                            filters.category ? String(filters.category) : 'all'
                        }
                        onValueChange={(v) =>
                            apply({ category: v === 'all' ? null : Number(v) })
                        }
                    >
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
                    <Select
                        value={filters.status}
                        onValueChange={(v) =>
                            apply({ status: v as Filters['status'] })
                        }
                    >
                        <SelectTrigger className="w-full sm:w-40">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {Object.entries(statusLabels).map(([k, label]) => (
                                <SelectItem key={k} value={k}>
                                    {label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <Button type="submit" variant="secondary">
                        Cari
                    </Button>
                </form>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Produk</TableHead>
                                <TableHead>Kategori</TableHead>
                                <TableHead>Harga jual</TableHead>
                                <TableHead className="text-right">
                                    Stok
                                </TableHead>
                                <TableHead className="text-right">
                                    HPP rata-rata
                                </TableHead>
                                <TableHead className="w-0" />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {page.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={6}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Tidak ada produk yang cocok.
                                    </TableCell>
                                </TableRow>
                            )}
                            {page.data.map((p) => {
                                const low =
                                    p.isActive &&
                                    !p.archived &&
                                    p.stock <= p.minStock;

                                return (
                                    <TableRow key={p.id}>
                                        <TableCell>
                                            <div className="font-medium">
                                                {p.name}
                                            </div>
                                            <div className="flex items-center gap-1.5 text-xs text-muted-foreground">
                                                {p.sku}
                                                {!p.isActive && !p.archived && (
                                                    <Badge variant="outline">
                                                        Nonaktif
                                                    </Badge>
                                                )}
                                                {p.archived && (
                                                    <Badge variant="outline">
                                                        Arsip
                                                    </Badge>
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-muted-foreground">
                                            {p.category ?? '–'}
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-0.5 text-xs">
                                                {p.units.map((u) => (
                                                    <div key={u.unit}>
                                                        <span className="tabular-nums">
                                                            {formatRupiah(
                                                                u.price,
                                                            )}
                                                        </span>{' '}
                                                        <span className="text-muted-foreground">
                                                            / {u.unit}
                                                            {u.conversion > 1 &&
                                                                ` (${u.conversion} ${p.baseUnit})`}
                                                        </span>
                                                    </div>
                                                ))}
                                            </div>
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            <span
                                                className={
                                                    low
                                                        ? 'font-semibold text-destructive'
                                                        : ''
                                                }
                                            >
                                                {formatQty(p.stock)}{' '}
                                                {p.baseUnit}
                                            </span>
                                            {low && (
                                                <div className="text-xs text-destructive">
                                                    min. {formatQty(p.minStock)}
                                                </div>
                                            )}
                                        </TableCell>
                                        <TableCell className="text-right tabular-nums">
                                            {formatRupiah(
                                                Math.round(p.avgCost),
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-1">
                                                <Button
                                                    size="icon"
                                                    variant="ghost"
                                                    asChild
                                                    title="Kartu stok"
                                                >
                                                    <Link
                                                        href={products.stockCard(
                                                            p.id,
                                                        )}
                                                    >
                                                        <ScrollText />
                                                    </Link>
                                                </Button>
                                                {p.archived ? (
                                                    <Button
                                                        size="icon"
                                                        variant="ghost"
                                                        title="Pulihkan"
                                                        onClick={() =>
                                                            router.post(
                                                                products.restore.url(
                                                                    p.id,
                                                                ),
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <RotateCcw />
                                                    </Button>
                                                ) : (
                                                    <>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            asChild
                                                            title="Ubah"
                                                        >
                                                            <Link
                                                                href={products.edit(
                                                                    p.id,
                                                                )}
                                                            >
                                                                <Pencil />
                                                            </Link>
                                                        </Button>
                                                        <Button
                                                            size="icon"
                                                            variant="ghost"
                                                            title="Hapus / arsipkan"
                                                            onClick={() =>
                                                                setDeleting(p)
                                                            }
                                                        >
                                                            <Archive />
                                                        </Button>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>

                <Pagination page={page} />
            </div>

            <ConfirmDialog
                open={deleting !== null}
                onOpenChange={(open) => !open && setDeleting(null)}
                title={`Hapus ${deleting?.name ?? ''}?`}
                description="Produk yang belum pernah punya transaksi dihapus permanen. Produk dengan riwayat stok diarsipkan, jadi laporan dan kartu stoknya tetap utuh."
                confirmLabel="Hapus"
                destructive
                processing={processing}
                onConfirm={() => {
                    if (!deleting) {
                        return;
                    }

                    router.delete(products.destroy.url(deleting.id), {
                        preserveScroll: true,
                        onError: toastFirstError,
                        onStart: () => setProcessing(true),
                        onFinish: () => {
                            setProcessing(false);
                            setDeleting(null);
                        },
                    });
                }}
            />
        </>
    );
}

ProductIndex.layout = {
    breadcrumbs: [{ title: 'Produk', href: products.index() }],
};
