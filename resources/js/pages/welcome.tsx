import { Head, Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    BookOpenCheck,
    QrCode,
    ReceiptText,
    ScanBarcode,
    Undo2,
} from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { Button } from '@/components/ui/button';
import { dashboard, login } from '@/routes';

const features = [
    {
        icon: ScanBarcode,
        title: 'Kasir cepat',
        text: 'Scan barcode, satuan pcs/pak/dus, diskon item & transaksi, tahan transaksi, pintasan keyboard.',
    },
    {
        icon: BookOpenCheck,
        title: 'Stok yang bisa diaudit',
        text: 'Buku besar pergerakan stok dengan HPP moving average. Setiap angka bisa ditelusuri ke dokumennya.',
    },
    {
        icon: ReceiptText,
        title: 'PPN 12% & struk thermal',
        text: 'DPP nilai lain 11/12, harga termasuk atau belum termasuk pajak, struk 58/80 mm dari browser.',
    },
    {
        icon: QrCode,
        title: 'Tunai & QRIS',
        text: 'QRIS lewat Midtrans dengan webhook bertanda tangan; stok baru berkurang saat pembayaran lunas.',
    },
    {
        icon: Undo2,
        title: 'Retur dengan PIN owner',
        text: 'Barang kembali ke stok dengan HPP aslinya, uang keluar dari laci shift yang berjalan.',
    },
    {
        icon: BarChart3,
        title: 'Laporan untuk owner',
        text: 'Penjualan & laba kotor, produk terlaris, nilai persediaan, rekap kasir, ekspor Excel.',
    },
];

export default function Welcome() {
    const { auth, name } = usePage().props;

    return (
        <>
            <Head title="Aplikasi kasir & manajemen stok" />
            <div className="min-h-dvh bg-background text-foreground">
                <header className="mx-auto flex max-w-5xl items-center justify-between px-4 py-5">
                    <div className="flex items-center gap-2 font-semibold">
                        <AppLogoIcon className="size-7 fill-current" />
                        {name}
                    </div>
                    <Button asChild>
                        <Link href={auth.user ? dashboard() : login()}>
                            {auth.user ? 'Buka dashboard' : 'Masuk'}
                        </Link>
                    </Button>
                </header>

                <main className="mx-auto max-w-5xl px-4 pt-10 pb-16 sm:pt-16">
                    <section className="max-w-2xl space-y-4">
                        <p className="text-sm font-medium text-muted-foreground">
                            Untuk toko, warung, dan minimarket
                        </p>
                        <h1 className="text-3xl font-semibold tracking-tight text-balance sm:text-5xl">
                            Kasir dan stok dalam satu buku besar.
                        </h1>
                        <p className="text-lg text-pretty text-muted-foreground">
                            Jual lebih cepat di kasir, terima barang dan stok
                            opname di gudang, dan lihat laba kotor yang
                            sebenarnya, karena setiap perubahan stok tercatat
                            beserta HPP-nya.
                        </p>
                        {!auth.user && (
                            <p className="text-sm text-muted-foreground">
                                Coba akun demo: <code>owner@kasir.test</code>,{' '}
                                <code>gudang@kasir.test</code>, atau{' '}
                                <code>kasir@kasir.test</code> (kata sandi{' '}
                                <code>password</code>).
                            </p>
                        )}
                    </section>

                    <section className="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {features.map((f) => (
                            <div
                                key={f.title}
                                className="rounded-xl border bg-card p-5"
                            >
                                <f.icon className="size-5 text-muted-foreground" />
                                <h2 className="mt-3 font-medium">{f.title}</h2>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    {f.text}
                                </p>
                            </div>
                        ))}
                    </section>
                </main>
            </div>
        </>
    );
}
