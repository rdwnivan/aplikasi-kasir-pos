import { Head } from '@inertiajs/react';
import { MasterList } from '@/components/catalog/master-list';
import type { MasterItem } from '@/components/catalog/master-list';
import suppliers from '@/routes/suppliers';

export default function Suppliers({ items }: { items: MasterItem[] }) {
    return (
        <>
            <Head title="Supplier" />
            <MasterList
                title="Supplier"
                description="Pemasok barang untuk penerimaan barang."
                noun="supplier"
                usageLabel="Penerimaan"
                placeholder="mis. CV Sumber Rejeki"
                items={items}
                routes={suppliers}
                withContact
            />
        </>
    );
}

Suppliers.layout = {
    breadcrumbs: [{ title: 'Supplier', href: suppliers.index() }],
};
