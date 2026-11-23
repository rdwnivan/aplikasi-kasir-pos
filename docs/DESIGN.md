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

Tidak ada pendaftaran mandiri: setiap akun harus punya role, jadi fitur `registration` Fortify dimatikan (halaman dan rute `/register` dihapus, 2026-11-23). Akun dibuat oleh owner di menu **Pengguna** (§12): tambah akun, ubah nama/email/role, nonaktifkan/aktifkan lagi (akun tidak pernah dihapus), reset kata sandi, dan hapus PIN owner lain. Owner tidak bisa menonaktifkan atau mengubah role dirinya sendiri, dan harus selalu ada minimal satu owner aktif.

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

- ~~Purchase Order + penerimaan parsial~~ → selesai di v2, lihat §10
- Batch & tanggal kedaluwarsa
- ~~Pembayaran campuran (split payment)~~ → tunai + QRIS selesai di v2, lihat §11
- Kasbon / piutang pelanggan
- Multi-cabang & transfer stok
- Mode offline (PWA + antrean sinkronisasi)
- Cetak ESC/POS langsung (WebUSB / QZ Tray)
- Ekspor PDF
- ~~Layar kelola pengguna~~ → selesai di v2, lihat §12
- Owner menutup shift kasir lain (mis. kasir pulang dengan shift terbuka; sekarang akun itu juga tidak bisa dinonaktifkan sampai shift-nya ditutup)
- Owner mereset 2FA/passkey akun lain, dan melindungi 2FA/passkey akun demo publik (§12.4)
- Log audit perubahan akun

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
- **Purchase Order (v2, §10) di demo live** (2026-11-23). Commit `3512f50`: run `tests` hijau di ketiga job (Pest PostgreSQL termasuk `tests/Concurrency/PurchaseOrderReceiveRaceTest`), `demo-db` mode migrate menjalankan `2026_11_23_000001_create_purchase_orders_tables`, dan deploy Vercel `READY`. Dengan akun gudang: draft Aqua Botol 600ml 2 dus (harga beli terakhir Rp 70.900/dus terisi otomatis) → dipesan sebagai PO-20261123-0001 → diterima 1 dus sebagai GR-20261123-0001, yang tertaut ke PO dan membuat status `partial`. Kartu stok: 73 → 97 pcs, HPP rata-rata Rp 2.913,90 = (73 × 2.900,66 + 24 × 2.954,17) / 97. Kartu dashboard "PO belum selesai": 1 pesanan, 0 terlambat, sisa Rp 70.900. Vercel tidak mencatat error runtime. PO uji ini dibiarkan `partial` di DB demo (catatan "Uji fitur PO (Claude)").
- **Urutan deploy tes → migrasi → deploy (§9.6)** (2026-11-23). Push `4885e68` (alur baru dari `c718eb0`): Vercel tidak membuat deployment dari Git, termasuk untuk commit yang membawa `git.deploymentEnabled: false` itu sendiri. `tests` hijau → `demo-db` run [45089118190](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45089118190): job migrate sukses (00:30:00–00:30:31 UTC), lalu job deploy mulai 00:30:33. Percobaan pertama berhenti di "Check configuration" karena `VERCEL_TOKEN` belum diisi, jadi tidak ada yang tayang, sesuai rancangan. Setelah secret diisi, hanya job deploy dijalankan ulang (attempt 2): `vercel deploy --prod` ±2,5 menit (upload + build image di Vercel), deployment `dpl_2jiYFHePQt1Jv2uMsGypwMrXsLDD` `READY` untuk SHA `4885e68`, `/up` 200 dari CI dan dari luar, tanpa error runtime di Vercel. Push ini tidak membawa migrasi baru; yang pertama dengan migrasi adalah `2006dfb` (split payment, §11): `demo-db` menjalankan `2026_11_23_000002_add_split_cash_amount_to_sales` pukul 01:16:23 UTC, job deploy baru mulai 01:16:28 dan selesai 01:18:08 (`dpl_51ULi6wXnWq1VPhNVnUfEGYX9bE9` `READY`), `/up` 200, `/register` 404, tanpa error runtime. Jadi kode baru tayang sesudah skemanya ada. Cabang "migrasi gagal → deploy dilewati" belum pernah terjadi sungguhan. Catatan: metadata Vercel menampilkan `githubCommitRef: HEAD` karena CI men-checkout SHA (detached), bukan branch.
- **Purchase Order: tutup sisa, batal, "Buat PO" dari stok minimum, dan pesan "melebihi sisa" di demo live** (2026-11-23, deploy `9b41724`, akun gudang). (1) Form terima PO-20261123-0001 (sisa 1 dus) dengan atribut `max` dibuang lewat devtools dan qty 2: server menolak dengan "Melebihi sisa pesanan (1)." (teks ini hanya ada di `ReceivePurchaseOrder`), tanpa GR baru. (2) Tutup sisa PO yang sama: alasan kosong ditolak ("Alasan wajib diisi."), dengan alasan → status `closed`, alasan dan waktu tampil, tombol aksi hilang, tetap satu GR. (3) Stok minimum Aqua Botol 600ml dinaikkan sementara 24 → 120 (stok 97): kartu "Stok menipis" menampilkannya dengan tombol "Buat PO" (`?dari=stok-minimum`), form terisi 6 dus (2 × 120 − 97 = 143 pcs, dibulatkan ke atas per dus = 144) dengan harga beli terakhir Rp 70.900/dus → draft → dipesan sebagai PO-20261123-0002. (4) Batal PO itu dengan alasan → `cancelled`, tombol aksi hilang, dan `/pesanan-pembelian/2/terima` mengalihkan kembali ke halaman PO. Stok minimum dikembalikan ke 24; kartu stok Aqua tetap 97 pcs tanpa mutasi baru; kartu "PO belum selesai" kosong; Vercel tidak mencatat error runtime. Kedua PO uji tetap ada di DB demo (catatan berlabel "Claude").
- **Pembayaran campuran tunai + QRIS (§11), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, Midtrans Sandbox). Akun kasir, modal Rp 200.000, Beras Rojolele 5kg Rp 78.000 → F9 → "Tunai + QRIS", tunai Rp 50.000: dialog menampilkan "Sisa lewat QRIS Rp 28.000". Percobaan pertama ditolak Midtrans (409 conflict) karena nomor INV-20261123-0001 sudah dipakai transaksi demo live di akun sandbox yang sama; sale itu otomatis `cancelled` dan pesan Midtrans tampil di dialog. Percobaan kedua (INV-20261123-0002): QR Rp 28.000 dengan keterangan "tunai Rp 50.000 sudah diterima", dibayar lewat simulator sandbox (GoPay) → polling menyelesaikan transaksi, dialog sukses menampilkan Tunai Rp 50.000 / QRIS Rp 28.000, header shift "1 transaksi · kas Rp 250.000", stok beras 5 → 4. Riwayat menampilkan "Tunai + QRIS", detail "Dibayar tunai / Dibayar QRIS", struk "Tunai 50.000 / QRIS 28.000" dengan referensi Midtrans. Pembatalan oleh kasir (Gula Pasir Rp 18.500, tunai Rp 10.000, INV-20261123-0004) awalnya hanya memunculkan toast "QRIS … dibatalkan" tanpa pengingat uang tunai; sekarang toast peringatan "Kembalikan uang tunai Rp 10.000 ke pelanggan." muncul dan kas shift tetap Rp 250.000. Tanpa error di konsol. Data demo hasil seeder: 41 dari 542 transaksi lunas campuran, `stock:rebuild` cocok. Pesan pengembalian di dialog saat QR kedaluwarsa sendiri belum dilihat di browser (perlu menunggu 15 menit); demo live belum dicoba.
- **Kelola pengguna (§12), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, akun owner demo). Menu "Pengguna" tampil di bawah Laporan; menu aksi baris sendiri hanya berisi "Ubah". Tambah kasir "Dewi Lestari" dengan email `Dewi@Kasir.test` → tersimpan sebagai `dewi@kasir.test` dengan badge "Belum ganti kata sandi". Login sebagai Dewi: `/kasir` dialihkan ke "Ganti kata sandi"; kata sandi sementara yang sama ditolak ("Pilih kata sandi yang berbeda…"), kata sandi baru diterima dan Dewi masuk ke "Buka shift". Dewi membuka shift; `DeactivateUser` yang dipanggil dari luar (script dengan app ter-bootstrap, meniru owner di perangkat lain) ditolak "Dewi Lestari masih punya shift terbuka. Tutup shift dulu." Setelah shift ditutup, penonaktifan berhasil dan request Dewi berikutnya dialihkan ke login. Percobaan pertama berakhir di halaman login **tanpa pesan**, karena Action juga menghapus baris `sessions` dan halaman login tidak menampilkan error yang datang lewat redirect. Keduanya diperbaiki (baris sesi tidak lagi dihapus; login menampilkan `errors.email` dari props), lalu diulang: sesi yang sedang berjalan berakhir dengan "Akun ini dinonaktifkan. Hubungi owner.", dan login kata sandi saat nonaktif menampilkan pesan yang sama. Dari UI owner: tab "Nonaktif (1)" → Aktifkan lagi, Reset kata sandi (badge muncul lagi, sesi owner tetap), Nonaktifkan lewat dialog konfirmasi. Tanpa error di konsol. Yang tidak dicoba di browser (hanya tes Pest): akses 403 untuk gudang/kasir, pengaman owner aktif terakhir, hapus PIN owner lain, dan perlindungan akun demo (`POS_PROTECT_DEMO_ACCOUNTS`), karena server lokal berjalan tanpa variabel itu. Demo live belum dicoba.

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
- Webhook `POST /webhooks/midtrans` memverifikasi `signature_key` = SHA512(`order_id` + `status_code` + `gross_amount` + kunci tanda tangan) dengan `hash_equals`. Kuncinya _Signature Secret Key_ (`MIDTRANS_SIGNATURE_KEY`, plus `MIDTRANS_SIGNATURE_KEY_PREVIOUS` opsional saat rotasi); server key hanya dipakai bila keduanya kosong, karena sejak 28 Okt 2026 Midtrans menandatangani notifikasi akun ber-access key baru dengan kunci terpisah (lihat §8). Webhook juga **idempotent**: transaksi dikunci dengan `SELECT … FOR UPDATE`, lalu notifikasi yang tidak mengubah status diabaikan.
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
- **Urutan deploy demo: tes → migrasi → deploy.** Vercel tidak men-deploy saat push (`git.deploymentEnabled: false` di `vercel.json`); workflow `demo-db` menjalankan `migrate --force` ke Neon setelah `tests` hijau pada push ke `main`, lalu job `deploy` (`needs` migrasi yang sukses) men-deploy **commit yang sama** dengan `vercel deploy --prod` (Vercel CLI; image di-build Vercel dari checkout, bukan `--prebuilt`). Tes merah/dibatalkan → tidak ada migrasi maupun deploy; migrasi gagal → deploy dilewati; PR dan branch lain tidak pernah sampai ke job mana pun, dan preview deploy dari Git ikut mati. Run manual hanya dari `main`, dan menolak commit yang belum punya run `tests` hijau. Deploy Hook tidak dipakai karena men-deploy ujung branch saat dipanggil (bukan SHA yang sudah dimigrasi) dan dokumentasi Vercel tidak menjamin hook tetap jalan dengan `deploymentEnabled: false`. Butuh secret `VERCEL_TOKEN`; ID team/project tertulis di workflow (bukan rahasia).
- **Migrasi harus kompatibel mundur (expand → contract).** Selama migrasi berjalan, kode lama masih melayani, jadi migrasi hanya menambah tabel/kolom (kolom baru nullable atau ber-default). Hapus/ganti nama kolom dilakukan di push berikutnya, setelah tidak ada kode yang ter-deploy yang membacanya.
- `DemoSalesSeeder` (30 hari penjualan, restok, retur, QRIS yang sudah lunas) **hanya jalan saat `APP_ENV=local`**, supaya `migrate --seed` di produksi tidak mengisi transaksi palsu. Semua data dibuat lewat Action yang sama dengan aplikasi, jadi buku besar tetap konsisten.

### 9.7 CI

- GitHub Actions menjalankan Pest dalam matriks **SQLite (in memory) dan PostgreSQL 17**.
- Tes di `tests/Concurrency` hanya jalan di PostgreSQL: dua koneksi database (proses PHP kedua) berebut unit terakhir untuk membuktikan `lockForUpdate()` mencegah oversell. Di SQLite tes ini dilewati karena lock tidak berlaku.

### 9.8 Docker (Laravel Sail)

- Container app menjalankan skrip first-boot `docker/sail-start.sh`: membuat `.env` dari `.env.example` bila belum ada, membuat `APP_KEY`, menjalankan `npm ci` dan `npm run build` bila belum ada build, lalu `artisan serve` di port 80.

## 10. v2: Purchase Order + penerimaan parsial (2026-11-23)

Tujuan: toko bisa mencatat **apa yang sudah dipesan ke supplier dan apa yang masih ditunggu**, lalu menerima barangnya bertahap. Penerimaan langsung tanpa PO (§5) tetap ada untuk belanja dadakan.

### 10.1 Prinsip

- **PO tidak menyentuh stok maupun HPP.** Stok hanya bertambah lewat penerimaan barang (GR), yang tetap memanggil `StockLedger::receive()` dengan type `purchase` dan **harga beli aktual** di GR. Harga di PO hanya harga kesepakatan dan menjadi nilai awal form penerimaan.
- `stock:rebuild`, nilai persediaan, laba kotor, dan semua laporan penjualan tidak berubah.
- Jumlah yang sudah diterima **diturunkan dari baris GR** (jumlah `goods_receipt_items.qty` per `purchase_order_item_id`), tidak disimpan dua kali. Yang disimpan di PO hanya `status`, dan status dihitung ulang di transaksi yang sama setiap kali ada penerimaan.

### 10.2 Skema (migrasi baru, hanya menambah)

`purchase_orders`

| Kolom                       | Keterangan                                                                                        |
| --------------------------- | ------------------------------------------------------------------------------------------------- |
| `number` (nullable, unik)   | `PO-YYYYMMDD-0001`, diberikan saat **dipesan** (draft belum bernomor, seperti transaksi ditahan)  |
| `supplier_id`               | wajib; `restrictOnDelete` (supplier yang punya PO tidak bisa dihapus, sama seperti yang punya GR) |
| `user_id`                   | pembuat                                                                                           |
| `status`                    | `App\Enums\PurchaseOrderStatus`: `draft`, `ordered`, `partial`, `received`, `closed`, `cancelled` |
| `expected_at` (date, null)  | perkiraan barang datang; dipakai untuk menandai PO terlambat                                      |
| `ordered_at`, `finished_at` | timestamp saat dipesan dan saat selesai / ditutup / dibatalkan                                    |
| `total`                     | integer Rupiah, Σ subtotal baris pesanan                                                          |
| `note`                      | catatan bebas                                                                                     |
| `close_reason`              | alasan, wajib saat menutup sisa pesanan atau membatalkan                                          |

`purchase_order_items`: `purchase_order_id` (cascade), `product_id`, `unit_id` (satuan beli, mis. dus), `qty` (dalam satuan itu), `unit_price` (integer Rupiah per satuan itu), `subtotal`. Satu produk + satuan hanya boleh muncul sekali per PO.

Tambahan kolom nullable: `goods_receipts.purchase_order_id` dan `goods_receipt_items.purchase_order_item_id` (keduanya `restrictOnDelete`). GR lama dan GR tanpa PO tetap `null`.

### 10.3 Alur & status

```
draft ──Pesan──▶ ordered ──terima sebagian──▶ partial ──terima sisa──▶ received
  │                │                            │
 hapus           Batal (belum ada              Tutup sisa (supplier tidak
                 penerimaan)                    mengirim sisanya) ──▶ closed
                   ▼
               cancelled
```

- **Draft**: bebas diubah atau dihapus. Pilih supplier, perkiraan tanggal datang, dan baris (produk, satuan, qty, harga). Harga awal per baris = harga beli terakhir produk+satuan itu dari GR, bila ada.
- **Pesan**: nomor PO dibuat (`DocumentNumber`, di dalam transaksi), baris dikunci dan tidak bisa diubah lagi.
- **Terima barang** (dari halaman PO): form GR terisi sisa qty tiap baris, satuan mengikuti baris PO, harga awal = harga PO tapi **boleh diubah** sesuai faktur. Qty per baris 0 sampai sisa; minimal satu baris > 0. **Tidak boleh melebihi sisa** dan tidak bisa menambah produk di luar PO; kelebihan atau barang bonus dicatat sebagai GR biasa tanpa PO. Setelah disimpan, status menjadi `partial` atau `received`.
- **Tutup sisa** (`partial → closed`): sisa pesanan tidak akan datang. **Batal** (`ordered → cancelled`): hanya bila belum ada penerimaan. Keduanya wajib mengisi alasan.
- Konversi satuan dibaca saat GR dibuat (seperti GR biasa) dan di-snapshot di `goods_receipt_items.conversion`. Sisa pesanan dihitung dalam satuan baris PO, jadi perubahan konversi produk tidak mengacaukan sisa.

### 10.4 Konkurensi & transaksi

- Semua aksi yang mengubah PO (`OrderPurchaseOrder`, `ReceivePurchaseOrder`, `ClosePurchaseOrder`, `CancelPurchaseOrder`) mengunci baris PO dengan `SELECT … FOR UPDATE` lalu memeriksa ulang status. Dua penerimaan bersamaan untuk PO yang sama berjalan bergantian, jadi sisa tidak bisa diterima dua kali.
- Urutan kunci: **PO dulu, lalu produk** dalam urutan `product_id` (dari `ReceiveGoods`), sama untuk semua alur, sehingga tidak ada deadlock dengan transaksi kasir.
- `ReceivePurchaseOrder` memvalidasi baris terhadap PO lalu memanggil `ReceiveGoods` di transaksi yang sama. `ReceiveGoods::handle()` mendapat parameter opsional (`purchaseOrder`, dan `purchase_order_item_id` per baris), jadi pemanggil lama (`CatalogSeeder`, `DemoSalesSeeder`, controller GR) tidak berubah.

### 10.5 Layar & route (owner + gudang)

| URL                                 | Isi                                                                                    |
| ----------------------------------- | -------------------------------------------------------------------------------------- |
| `GET /pesanan-pembelian`            | daftar PO: filter status & supplier, progres terima (mis. 12/20 dus), tanda terlambat  |
| `GET /pesanan-pembelian/baru`       | form draft; `?dari=stok-minimum` mengisi baris dari produk di bawah stok minimum       |
| `GET /pesanan-pembelian/{po}`       | detail: baris, dipesan / diterima / sisa, daftar GR terkait, tombol aksi sesuai status |
| `…/{po}/ubah`, `PUT`, `DELETE`      | ubah / hapus draft                                                                     |
| `POST …/{po}/pesan`                 | draft → ordered                                                                        |
| `GET`/`POST …/{po}/terima`          | form dan simpan penerimaan dari PO (membuat GR, lalu diarahkan ke detail GR)           |
| `POST …/{po}/tutup`, `…/{po}/batal` | tutup sisa / batalkan                                                                  |

- Nama route `purchase-orders.*`, dipanggil dari frontend lewat Wayfinder. Menu sidebar "Pesanan pembelian" di atas "Penerimaan barang".
- Detail GR menampilkan tautan ke PO-nya, dan daftar GR bisa difilter "dari PO / tanpa PO".
- **Isi dari stok minimum**: setiap produk aktif dengan `stock_on_hand ≤ min_stock` (definisi yang sama dengan kartu stok menipis) menjadi satu baris, urut nama; satuan = satuan GR terakhir produk itu (atau satuan dasar), qty = cukup untuk mencapai **2 × stok minimum**, dibulatkan ke atas dalam satuan itu. Pengguna tinggal menghapus baris yang bukan untuk supplier ini. Kartu stok minimum di dashboard mendapat tombol "Buat PO".

### 10.6 Dampak ke laporan

- Laporan penjualan, laba kotor, produk terlaris, nilai persediaan, dan rekap shift **tidak berubah**: semuanya membaca `sales` dan `stock_movements`, sedangkan PO tidak membuat movement.
- Dashboard owner & gudang mendapat kartu **"PO belum selesai"**: jumlah PO `ordered`/`partial`, nilai sisa pesanan (Σ sisa qty × harga PO), dan berapa yang sudah lewat `expected_at`.
- Di luar cakupan ini: ekspor Excel PO, cetak/kirim PO ke supplier, harga per supplier, utang dagang.

### 10.7 Data demo & deploy

- `DemoSalesSeeder`: sebagian restok dibuat lewat PO (dipesan lalu diterima penuh), dan di hari-hari terakhir ada satu PO diterima sebagian, satu PO dipesan yang belum datang (satu di antaranya terlambat), serta satu draft. Semuanya lewat Action yang sama.
- Migrasinya hanya menambah tabel dan kolom nullable, jadi workflow `demo-db` (migrate) aman untuk DB demo yang sudah ada. DB demo live **baru berisi contoh PO setelah `demo-db` mode `reset`**, yang tetap menunggu izin; tanpa itu PO bisa dicoba dengan membuatnya langsung di demo.

### 10.8 Rencana tes (ditulis lebih dulu)

- Siklus status: draft bisa diubah/dihapus; setelah dipesan baris terkunci dan bernomor `PO-…`; aksi yang tidak sah untuk status tertentu ditolak dengan pesan validasi.
- Penerimaan: sebagian lalu sisanya → `partial` → `received`; melebihi sisa, produk di luar PO, PO `draft`/`closed`/`cancelled` ditolak tanpa membuat GR maupun movement.
- Stok & HPP: penerimaan dari PO memakai harga di GR (bukan harga PO) untuk moving average; `stock:rebuild` tetap cocok.
- Tutup sisa / batal: alasan wajib; batal ditolak setelah ada penerimaan.
- Isi dari stok minimum: produk, satuan, dan qty saran sesuai aturan §10.5.
- Akses: kasir mendapat 403 di semua route PO.
- Konkurensi (PostgreSQL, `tests/Concurrency`): dua proses menerima sisa terakhir PO yang sama, hanya satu yang berhasil.
- `DemoSalesSeeder` tetap jalan (`migrate:fresh --seed` dengan `APP_ENV=local`).

## 11. v2: Pembayaran campuran tunai + QRIS (2026-11-23)

Tujuan: pelanggan bisa membayar **sebagian tunai dan sisanya QRIS** dalam satu transaksi (mis. belanja Rp 87.500, bayar Rp 50.000 tunai, Rp 37.500 lewat QRIS). Kombinasi lain (dua QRIS, kartu) di luar cakupan.

### 11.1 Prinsip

- Satu transaksi tetap satu `Sale` dengan satu nomor INV dan satu struk; tidak ada transaksi pecahan.
- Bagian QRIS memakai mesin status QRIS yang sudah ada (`pending_payment → completed / cancelled`, §9.3). Stok baru keluar saat QRIS lunas, sama seperti QRIS penuh.
- Uang tunai diterima kasir **di awal**, sebelum QR ditampilkan, dengan jumlah pas (tanpa kembalian). Uang itu baru dihitung masuk laci setelah transaksi `completed`; kalau QRIS batal/kedaluwarsa, layar meminta kasir mengembalikan uang tunainya.
- Angka laporan selalu memisahkan bagian tunai dan bagian QRIS, jadi kas laci dan rekonsiliasi Midtrans tetap tepat.

### 11.2 Skema (migrasi baru, hanya menambah)

- `PaymentMethod::Split` (`split`, label "Tunai + QRIS"); kolom `sales.payment_method` sudah `varchar(10)`.
- Kolom baru `sales.split_cash_amount` (`unsignedBigInteger`, nullable): bagian tunai, hanya diisi bila `payment_method = split`. Bagian QRIS = `total − split_cash_amount`, tidak disimpan terpisah.
- Tidak ada backfill: transaksi lama tunai/QRIS tidak berubah. Migrasi ini aman untuk kode lama (expand → contract, §9.6), karena kode lama tidak pernah menulis `split`.

### 11.3 Aturan

- Bagian tunai minimal Rp 1 dan lebih kecil dari total, jadi bagian QRIS minimal Rp 1. Total nol tidak bisa dibayar campuran.
- Midtrans di-charge sebesar **bagian QRIS**; webhook mencocokkan `gross_amount` dengan bagian QRIS (bukan total). Webhook, polling dan tombol batal menerima `qris` maupun `split`.
- Saat lunas: `paid_amount = total`, `change_amount = 0`, sama seperti QRIS penuh.
- Kas yang diharapkan di laci (`Shift::expectedCash`) = modal + Σ bagian tunai transaksi `completed` (tunai penuh = total, campuran = `split_cash_amount`) − refund. Hitungan "penjualan tunai (N transaksi)" di tutup shift ikut menghitung transaksi campuran.
- Lunas terlambat setelah dibatalkan (`cancelled → completed`, §9.3) tetap berlaku untuk campuran. Karena kasir sudah diminta mengembalikan uang tunainya, selisih laci akan terlihat saat tutup shift; risiko ini diterima (jendelanya hanya beberapa detik setelah batal) dan dicatat, bukan disembunyikan.
- Retur tidak berubah: refund selalu tunai dari laci shift yang berjalan, apa pun cara bayarnya (§9.2).

### 11.4 Layar

- Dialog bayar (F9) mendapat pilihan ketiga **"Tunai + QRIS"**: input "Tunai diterima", baris "Sisa lewat QRIS", tombol "Tampilkan QRIS Rp …". Tombol nonaktif bila tunai 0 atau ≥ total.
- Dialog QR menampilkan nominal bagian QRIS dan baris "Tunai Rp … sudah diterima". Saat QR kedaluwarsa/dibatalkan Midtrans, dialog menampilkan "Kembalikan uang tunai Rp … ke pelanggan"; saat kasir menekan "Batalkan QRIS", dialog tertutup dan pesan yang sama muncul sebagai toast peringatan.
- Dialog "Transaksi berhasil", struk, riwayat transaksi (filter dan detail) menampilkan kedua bagian: "Tunai Rp …" dan "QRIS Rp …" (+ referensi Midtrans di struk).

### 11.5 Dampak ke laporan

- Ringkasan penjualan dan rekap shift: kolom Tunai dan QRIS dihitung dari bagian masing-masing, bukan dari `payment_method` saja. Ekspresi SQL-nya satu tempat (`App\Reports\Sql::cashPart()` / `qrisPart()`), dipakai juga oleh `Shift`.
- Laba kotor tidak terpengaruh (tidak bergantung cara bayar).

### 11.6 Data demo

- `DemoSalesSeeder`: sekitar 1 dari 10 transaksi dibayar campuran (tunai dibulatkan ke bawah ke Rp 10.000, sisanya QRIS), ditulis langsung sebagai lunas seperti QRIS demo.

### 11.7 Rencana tes (ditulis lebih dulu)

- Mulai campuran: sale `pending_payment`, `split_cash_amount` tersimpan, Midtrans di-charge bagian QRIS (fake client), stok belum keluar.
- Validasi: tunai 0, tunai ≥ total, total nol ditolak tanpa membuat sale.
- Lunas lewat polling dan webhook: stok keluar sekali, `paid_amount = total`; webhook dengan `gross_amount` = total (bukan bagian QRIS) ditolak `amount_mismatch`.
- Batal/kedaluwarsa: sale `cancelled`, kas laci tidak bertambah.
- Kas laci & tutup shift: modal + tunai penuh + bagian tunai campuran − refund.
- Laporan ringkasan & rekap shift: kolom Tunai/QRIS memisahkan bagian campuran; total tetap = Tunai + QRIS.
- Struk dan riwayat menampilkan kedua bagian; filter riwayat `split`.
- `DemoSalesSeeder` tetap jalan.

## 12. v2: Kelola pengguna (owner saja) (2026-11-23)

Tujuan: owner bisa **menambah akun, mengubah nama/email/role, menonaktifkan dan mengaktifkan lagi, serta mereset kata sandi dan PIN** tanpa seeder/tinker. Akun tidak pernah dihapus, karena transaksi, shift, retur, GR/opname, dan PO merujuk ke `users` dengan `restrictOnDelete`.

### 12.1 Kondisi sekarang (hasil pemeriksaan)

- `users`: `name`, `email` (unik), `email_verified_at`, `password`, `pin_hash` (§9.2), kolom 2FA, `remember_token`. Role lewat spatie (`model_has_roles`), satu role per akun.
- Login lewat Fortify (kata sandi, 2FA, passkey). Semua route POS memakai `verified`, jadi akun tanpa `email_verified_at` tidak bisa masuk; aplikasi tidak mengirim email (`MAIL_MAILER=log`).
- PIN owner sudah bisa diatur **oleh owner itu sendiri** di Pengaturan → PIN owner (konfirmasi kata sandi). `OwnerPin::approve()` mencocokkan PIN terhadap semua owner yang punya PIN.
- Sesi di demo memakai driver `database` (Dockerfile); lokal/tes bisa driver lain. Belum ada `AuthenticateSession`, jadi ganti kata sandi tidak mengakhiri sesi lain.
- Starter kit masih punya **hapus akun sendiri** (`DELETE settings/profile`). Untuk akun yang punya riwayat ini gagal di FK (500); untuk akun tanpa riwayat ini benar-benar menghapus, termasuk owner terakhir.

### 12.2 Skema (migrasi baru, hanya menambah)

- `users.deactivated_at` (timestamp, nullable). `null` = aktif. Tidak ada backfill: semua akun lama tetap aktif, dan kode lama yang tidak membaca kolom ini tetap jalan (expand → contract, §9.6).
- `users.must_change_password` (boolean, default `false`): diisi `true` saat owner membuat akun atau mereset kata sandi.

### 12.3 Aturan

- **Tambah akun**: nama, email (unik), role, kata sandi awal (aturan `Password::defaults()`, diketik owner dua kali). `email_verified_at = now()` karena owner yang menjamin emailnya; tidak ada email undangan. PIN tidak diisi.
- **Ubah**: nama, email, role. Email yang diubah owner tetap terverifikasi.
- **Nonaktifkan / aktifkan lagi**: mengisi / mengosongkan `deactivated_at`. Akun nonaktif tetap tampil di laporan, riwayat, dan filter kasir; hanya tidak bisa masuk.
- **Akun nonaktif tidak bisa masuk dan sesinya berakhir**:
    - Login kata sandi ditolak di `Fortify::authenticateUsing` dengan pesan "Akun ini dinonaktifkan. Hubungi owner." (sebelum tantangan 2FA).
    - Middleware `EnsureUserIsActive` di grup `web`: kalau pengguna yang sedang login nonaktif → logout, sesi di-invalidate, kembali ke halaman login dengan pesan yang sama. Ini menutup sesi yang sedang berjalan, cookie remember-me, dan login passkey, apa pun driver sesinya. Saat dinonaktifkan, `remember_token` juga diganti. Baris `sessions` sengaja tidak dihapus: percobaan di browser menunjukkan sesi yang dihapus berakhir di halaman login tanpa penjelasan, sedangkan lewat middleware pengguna melihat pesannya.
- **Reset kata sandi oleh owner**: owner mengetik kata sandi sementara; `must_change_password = true`; semua sesi akun itu berakhir (`AuthenticateSession` ditambahkan ke grup `web`, sehingga sesi dengan hash kata sandi lama di-logout; sesi owner sendiri tidak terpengaruh). Saat login berikutnya, pengguna diarahkan ke halaman **"Ganti kata sandi"** dan tidak bisa membuka halaman lain sampai menggantinya. Alasannya: owner tidak seharusnya tetap mengetahui kata sandi kasir.
- **PIN owner**: owner bisa **mereset (menghapus) PIN owner lain**, misalnya karena lupa atau bocor; owner itu lalu mengatur PIN barunya sendiri di Pengaturan → PIN owner. Owner tidak bisa _mengisi_ PIN orang lain, karena PIN menandai siapa yang menyetujui retur (`sale_returns.approved_by`). PIN milik sendiri tetap diatur di Pengaturan. Akun owner nonaktif dan akun yang diturunkan dari owner tidak lagi dicocokkan oleh `OwnerPin::approve()` (PIN-nya ikut dihapus saat role diturunkan).
- **Pengaman**:
    - Owner tidak bisa menonaktifkan dirinya sendiri atau mengubah role dirinya sendiri (tombol disembunyikan dan server menolak).
    - Harus selalu ada **minimal satu owner aktif**. Aksi yang menonaktifkan atau menurunkan owner mengunci baris owner aktif (`SELECT … FOR UPDATE`) lalu menghitung ulang, jadi dua owner yang saling menonaktifkan bersamaan tidak bisa membuat toko tanpa owner.
    - **Akun yang shift-nya masih terbuka** (kasir maupun owner yang membuka shift) tidak bisa dinonaktifkan, dan tidak bisa diubah ke role tanpa akses kasir (gudang): "Tutup shift dulu." Shift harus ditutup dengan hitungan laci, dan transaksi QRIS yang menunggu pembayaran tergantung pada shift itu. Akibatnya, kasir yang pergi dengan shift terbuka tetap harus login untuk menutupnya; menutup shift orang lain oleh owner di luar cakupan ini (dicatat di roadmap).
- **Hapus akun sendiri dihapus** (route, tombol, dan request-nya), digantikan oleh penonaktifan oleh owner.

### 12.4 Demo live

Akun demo dibuka untuk umum dengan kata sandi yang tertulis di README, jadi pengunjung bisa saja menonaktifkan owner demo atau mengganti kata sandinya. Konfigurasi baru `pos.protect_demo_accounts` (env `POS_PROTECT_DEMO_ACCOUNTS`, default `false`; diisi `true` di blok `ENV` `Dockerfile`, bukan lewat pengaturan Vercel): ketiga akun dari `DemoUserSeeder` tidak bisa dinonaktifkan, diubah role/emailnya, direset kata sandi/PIN-nya, dan juga tidak bisa mengganti kata sandi, email, maupun PIN-nya sendiri di Pengaturan. Akun baru yang dibuat pengunjung bisa dikelola penuh. Lokal dan tes memakai default `false`.

Celah yang **belum** ditutup (sudah ada sebelum §12): pengunjung masih bisa mengaktifkan 2FA atau mendaftarkan passkey di akun demo (Pengaturan → Keamanan). 2FA yang diaktifkan pengunjung akan mengunci akun demo itu bagi orang lain, dan sejauh ini hanya bisa dipulihkan dengan `demo-db` mode `reset` atau mengosongkan kolom 2FA di DB. Dicatat di roadmap §6.

### 12.5 Layar & route (`role:owner`; gudang dan kasir 403)

| URL                                 | Isi                                                                                                      |
| ----------------------------------- | -------------------------------------------------------------------------------------------------------- |
| `GET /pengguna`                     | daftar: nama, email, role, status (aktif/nonaktif), PIN (ada/belum), shift terbuka; filter role & status |
| `POST /pengguna`                    | tambah akun (dialog)                                                                                     |
| `PUT /pengguna/{user}`              | ubah nama/email/role (dialog)                                                                            |
| `POST /pengguna/{user}/nonaktifkan` | nonaktifkan (dialog konfirmasi)                                                                          |
| `POST /pengguna/{user}/aktifkan`    | aktifkan lagi                                                                                            |
| `PUT /pengguna/{user}/kata-sandi`   | reset kata sandi (dialog; bukan untuk akun sendiri)                                                      |
| `DELETE /pengguna/{user}/pin`       | hapus PIN owner lain                                                                                     |
| `GET`/`PUT /ganti-kata-sandi`       | wajib ganti kata sandi (semua role, `auth`)                                                              |

- Semua aksi ada di satu halaman: tabel dengan menu aksi per baris, form di dialog (seperti master supplier), bukan halaman tambah/ubah terpisah. Aksi yang tidak berlaku (akun sendiri, shift terbuka, akun demo terlindungi) dinonaktifkan di menu, dan server tetap menolaknya.
- Nama route `users.*` dan `forced-password.*`, dipanggil lewat Wayfinder. Menu sidebar owner "Pengguna" (di bawah "Laporan").
- Write operation di `app/Actions/Users/*` (`CreateUser`, `UpdateUser`, `DeactivateUser`, `ReactivateUser`, `SetTemporaryPassword`, `ResetOwnerPin`; aturan bersama di trait `GuardsAccounts`), masing-masing memegang transaksinya; pengaman §12.3 ada di Action, bukan hanya di UI. Middleware `AuthenticateSession`, `EnsureUserIsActive`, `RequirePasswordChange` di grup `web`.

### 12.6 Di luar cakupan

Log audit siapa mengubah apa, reset 2FA/passkey milik orang lain, undangan lewat email, menutup shift orang lain, banyak role per akun, permission per fitur.

### 12.7 Rencana tes (ditulis lebih dulu)

- Akses: gudang dan kasir 403 di semua route `users.*`; tamu diarahkan ke login.
- Tambah: akun dengan role terpilih, `email_verified_at` terisi, `must_change_password = true`; email ganda dan kata sandi lemah ditolak.
- Ubah nama/email/role; ganti role owner → bukan owner menghapus `pin_hash`.
- Nonaktif: login kata sandi ditolak dengan pesan; sesi yang sudah login di-logout pada request berikutnya; aktif lagi bisa login.
- Pengaman: nonaktifkan/ubah role diri sendiri ditolak; owner aktif terakhir tidak bisa dinonaktifkan/diturunkan; akun dengan shift terbuka tidak bisa dinonaktifkan atau dijadikan gudang.
- Reset kata sandi: kata sandi lama tidak berlaku, sesi lama berakhir, login berikutnya dialihkan ke "Ganti kata sandi" sampai diganti.
- Reset PIN: `pin_hash` owner lain kosong; `OwnerPin::approve()` tidak menerima PIN owner nonaktif.
- Hapus akun sendiri: route sudah tidak ada.
- `pos.protect_demo_accounts = true`: aksi pada tiga akun demo ditolak, akun lain tetap bisa dikelola.
- `DemoSalesSeeder` tetap jalan (`migrate:fresh --seed` dengan `APP_ENV=local`).
