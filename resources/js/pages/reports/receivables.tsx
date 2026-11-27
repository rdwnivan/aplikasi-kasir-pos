import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/page-header';
import {
    ExportButtons,
    ReportNav,
    StatCard,
} from '@/components/reports/report-nav';
import {
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { formatDate, formatDateTime } from '@/lib/format';
import { formatRupiah } from '@/lib/money';
import customers from '@/routes/customers';
import reports from '@/routes/reports';

type Ages = {
    age0to30: number;
    age31to60: number;
    age61to90: number;
    age90plus: number;
};

type Row = Ages & {
    id: number;
    customer: string;
    phone: string | null;
    balance: number;
    creditLimit: number;
    openSales: number;
    oldestAt: string | null;
    lastPaymentAt: string | null;
};

type Report = {
    asOf: string;
    rows: Row[];
    totals: Ages & { customers: number; balance: number; overdue: number };
};

const ages: { key: keyof Ages; label: string }[] = [
    { key: 'age0to30', label: '0–30 hari' },
    { key: 'age31to60', label: '31–60 hari' },
    { key: 'age61to90', label: '61–90 hari' },
    { key: 'age90plus', label: '> 90 hari' },
];

/** Kasbon owed per customer as of now, aged (DESIGN.md §17). */
export default function ReceivablesReport({ report }: { report: Report }) {
    const t = report.totals;

    return (
        <>
            <Head title="Laporan piutang" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <PageHeader
                    title="Laporan"
                    description={`Kasbon yang belum lunas per ${formatDateTime(report.asOf)}. Umur dihitung dari tanggal kasbon; pelunasan menutup kasbon tertua lebih dulu.`}
                />
                <ReportNav />
                <div className="flex">
                    <ExportButtons
                        excel={reports.export.receivables.url()}
                        pdf={reports.pdf.receivables.url()}
                    />
                </div>

                <div className="grid gap-3 sm:grid-cols-3">
                    <StatCard
                        label="Total piutang"
                        value={formatRupiah(t.balance)}
                    />
                    <StatCard
                        label="Pelanggan berutang"
                        value={String(t.customers)}
                    />
                    <StatCard
                        label="Lewat 30 hari"
                        value={formatRupiah(t.overdue)}
                        tone={t.overdue > 0 ? 'negative' : 'default'}
                        hint={
                            t.balance > 0
                                ? `${Math.round((t.overdue / t.balance) * 100)}% dari total`
                                : undefined
                        }
                    />
                </div>

                <div className="rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Pelanggan</TableHead>
                                <TableHead className="text-right">
                                    Utang
                                </TableHead>
                                {ages.map((a) => (
                                    <TableHead
                                        key={a.key}
                                        className="text-right"
                                    >
                                        {a.label}
                                    </TableHead>
                                ))}
                                <TableHead>Kasbon tertua</TableHead>
                                <TableHead>Pelunasan terakhir</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {report.rows.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={8}
                                        className="py-8 text-center text-muted-foreground"
                                    >
                                        Tidak ada kasbon yang belum lunas.
                                    </TableCell>
                                </TableRow>
                            )}
                            {report.rows.map((r) => (
                                <TableRow key={r.id}>
                                    <TableCell>
                                        <Link
                                            href={customers.show(r.id)}
                                            className="font-medium hover:underline"
                                        >
                                            {r.customer}
                                        </Link>
                                        <div className="text-xs text-muted-foreground">
                                            {r.phone ?? '—'} · batas{' '}
                                            {formatRupiah(r.creditLimit)}
                                        </div>
                                    </TableCell>
                                    <TableCell className="text-right font-medium whitespace-nowrap tabular-nums">
                                        {formatRupiah(r.balance)}
                                    </TableCell>
                                    {ages.map((a) => (
                                        <TableCell
                                            key={a.key}
                                            className={
                                                'text-right whitespace-nowrap tabular-nums ' +
                                                (r[a.key] === 0
                                                    ? 'text-muted-foreground'
                                                    : a.key !== 'age0to30'
                                                      ? 'text-destructive'
                                                      : '')
                                            }
                                        >
                                            {r[a.key] === 0
                                                ? '–'
                                                : formatRupiah(r[a.key])}
                                        </TableCell>
                                    ))}
                                    <TableCell className="whitespace-nowrap">
                                        {r.oldestAt
                                            ? formatDate(r.oldestAt)
                                            : '–'}
                                        <div className="text-xs text-muted-foreground">
                                            {r.openSales} kasbon belum lunas
                                        </div>
                                    </TableCell>
                                    <TableCell className="whitespace-nowrap text-muted-foreground">
                                        {r.lastPaymentAt
                                            ? formatDate(r.lastPaymentAt)
                                            : 'Belum pernah'}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                        {report.rows.length > 0 && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell>Total</TableCell>
                                    <TableCell className="text-right tabular-nums">
                                        {formatRupiah(t.balance)}
                                    </TableCell>
                                    {ages.map((a) => (
                                        <TableCell
                                            key={a.key}
                                            className="text-right tabular-nums"
                                        >
                                            {formatRupiah(t[a.key])}
                                        </TableCell>
                                    ))}
                                    <TableCell colSpan={2} />
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>
            </div>
        </>
    );
}

ReceivablesReport.layout = {
    breadcrumbs: [{ title: 'Laporan', href: reports.sales() }],
};
