import { Link } from '@inertiajs/react';
import { FileSpreadsheet, FileText } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn } from '@/lib/utils';
import reports from '@/routes/reports';

const tabs = [
    { title: 'Penjualan & laba', href: reports.sales() },
    { title: 'Produk terlaris', href: reports.products() },
    { title: 'Nilai persediaan', href: reports.inventory() },
    { title: 'Kasir & shift', href: reports.shifts() },
];

export function ReportNav() {
    const { isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <nav
            className="flex gap-1 overflow-x-auto overflow-y-hidden border-b"
            aria-label="Laporan"
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.title}
                    href={tab.href}
                    className={cn(
                        '-mb-px border-b-2 px-3 py-2 text-sm whitespace-nowrap text-muted-foreground hover:text-foreground',
                        isCurrentOrParentUrl(tab.href) &&
                            'border-primary font-medium text-foreground',
                    )}
                >
                    {tab.title}
                </Link>
            ))}
        </nav>
    );
}

export function StatCard({
    label,
    value,
    hint,
    tone = 'default',
}: {
    label: string;
    value: string;
    hint?: string;
    tone?: 'default' | 'positive' | 'negative';
}) {
    return (
        <div className="rounded-xl border bg-card p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <p
                className={cn(
                    'mt-1 text-xl font-semibold tracking-tight tabular-nums',
                    tone === 'positive' &&
                        'text-emerald-600 dark:text-emerald-400',
                    tone === 'negative' && 'text-destructive',
                )}
            >
                {value}
            </p>
            {hint && (
                <p className="mt-0.5 text-xs text-muted-foreground">{hint}</p>
            )}
        </div>
    );
}

/** Plain link (not an Inertia visit): the server answers with an .xlsx file. */
/**
 * Excel and PDF downloads of the report on screen, with its filters. The PDF link has no
 * `download` attribute: when the server refuses a PDF that is too long it redirects back
 * here with a toast, which a download would save as a file instead.
 */
export function ExportButtons({ excel, pdf }: { excel: string; pdf: string }) {
    return (
        <div className="flex gap-2 sm:ml-auto">
            <Button variant="outline" size="sm" asChild>
                <a href={excel} download>
                    <FileSpreadsheet /> Ekspor Excel
                </a>
            </Button>
            <Button variant="outline" size="sm" asChild>
                <a href={pdf}>
                    <FileText /> Ekspor PDF
                </a>
            </Button>
        </div>
    );
}
