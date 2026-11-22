# Design — Aplikasi Kasir (POS) + Stock Management

Dokumen ini merangkum keputusan desain hasil sesi grilling (2026-11-21).
Tujuan: project portofolio GitHub untuk posisi **fullstack web developer**, dengan konteks bisnis Indonesia.

## 1. Ringkasan keputusan

| Area      | Keputusan                                                          |
| --------- | ------------------------------------------------------------------ |
| Target    | Fullstack web developer                                            |
| Stack     | Laravel (terbaru) + Inertia.js + React + TypeScript, PostgreSQL    |
| Platform  | Web app responsif (desktop kasir & tablet)                         |
| Skala     | 1 toko, banyak kasir. Skema siap dikembangkan ke multi-cabang (v2) |
| Timeline  | 4–6 minggu, MVP bisa didemokan di minggu ke-3                      |
| Bahasa UI | Bahasa Indonesia                                                   |
| README    | Inggris (utama) + ringkasan Bahasa Indonesia                       |
| Offline   | Tidak di v1 (roadmap: PWA + antrean sinkronisasi)                  |

## 2. Standar lokal Indonesia

- Format Rupiah `Rp 15.000` (tanpa desimal), tanggal `21 Nov 2026`, zona waktu `Asia/Jakarta`.
- **PPN 12%** dengan DPP nilai lain 11/12 (efektif 11%). Tarif & metode disimpan di pengaturan, bukan di-hardcode.
- Opsi harga **sudah termasuk pajak** atau **belum termasuk pajak** (pengaturan toko).
- **QRIS** via Midtrans Sandbox (Core API), dengan webhook + idempotensi.
- **Struk thermal** 58mm / 80mm via cetak browser (CSS `@page`). ESC/POS = roadmap.
- **Barcode scanner** (scanner USB = input keyboard + Enter).
- **Satuan bertingkat**: pcs / pak / dus dengan faktor konversi ke satuan dasar; harga jual per satuan (grosir vs eceran).

## 3. Role

| Role        | Akses                                                                                |
| ----------- | ------------------------------------------------------------------------------------ |
| Owner       | Semua: laporan, pengaturan, user, produk, stok, kasir                                |
| Staf Gudang | Produk, supplier, penerimaan barang, stok opname, lihat stok                         |
| Kasir       | Layar kasir, shift sendiri, riwayat transaksi shift sendiri, retur (butuh PIN owner) |

Implementasi: `spatie/laravel-permission`, role tetap (tanpa UI pengaturan permission).

## 4. Model stok & HPP

- **Buku besar pergerakan stok** (`stock_movements`) adalah satu-satunya sumber kebenaran.
  Setiap perubahan stok = satu baris: `type` (purchase, sale, sale_return, adjustment, opname), `qty` (+/−, dalam satuan dasar), `unit_cost`, referensi dokumen (polymorphic).
- `products.stock_on_hand` & `products.avg_cost` adalah **cache** yang diperbarui di transaksi DB yang sama, dengan `SELECT ... FOR UPDATE` per produk untuk mencegah race condition antar kasir.
- **HPP moving average**: saat barang masuk, `avg_cost_baru = (stok × avg_lama + qty_masuk × harga_beli) / (stok + qty_masuk)`. Saat penjualan, HPP baris = `avg_cost` saat itu (disnapshot ke `sale_items.unit_cost`).
- Stok tidak boleh negatif (bisa dibuat opsi pengaturan nanti).
- Kuantitas disimpan sebagai **integer dalam satuan dasar**. Barang timbang memakai satuan dasar kecil (mis. gram) bila perlu.
- Uang disimpan sebagai integer Rupiah; HPP `decimal(15,4)` dihitung dengan bcmath (tanpa float).
- Opname surplus masuk dengan HPP rata-rata saat ini (tidak mengubah rata-rata); hanya `purchase` & `sale_return` yang menghitung ulang rata-rata.
- Baris `stock_movements` immutable: koreksi dilakukan dengan movement baru.
- Perintah `stock:rebuild` untuk menghitung ulang cache dari buku besar (bukti konsistensi, sekaligus dipakai di tes).

## 5. Cakupan v1

### Stok / pembelian

- Master supplier
- Penerimaan barang langsung (tanpa PO)
- Stok opname (input hitungan fisik → selisih otomatis jadi movement `opname`)
- Peringatan stok minimum

### Kasir

- Cari produk / scan barcode, pilih satuan
- Diskon per item & per transaksi (nominal atau persen)
- Tahan transaksi (hold) & lanjutkan
- Buka/tutup shift: modal awal laci, rekap kas akhir, selisih kas
- Pembayaran tunai (hitung kembalian) atau QRIS
- Retur/refund penjualan (stok kembali via movement `sale_return`)
- Pintasan keyboard (F2 cari, F4 hold, F9 bayar, Esc batal)
- Cetak struk 58/80mm

### Laporan

- Penjualan harian / bulanan + grafik dashboard
- Laba kotor (penjualan − HPP)
- Produk terlaris
- Nilai persediaan (stok × avg_cost)
- Rekap per kasir / per shift
- Ekspor Excel

## 6. Di luar v1 (roadmap)

- Purchase Order + penerimaan parsial
- Batch & tanggal kedaluwarsa
- Pembayaran campuran (split payment)
- Kasbon / piutang pelanggan
- Multi-cabang & transfer stok
- Mode offline (PWA + antrean sinkronisasi)
- Cetak ESC/POS langsung (WebUSB / QZ Tray)
- Ekspor PDF

## 7. Kualitas & demo

- Tes Pest, fokus ke buku besar stok, HPP, transaksi kasir, retur, webhook QRIS
- GitHub Actions: Pint, PHPStan, build frontend, `tsc`, `vp check`, lalu Pest di SQLite **dan** PostgreSQL 17 (lihat §9)
- Docker (Laravel Sail) untuk `up` sekali jalan
- Seeder data realistis (Indomie, Aqua, Teh Botol, Beras 5kg, dll.) + akun demo per role
- Demo live (hosting ditentukan kemudian)

## 8. Milestone

| Minggu | Target                                                                                        | Status                                                   |
| ------ | --------------------------------------------------------------------------------------------- | -------------------------------------------------------- |
| 1      | Scaffold, auth & role, skema DB, master produk/satuan/supplier, service buku besar stok + tes | ✅ Selesai                                               |
| 2      | Penerimaan barang, stok opname, layar kasir (tunai), shift                                    | ✅ Selesai                                               |
| 3      | Struk, hold, diskon, retur → **MVP demo**                                                     | ✅ Selesai                                               |
| 4      | QRIS Midtrans + webhook, laporan & dashboard                                                  | ✅ Selesai (sandbox: polling & webhook terverifikasi)    |
| 5      | Ekspor Excel, polish UI, seeder, CI, Docker                                                   | ✅ Selesai (CI hijau; `sail up` belum pernah dijalankan) |
| 6      | Deploy demo, README + screenshot/GIF, buffer                                                  | ✅ Selesai (Vercel + Neon; animasi README berupa APNG)   |

### Terverifikasi

- **GitHub Actions** (2026-11-22). Run pertama `tests` di `main` (commit `df90872`) hijau di semua job: lint/types/build, Pest SQLite (221 lulus, 3 dilewati — tes `tests/Concurrency` yang hanya jalan di PostgreSQL), dan Pest PostgreSQL 17 (224 lulus, termasuk `tests/Concurrency`, tanpa yang dilewati). Run: <https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45014828946>.
- **QRIS ke Midtrans Sandbox, jalur polling** (2026-11-22). Dari layar kasir (`php -S`, DB `kasir_pos_test`) dibuat transaksi QRIS 2 pcs Aqua Botol 600ml senilai Rp 8.000; `POST /v2/charge` ke `api.sandbox.midtrans.com` berhasil dengan API key sandbox dari dashboard Midtrans (model key baru: satu API key berizin Core API = Write dan Cancel transaction = Write, dipakai sebagai `MIDTRANS_SERVER_KEY`; client key tidak dipakai aplikasi). QR dibayar lewat simulator sandbox; polling 3 detik mengubah status ke `completed`, layar menampilkan "Pembayaran QRIS diterima", dan polling berhenti. Stok turun 75 → 73 dengan tepat satu `stock_movement` baru (`sale`, qty −2), dan panggilan polling tambahan setelah itu tidak mengeluarkan stok lagi.
- **Deploy demo** (2026-11-22). <https://aplikasi-kasir-pos-fawn.vercel.app>: `Dockerfile` (FrankenPHP, PHP 8.5) di-build oleh Container runtime Vercel (beta, paket Hobby gratis) di region `sin1`, database di Neon (Singapura). Halaman dan aset tampil lewat https, `/up` 200, login owner dan dashboard berisi data 30 hari dari workflow `demo-db` (mode `reset`: seed di PostgreSQL runner lalu `pg_dump` ke Neon). Render (minta kartu kredit walau paket gratis) dan Hugging Face Spaces (Space Docker kini butuh PRO) sempat dicoba dan ditinggalkan.
- **Webhook Midtrans di demo live** (2026-11-23). Notifikasi pertama (INV-20261122-0002) ditolak `invalid_signature`: sejak 28 Oktober 2026 Midtrans menandatangani notifikasi dengan _Signature Secret Key_, yang berbeda dari server key pada akun dengan access key baru. Setelah `MIDTRANS_SIGNATURE_KEY` ditambahkan (commit `8a310c3`), transaksi INV-20261123-0001 dibayar lewat simulator dengan tab kasir ditutup: notifikasi `pending` (`unchanged`) dan `settlement` (`settled`) keduanya `signature_valid = true`, jadi pelunasan datang dari webhook, bukan polling. Stok keluar sekali per pelunasan dijaga oleh `QrisPayments::settle()` (row lock + cek status) dan tes Pest; untuk transaksi ini tabel `stock_movements` tidak diperiksa terpisah.

### Belum terverifikasi

- **`sail up`.** `compose.yaml` dan `docker/sail-start.sh` sudah ditulis, tetapi container belum pernah dijalankan. Belum bisa dicek karena Docker tidak terpasang di mesin pengembangan (2026-11-22).

## 9. Keputusan implementasi (2026-11-22)

### 9.1 Laba kotor

- Laba kotor = **penjualan bersih tanpa PPN** − HPP. PPN dipungut untuk negara, jadi bukan pendapatan toko.
- Penjualan bersih = (total penjualan − PPN penjualan) − (refund − PPN refund). HPP = HPP barang terjual − HPP barang yang diretur (memakai HPP baris aslinya).
- Implementasi: `app/Reports/SalesSummary.php`, dipakai juga oleh dashboard.
- Laporan **produk terlaris** memakai aturan yang sama per produk (`app/Reports/TopProducts.php`): total dan PPN setiap transaksi dibagi ke barisnya menurut `line_total`, PPN setiap retur dibagi menurut nilai refund per baris, semuanya dengan _largest remainder_ (`App\Support\Apportion`, juga dipakai retur di §9.2). Omzet = yang dibayar pelanggan dikurangi refund (termasuk PPN); penjualan bersih = omzet tanpa PPN; laba = bersih − HPP. Jumlah omzet dan penjualan bersih semua produk sama persis dengan laporan penjualan; HPP dibulatkan per produk, jadi total HPP/laba bisa selisih beberapa rupiah.

### 9.2 Retur

- Nilai refund = yang benar-benar dibayar pelanggan. Total transaksi (setelah diskon transaksi dan PPN) dibagi ke tiap baris **secara proporsional** terhadap `line_total`, dalam rupiah bulat. Sisa pembulatan dibagikan per Rp 1 ke baris dengan sisa pecahan terbesar (_largest remainder_), sehingga jumlah bagian selalu tepat sama dengan total.
- Di dalam satu baris, retur sebagian dihitung pro rata; retur unit terakhir sebuah baris mengembalikan seluruh sisa bagian baris itu. Jadi meretur semua barang selalu mengembalikan total transaksi tepat sampai rupiah.
- Barang masuk lagi ke buku besar sebagai `sale_return` dengan HPP baris aslinya.
- Persetujuan dengan **PIN owner** (4–6 angka) yang disimpan sebagai hash (`users.pin_hash`). PIN salah dibatasi **5 kali per 5 menit** per pengguna yang meminta (`App\Services\Auth\OwnerPin`).
- Uang refund keluar dari **laci shift yang sedang berjalan** milik pengguna yang memproses retur, jadi shift harus terbuka dan rekap kas saat tutup shift sudah memperhitungkannya.
- Satu retur per transaksi diproses bergantian (row lock pada `sales`), sehingga dua terminal tidak bisa sama-sama meretur unit terakhir.

### 9.3 QRIS (Midtrans Core API)

- Status transaksi: `pending_payment` → `completed` (settlement) atau `cancelled` (expire / cancel / deny / failure). Transaksi `cancelled` yang ternyata dibayar di detik terakhir tetap menjadi `completed`, karena uangnya nyata.
- **Stok baru keluar saat settlement**, tepat sekali. Selama `pending_payment` stok tidak dipesan.
- Webhook `POST /webhooks/midtrans` memverifikasi `signature_key` = SHA512(`order_id` + `status_code` + `gross_amount` + server key) dengan `hash_equals`, dan **idempotent**: transaksi dikunci dengan `SELECT … FOR UPDATE`, lalu notifikasi yang tidak mengubah status diabaikan.
- Layar kasir juga **polling status ke Midtrans setiap 3 detik**, sehingga pembayaran tetap terdeteksi walau webhook tidak sampai (mis. server lokal tanpa URL publik).
- State machine ada di `app/Actions/Sales/QrisPayments.php`; pembuatan QR di `StartQrisPayment`.

### 9.4 Ekspor Excel

- Memakai **OpenSpout** (`openspout/openspout`) untuk menulis `.xlsx` secara streaming: memori tetap kecil walau barisnya banyak, dan jauh lebih ringan daripada PhpSpreadsheet (cukup ekstensi standar seperti `zip` dan `xmlreader`). Builder kecil ada di `app/Exports/Spreadsheet.php`.
- **Bukan CSV**, karena kolom rupiah dan jumlah perlu format angka asli agar bisa dijumlahkan dan dibuat grafik di Excel. Selain itu, Excel dengan locale Indonesia memakai `;` sebagai pemisah daftar dan `,` sebagai desimal, sehingga CSV berpemisah koma terbaca salah.

### 9.5 Grafik dashboard

- Grafik batang dibuat dengan **SVG sendiri** (`resources/js/components/charts/daily-bar-chart.tsx`), tanpa library grafik. Kebutuhannya hanya batang harian (termasuk nilai negatif untuk laba) dengan tooltip dan tabel aksesibel, jadi library seperti Recharts tidak sebanding dengan ukuran bundle-nya.

### 9.6 Platform & data demo

- **PHP minimal 8.4** (`composer.json`: `^8.4`); pengembangan dan Sail memakai PHP 8.5.
- Harga beli di `CatalogSeeder` sekitar 88% dari harga jual satuan terbesar tanpa PPN, sehingga data demo menunjukkan margin kotor wajar (±12%).
- `DemoSalesSeeder` (30 hari penjualan, restok, retur, QRIS yang sudah lunas) **hanya jalan saat `APP_ENV=local`**, supaya `migrate --seed` di produksi tidak mengisi transaksi palsu. Semua data dibuat lewat Action yang sama dengan aplikasi, jadi buku besar tetap konsisten.

### 9.7 CI

- GitHub Actions menjalankan Pest dalam matriks **SQLite (in memory) dan PostgreSQL 17**.
- Tes di `tests/Concurrency` hanya jalan di PostgreSQL: dua koneksi database (proses PHP kedua) berebut unit terakhir untuk membuktikan `lockForUpdate()` mencegah oversell. Di SQLite tes ini dilewati karena lock tidak berlaku.

### 9.8 Docker (Laravel Sail)

- Container app menjalankan skrip first-boot `docker/sail-start.sh`: membuat `.env` dari `.env.example` bila belum ada, membuat `APP_KEY`, menjalankan `npm ci` dan `npm run build` bila belum ada build, lalu `artisan serve` di port 80.
