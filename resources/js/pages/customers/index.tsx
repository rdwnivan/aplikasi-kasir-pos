import { Head, Link, router } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useState } from 'react';
import { CustomerFormDialog } from '@/components/customers/customer-form-dialog';
import { PageHeader } from '@/components/page-header';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import { cn } from '@/lib/utils';
import customers from '@/routes/customers';

type Customer = {
    id: number;
    name: string;
    phone: string | null;
    address: string | null;
    note: string | null;
    balance: number;
    creditLimit: number;
    active: boolean;
    oldestAt: string | null;
    /** Calendar days (WIB) since that kasbon, counted on the server like the report. */
    oldestDays: number | null;
};

type Status = 'berutang' | 'lewat-30' | 'nonaktif' | null;

type Props = {
    customers: Customer[];
    filters: { q: string; status: Status };
    canManage: boolean;
    defaultLimit: number;
};

const statuses: { value: Status; label: string }[] = [
    { value: null, label: 'Aktif' },
    { value: 'berutang', label: 'Berutang' },
    { value: 'lewat-30', label: 'Lewat 30 hari' },
    { value: 'nonaktif', label: 'Nonaktif' },
];

/** Pelanggan & kasbon (DESIGN.md §17). */
export default function CustomersIndex({
    customers: rows,
    filters,
    canManage,
    defaultLimit,
}: Props) {
    const [q, setQ] = useState(filters.q);
    const [adding, setAdding] = useState(false);

    function visit(next: Partial<Props['filters']>) {
        router.get(
            customers.index.url({
                query: {
                    q: q || undefined,
                    status: filters.status ?? undefined,
                    ...next,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Pelanggan & kasbon" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Pelanggan & kasbon"
                    description={`Pelanggan langganan yang boleh belanja kasbon. Pelanggan baru dari kasir mendapat batas ${formatRupiah(defaultLimit)}.`}
                    actions={
                        canManage && (
                            <Button onClick={() => setAdding(true)}>
                                <Plus /> Tambah pelanggan
                            </Button>
                        )
                    }
                />

                <div className="flex flex-wrap items-center gap-2">
                    <form
                        className="relative"
                        onSubmit={(e) => {
                            e.preventDefault();
                            visit({ q: q || undefined });
                        }}
                    >
                        <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            className="w-64 pl-9"
                            placeholder="Cari nama atau nomor HP…"
                            aria-label="Cari pelanggan"
                            value={q}
                            onChange={(e) => setQ(e.target.value)}
                        />
                    </form>
                    <div className="flex gap-1">
                        {statuses.map((s) => (
                            <Button
                                key={s.label}
                                size="sm"
                                variant={
                                    filters.status === s.value
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() =>
                                    visit({
                                        status: s.value ?? undefined,
                                    } as Partial<Props['filters']>)
                                }
                            >
                                {s.label}
                            </Button>
                        ))}
                    </div>
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Pelanggan</TableHead>
                                <TableHead className="text-right">
                                    Utang
                                </TableHead>
                                <TableHead className="text-right">
                                    Batas
                                </TableHead>
                                <TableHead>Kasbon tertua belum lunas</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={4}
                                        className="py-10 text-center text-muted-foreground"
                                    >
                                        Belum ada pelanggan di sini.
                                    </TableCell>
                                </TableRow>
                            )}
                            {rows.map((c) => {
                                const days = c.oldestDays;

                                return (
                                    <TableRow key={c.id}>
                                        <TableCell>
                                            <Link
                                                href={customers.show(c.id)}
                                                className="font-medium hover:underline"
                                            >
                                                {c.name}
                                            </Link>
                                            {!c.active && (
                                                <Badge
                                                    variant="secondary"
                                                    className="ml-2"
                                                >
                                                    Nonaktif
                                                </Badge>
                                            )}
                                            <div className="text-xs text-muted-foreground">
                                                {c.phone ?? '—'}
                                            </div>
                                        </TableCell>
                                        <TableCell
                                            className={cn(
                                                'text-right font-medium whitespace-nowrap tabular-nums',
                                                c.balance === 0 &&
                                                    'font-normal text-muted-foreground',
                                            )}
                                        >
                                            {formatRupiah(c.balance)}
                                        </TableCell>
                                        <TableCell className="text-right whitespace-nowrap text-muted-foreground tabular-nums">
                                            {formatRupiah(c.creditLimit)}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {c.oldestAt ? (
                                                <>
                                                    {formatDate(c.oldestAt)}{' '}
                                                    <span
                                                        className={cn(
                                                            'text-xs',
                                                            days !== null &&
                                                                days > 30
                                                                ? 'text-destructive'
                                                                : 'text-muted-foreground',
                                                        )}
                                                    >
                                                        ({days} hari)
                                                    </span>
                                                </>
                                            ) : (
                                                <span className="text-muted-foreground">
                                                    –
                                                </span>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                );
                            })}
                        </TableBody>
                    </Table>
                </div>
            </div>

            <CustomerFormDialog
                customer={adding ? 'new' : null}
                defaultLimit={defaultLimit}
                onClose={() => setAdding(false)}
            />
        </>
    );
}

CustomersIndex.layout = {
    breadcrumbs: [{ title: 'Pelanggan & kasbon', href: customers.index() }],
};
