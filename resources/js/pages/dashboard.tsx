import { Head } from '@inertiajs/react';
import { LowStockCard } from '@/components/dashboard/low-stock-card';
import type { LowStockItem } from '@/components/dashboard/low-stock-card';
import { dashboard } from '@/routes';

type Props = {
    lowStock: { count: number; items: LowStockItem[] } | null;
};

export default function Dashboard({ lowStock }: Props) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-4 p-4">
                <div className="grid gap-4 lg:grid-cols-2">
                    {lowStock && (
                        <LowStockCard
                            count={lowStock.count}
                            items={lowStock.items}
                        />
                    )}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
};
