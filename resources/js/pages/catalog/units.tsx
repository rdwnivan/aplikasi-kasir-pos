import { Head } from '@inertiajs/react';
import { MasterList } from '@/components/catalog/master-list';
import type { MasterItem } from '@/components/catalog/master-list';
import units from '@/routes/units';

export default function Units({ items }: { items: MasterItem[] }) {
    return (
        <>
            <Head title="Satuan" />
            <MasterList
                title="Satuan"
                description="Nama satuan (pcs, pak, dus, kg…). Konversi diatur per produk."
                noun="satuan"
                usageLabel="Dipakai produk"
                placeholder="mis. dus"
                items={items}
                routes={units}
            />
        </>
    );
}

Units.layout = {
    breadcrumbs: [{ title: 'Satuan', href: units.index() }],
};
