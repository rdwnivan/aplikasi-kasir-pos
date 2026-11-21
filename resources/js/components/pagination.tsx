import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

/** Page links for a Laravel paginator; hidden when everything fits on one page. */
export function Pagination({ page }: { page: Paginated<unknown> }) {
    if (page.last_page <= 1) {
        return null;
    }

    return (
        <nav
            className="flex flex-wrap items-center justify-between gap-3 text-sm"
            aria-label="Halaman"
        >
            <p className="text-muted-foreground">
                {page.from}–{page.to} dari {page.total}
            </p>
            <div className="flex flex-wrap gap-1">
                {page.links.map((link, i) => {
                    const label = link.label
                        .replace('&laquo;', '‹')
                        .replace('&raquo;', '›')
                        .replace(/Sebelumnya|Previous/, '')
                        .replace(/Berikutnya|Next/, '')
                        .trim();

                    return link.url === null ? (
                        <span
                            key={i}
                            className="rounded-md px-3 py-1.5 text-muted-foreground/50"
                        >
                            {label}
                        </span>
                    ) : (
                        <Link
                            key={i}
                            href={link.url}
                            preserveScroll
                            className={cn(
                                'rounded-md px-3 py-1.5 hover:bg-muted',
                                link.active &&
                                    'bg-primary text-primary-foreground hover:bg-primary',
                            )}
                        >
                            {label}
                        </Link>
                    );
                })}
            </div>
        </nav>
    );
}
