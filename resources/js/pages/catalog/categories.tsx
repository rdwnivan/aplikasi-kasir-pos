import { Head } from '@inertiajs/react';
import { MasterList } from '@/components/catalog/master-list';
import type { MasterItem } from '@/components/catalog/master-list';
import categories from '@/routes/categories';

export default function Categories({ items }: { items: MasterItem[] }) {
    return (
        <>
            <Head title="Kategori" />
            <MasterList
                title="Kategori"
                description="Pengelompokan produk untuk pencarian dan laporan."
                noun="kategori"
                usageLabel="Jumlah produk"
                placeholder="mis. Minuman"
                items={items}
                routes={categories}
            />
        </>
    );
}

Categories.layout = {
    breadcrumbs: [{ title: 'Kategori', href: categories.index() }],
};
