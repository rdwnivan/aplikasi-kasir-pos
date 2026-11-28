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

Tidak ada pendaftaran mandiri: setiap akun harus punya role, jadi fitur `registration` Fortify dimatikan (halaman dan rute `/register` dihapus, 2026-11-23). Akun dibuat oleh owner di menu **Pengguna** (§12): tambah akun, ubah nama/email/role, nonaktifkan/aktifkan lagi (akun tidak pernah dihapus), reset kata sandi, hapus PIN owner lain, dan reset 2FA & passkey akun lain (§14); semua perubahan akun tercatat di Pengguna → Riwayat akun (§15). Owner tidak bisa menonaktifkan atau mengubah role dirinya sendiri, dan harus selalu ada minimal satu owner aktif.

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
- ~~Batch & tanggal kedaluwarsa~~ → selesai di v2, lihat §19
- ~~Pembayaran campuran (split payment)~~ → tunai + QRIS selesai di v2, lihat §11
- ~~Kasbon / piutang pelanggan~~ → selesai di v2, lihat §17 (pelunasan lewat QRIS: §18)
- Multi-cabang & transfer stok
- Mode offline (PWA + antrean sinkronisasi)
- Cetak ESC/POS langsung (WebUSB / QZ Tray)
- ~~Ekspor PDF~~ → selesai di v2, lihat §16
- ~~Layar kelola pengguna~~ → selesai di v2, lihat §12
- ~~Owner menutup shift kasir lain~~ → selesai di v2, lihat §13
- ~~Owner mereset 2FA/passkey akun lain~~ → selesai di v2, lihat §14
- ~~Melindungi 2FA/passkey akun demo publik~~ → selesai, lihat §12.4.1
- ~~Log audit perubahan akun~~ → selesai di v2, lihat §15

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
- **Pembayaran campuran tunai + QRIS (§11), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, Midtrans Sandbox). Akun kasir, modal Rp 200.000, Beras Rojolele 5kg Rp 78.000 → F9 → "Tunai + QRIS", tunai Rp 50.000: dialog menampilkan "Sisa lewat QRIS Rp 28.000". Percobaan pertama ditolak Midtrans (409 conflict) karena nomor INV-20261123-0001 sudah dipakai transaksi demo live di akun sandbox yang sama; sale itu otomatis `cancelled` dan pesan Midtrans tampil di dialog. Percobaan kedua (INV-20261123-0002): QR Rp 28.000 dengan keterangan "tunai Rp 50.000 sudah diterima", dibayar lewat simulator sandbox (GoPay) → polling menyelesaikan transaksi, dialog sukses menampilkan Tunai Rp 50.000 / QRIS Rp 28.000, header shift "1 transaksi · kas Rp 250.000", stok beras 5 → 4. Riwayat menampilkan "Tunai + QRIS", detail "Dibayar tunai / Dibayar QRIS", struk "Tunai 50.000 / QRIS 28.000" dengan referensi Midtrans. Pembatalan oleh kasir (Gula Pasir Rp 18.500, tunai Rp 10.000, INV-20261123-0004) awalnya hanya memunculkan toast "QRIS … dibatalkan" tanpa pengingat uang tunai; sekarang toast peringatan "Kembalikan uang tunai Rp 10.000 ke pelanggan." muncul dan kas shift tetap Rp 250.000. Tanpa error di konsol. Data demo hasil seeder: 41 dari 542 transaksi lunas campuran, `stock:rebuild` cocok. Pesan pengembalian di dialog saat QR kedaluwarsa sendiri baru terlihat setelah bug polling diperbaiki (lihat entri "QRIS kedaluwarsa lewat polling" di bawah). **Demo live** (2026-11-26, pemilik repo login sendiri sebagai `kasir@kasir.test`, Claude tidak mengetik kata sandi): halaman Buka shift lebih dulu menampilkan pemberitahuan §13 "Shift Anda sebelumnya ditutup oleh Budi Santoso pada 26 Nov 2026, 20.48: …". Shift baru modal Rp 200.000 → Beras Rojolele 5kg Rp 78.000 (stok 8) → F9 → "Tunai + QRIS", tunai Rp 50.000: "Sisa lewat QRIS Rp 28.000" → QR Midtrans sandbox INV-20261126-0001 Rp 28.000 dengan "tunai Rp 50.000 sudah diterima" (kali ini tanpa bentrok nomor). Pemilik repo membayar lewat simulator QRIS sandbox; transaksi menjadi lunas: keranjang kosong, header "1 transaksi · kas Rp 250.000", stok beras 8 → 7. Riwayat: "Tunai + QRIS" Rp 78.000; detail "Dibayar tunai Rp 50.000 / Dibayar QRIS Rp 28.000"; struk "Tunai 50.000 / QRIS 28.000" + referensi Midtrans. Tanpa error runtime Vercel. Tidak terlihat: dialog "Transaksi berhasil" (sudah tertutup saat dicek), dan apakah pelunasan datang dari webhook atau polling (log runtime tidak menunjukkannya). Shift kasir ini dibiarkan terbuka di DB demo.
- **Kelola pengguna (§12), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, akun owner demo). Menu "Pengguna" tampil di bawah Laporan; menu aksi baris sendiri hanya berisi "Ubah". Tambah kasir "Dewi Lestari" dengan email `Dewi@Kasir.test` → tersimpan sebagai `dewi@kasir.test` dengan badge "Belum ganti kata sandi". Login sebagai Dewi: `/kasir` dialihkan ke "Ganti kata sandi"; kata sandi sementara yang sama ditolak ("Pilih kata sandi yang berbeda…"), kata sandi baru diterima dan Dewi masuk ke "Buka shift". Dewi membuka shift; `DeactivateUser` yang dipanggil dari luar (script dengan app ter-bootstrap, meniru owner di perangkat lain) ditolak "Dewi Lestari masih punya shift terbuka. Tutup shift dulu." Setelah shift ditutup, penonaktifan berhasil dan request Dewi berikutnya dialihkan ke login. Percobaan pertama berakhir di halaman login **tanpa pesan**, karena Action juga menghapus baris `sessions` dan halaman login tidak menampilkan error yang datang lewat redirect. Keduanya diperbaiki (baris sesi tidak lagi dihapus; login menampilkan `errors.email` dari props), lalu diulang: sesi yang sedang berjalan berakhir dengan "Akun ini dinonaktifkan. Hubungi owner.", dan login kata sandi saat nonaktif menampilkan pesan yang sama. Dari UI owner: tab "Nonaktif (1)" → Aktifkan lagi, Reset kata sandi (badge muncul lagi, sesi owner tetap), Nonaktifkan lewat dialog konfirmasi. Tanpa error di konsol. Yang tidak dicoba di browser (hanya tes Pest): akses 403 untuk gudang/kasir, pengaman owner aktif terakhir, hapus PIN owner lain, dan perlindungan akun demo (`POS_PROTECT_DEMO_ACCOUNTS`), karena server lokal berjalan tanpa variabel itu. **Deploy** (push `386bb54`): `tests` hijau di ketiga job (Pest PostgreSQL selesai 01:58:10 UTC) → `demo-db` run [45097507783](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45097507783) menjalankan `2026_11_23_000003_add_account_status_to_users` pukul 01:58:48 → job deploy 01:58:54–02:00:39, `dpl_AZV59cFotV3YqXmCy8avZeivj2jC` `READY` untuk SHA itu. Dari luar: `/up` 200, `/login` 200, `/pengguna` dan `/ganti-kata-sandi` mengalihkan tamu ke login; Vercel tidak mencatat error runtime sejak deploy. Fitur ini belum dicoba sambil login di demo live, termasuk perlindungan akun demo.
- **2FA dan passkey akun demo (§12.4.1)** (2026-11-26). Pest: `DemoAccountSignInTest` (19 tes) ditulis lebih dulu dan merah sebelum implementasi. **Browser lokal** (`php -S` port 8002 dengan `POS_PROTECT_DEMO_ACCOUNTS=true`, DB `kasir_pos_test` hasil `migrate:fresh --seed`): `owner@kasir.test` diberi 2FA terkonfirmasi + satu passkey lewat script (meniru akun yang dikunci pengunjung). Login kata sandi langsung masuk ke dashboard tanpa tantangan 2FA, lalu DB menunjukkan kolom 2FA kosong dan 0 passkey. Halaman Keamanan akun itu menampilkan status dan catatan per bagian, tanpa tombol Aktifkan 2FA / Tambah / Hapus passkey. `GET /user/passkeys/options` dan `POST /user/two-factor-authentication` dari halaman itu dijawab 403 dengan pesan demo. Akun lokal bukan demo di server yang sama masih melihat "Aktifkan 2FA" dan mendapat QR + kunci manual (konfirmasi kode TOTP hanya dibuktikan di Pest). Dengan pengaturan mati (port 8001), halaman Keamanan owner demo tampil seperti biasa. Pendaftaran passkey sungguhan (WebAuthn) tidak dicoba. **Deploy** (push `5aaea74`, tanpa migrasi): `tests` [45806380085](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45806380085) hijau di ketiga job (Pest SQLite 309 lulus + 4 dilewati, PostgreSQL 313 lulus; selesai 11:00:54 UTC) → `demo-db` [45806643270](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45806643270) migrate 11:01:00–11:01:32 → deploy 11:01:34–11:05:27, `dpl_9z2Mryv9Rg14My2RmxiyF1hXu68m` `READY` untuk SHA itu. `/up` 200, `/login` 200, Vercel tidak mencatat error runtime dalam satu jam setelahnya. **Demo live sambil login** (2026-11-26, pemilik repo login sendiri sebagai `owner@kasir.test`): Pengaturan → Keamanan menampilkan "2FA tidak aktif di akun ini." dan catatan per bagian, tanpa tombol Aktifkan 2FA / Tambah / Hapus passkey, daftar passkey kosong (jadi tidak ada 2FA/passkey pengunjung yang tersisa, atau sudah terhapus oleh login itu; tidak bisa dibedakan dari luar). Dari sesi owner itu, `fetch` tanpa konfirmasi kata sandi ke enable, confirm, regenerate, disable, passkey options, dan passkey store semuanya 403 dengan pesan demo; `DELETE /user/passkeys/999999` 404 karena passkey itu tidak ada (route model binding berjalan sebelum middleware). Di Pengguna, ketiga akun berbadge "Akun demo"; untuk Siti, "Reset kata sandi" dan "Nonaktifkan" nonaktif di menu, kolom Email dan Role di dialog "Ubah" nonaktif; `PUT /pengguna/2` (email + role baru) dan `POST /pengguna/3/nonaktifkan` langsung ke server keduanya 422 dengan pesan demo, data tetap. Tanpa error konsol, tanpa error runtime Vercel. Alur tambah/reset/nonaktifkan akun uji di live tidak dicoba, karena butuh mengetik kata sandi di produksi (sudah dicoba di browser lokal, lihat entri §12 di atas).
- **Owner menutup shift kasir lain (§13), browser lokal** (2026-11-26, commit `44210f3`). Pest: `OwnerCloseShiftTest` (21 tes) ditulis lebih dulu dan merah sebelum implementasi; `ShiftCloseRaceTest` (PostgreSQL) lulus, dan merah bila kunci shift di `CompleteSale` dibuang (dicek dengan menghapusnya sementara). Seluruh suite: SQLite 330 lulus + 5 dilewati, PostgreSQL 335 lulus. **Browser** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed`): kasir demo membuka shift (modal Rp 200.000), menjual Gula Pasir Rp 18.500 tunai, menahan satu keranjang, dan membiarkan satu barang di keranjang aktif. Owner login di tab lain (`owner.localhost:8001`, cookie terpisah) → Laporan → Rekap kasir & shift: baris "Berjalan" punya tombol "Tutup shift"; halaman tutup menampilkan kas seharusnya Rp 218.500, "Transaksi terakhir 1 menit yang lalu" dan "1 transaksi ditahan akan dihapus". Hitungan Rp 218.000 + alasan → toast "Shift Andi Pratama ditutup. Kas kurang Rp 500.", baris rekap "oleh Budi Santoso: {alasan}", total selisih −Rp 500. DB: `closed_by` = owner, `close_reason` tersimpan, selisih −500, keranjang ditahan terhapus. Tab kasir yang masih terbuka menekan bayar → tidak ada sale baru, layar beralih ke Buka shift dengan "Shift Anda sebelumnya ditutup oleh Budi Santoso pada … : {alasan}" (toast "Buka shift dulu…" tidak sempat terlihat karena halaman langsung berganti). Setelah kasir membuka shift baru, badge "Shift terbuka" di Pengguna menaut ke halaman tutup shift itu dan menu baris berisi "Tutup shift" di atas "Nonaktifkan (tutup shift dulu)". Tanpa error konsol. Yang hanya dibuktikan di Pest: pemberesan QRIS menunggu (lunas / dibatalkan / Midtrans gagal), ekspor Excel "Ditutup oleh"/"Alasan", 403 untuk kasir/gudang, dan mode demo publik. **Deploy** (push `dc29af2`, membawa migrasi `2026_11_26_000001_add_closed_by_to_shifts`): `tests` [45814066987](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45814066987) hijau di ketiga job (Pest PostgreSQL selesai 11:51:56 UTC) → `demo-db` [45814394092](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45814394092): migrasi selesai 11:52:39, job deploy baru mulai 11:52:44 dan selesai 11:54:23, `dpl_EgnMjkiq5A7gTKjh6J78EbwoMNh3` `READY` untuk SHA itu. `/up` 200, `/laporan/kasir/shift/1/tutup` mengalihkan tamu ke login, Vercel tidak mencatat error runtime sejak deploy. **Demo live sambil login** (2026-11-26, pemilik repo login sendiri sebagai `owner@kasir.test`, Claude tidak mengetik kata sandi): di Pengguna, Andi (kasir demo) berbadge "Shift terbuka" dan menunya berisi "Tutup shift" + "Nonaktifkan (tutup shift dulu)", keduanya menaut ke `/laporan/kasir/shift/31/tutup`. Shift #31 dibuka 22 Nov 2026 19.39 dan ditinggal terbuka 4 hari (sisa uji atau pengunjung). Halaman tutup: modal Rp 200.000, penjualan tunai 0 transaksi, kas seharusnya Rp 200.000, "Transaksi terakhir 4 hari yang lalu (23 Nov 2026, 06.36)", tanpa QRIS menunggu/keranjang ditahan. Dengan izin pemilik: hitungan Rp 200.000 ("Kas pas, tidak ada selisih.") + alasan "Uji tutup shift oleh owner (Claude)" → toast "Shift Andi Pratama ditutup. Kas pas.", baris rekap "26 Nov 2026, 20.48 oleh Budi Santoso: Uji tutup shift oleh owner (Claude)" (2 transaksi, QRIS Rp 8.000, selisih pas), tidak ada baris "Berjalan" lagi, badge "Shift terbuka" hilang di Pengguna. Ekspor Excel kasir (20–26 Nov, dibaca di halaman tanpa disimpan) memuat kolom "Ditutup oleh"/"Alasan" berisi "Budi Santoso" dan alasan itu. Tanpa error konsol aplikasi, tanpa error runtime Vercel. Halaman kasir yang melihat pemberitahuan "ditutup oleh" tidak dicoba di live (butuh login kasir bersamaan).
- **Owner mereset 2FA & passkey akun lain (§14), browser lokal** (2026-11-26, commit `73a9ec5`). Pest: `ResetSignInFactorsTest` (15 tes) ditulis lebih dulu dan semuanya merah sebelum implementasi. Seluruh suite: SQLite 345 lulus + 5 dilewati, PostgreSQL 350 lulus, PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed`): `kasir@kasir.test` diberi 2FA terkonfirmasi + dua passkey lewat script (meniru HP hilang). Login kata sandi kasir berhenti di tantangan 2FA. Owner (`owner.localhost:8001`) → Pengguna: baris Andi berbadge "2FA" dan "2 passkey"; menu Andi berisi "Reset 2FA & passkey", menu Siti (tanpa 2FA/passkey) tidak. Props halaman hanya berisi `two_factor`/`has_two_factor_state`/`passkeys`; HTML halaman tidak memuat `two_factor_secret`, `recovery_codes`, `credential`, maupun nama passkey. Dialog menampilkan "2FA (aplikasi authenticator dan kode pemulihan)" dan "2 passkey" beserta catatan sesi; kata sandi owner yang salah ditolak "Kata sandi salah." dan dialog tetap terbuka; kata sandi benar → badge hilang, DB: kolom 2FA kosong, 0 passkey, log `Owner reset 2FA and passkeys {actor_id: 1, user_id: 3, had_two_factor: true, passkeys: 2}`. Login kata sandi kasir berikutnya langsung ke "Buka shift" tanpa tantangan 2FA. Tanpa error konsol dari aplikasi. Yang tidak dilihat di browser (hanya Pest): toast sukses (halaman sudah berganti saat dicek), penolakan akun sendiri/akun demo, akun nonaktif, sesama owner, dan setup 2FA yang belum dikonfirmasi. **Deploy** (push `e1f13dc`, tanpa migrasi): `tests` [45830210912](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45830210912) hijau di ketiga job (Pest PostgreSQL selesai 13:35:05 UTC) → `demo-db` [45830567227](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45830567227): migrate 13:35:12–13:35:49, deploy 13:35:51–13:37:40, `dpl_Aj3pYrku5Fo3CgupiaR8Bdu1PxxX` `READY` untuk SHA itu. `/up` 200, `/login` 200, `/pengguna` mengalihkan tamu ke login, `DELETE /pengguna/3/2fa` tanpa sesi/token CSRF dijawab 419. Vercel tidak mencatat error runtime sejak deploy. **Demo live sambil login** (2026-11-26, sesi owner demo yang sama dengan cek §13 di atas): props Pengguna berisi `two_factor: false`, `has_two_factor_state: false`, `passkeys: 0` untuk ketiga akun demo, HTML tanpa `two_factor_secret`/`recovery_codes`/`credential`; tidak ada badge 2FA/passkey dan menu Andi tidak berisi "Reset 2FA & passkey" (sesuai: akun demo tidak bisa punya 2FA). `DELETE /pengguna/2/2fa` dari sesi itu tanpa kata sandi → 422 "Kata sandi wajib diisi.", tidak ada yang berubah. Alur reset penuh belum dicoba di live: butuh akun uji yang 2FA-nya dipasang pemilik repo sendiri (Claude tidak mengetik kata sandi di produksi).
- **Log audit perubahan akun (§15), browser lokal** (2026-11-26, commit `3eb0186`). Pest: `AccountLogTest` (18 tes) ditulis lebih dulu dan semuanya merah sebelum implementasi; tes log di `ResetSignInFactorsTest` diganti dari `Log::notice` ke `account_events`. Seluruh suite: SQLite 363 lulus + 5 dilewati, PostgreSQL 368 lulus, Pint dan PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser** (`php -S` port 8001, DB `kasir_pos_test` + migrasi baru): owner membuka menu Siti → item "Riwayat" menaut ke `/pengguna/riwayat?user=2`; Siti dinonaktifkan lalu diaktifkan lagi lewat dialog → halaman Riwayat akun menampilkan "Diaktifkan lagi" (21.18) di atas "Dinonaktifkan" (21.17), keduanya "Oleh Budi Santoso", dengan keterangan "Dicatat sejak 26 Nov 2026"; filter `?type=deactivated` menyisakan satu baris. Yang hanya dibuktikan di Pest: rincian lama → baru ("Role: Kasir → Staf Gudang"), perubahan oleh pengguna sendiri (profil, kata sandi, PIN, 2FA, passkey), `demo_sign_in_released`, 403 untuk gudang/kasir, dan tidak adanya kata sandi/PIN/nama passkey di tabel maupun halaman. Belum di-deploy.
- **QRIS kedaluwarsa lewat polling (bug diperbaiki, 2026-11-26).** Ditemukan saat mencoba pesan "Kembalikan uang tunai" (§11.4) di lokal: QR split INV-20261126-0002 tetap "Menunggu pembayaran" lebih dari 3 menit setelah `expiresAt`. Midtrans menjawab `GET /v2/{order}/status` untuk transaksi kedaluwarsa dengan HTTP 200, `status_code` `407` ("Success, transaction is found"), `transaction_status` `expire`; `MidtransClient::send()` menganggap `status_code` selain 2xx sebagai gagal, jadi `QrisPayments::refresh()` tidak pernah membatalkan transaksi itu (yang tetap membatalkan: tombol "Batalkan QRIS", penutupan shift oleh owner, dan notifikasi webhook `expire` yang tidak memanggil `status()`). Perbaikan: `status()` menerima `407` sebagai jawaban bila body membawa `transaction_status`; error lain (mis. `404` "Transaction doesn't exist.") tetap dianggap gagal. Fake Midtrans di `QrisPaymentTest` kini memakai kode status asli Midtrans (sebelumnya semua status non-lunas dijawab `201`, sehingga bug ini tersembunyi), dengan tes regresi "cancels an expired QR by polling, although Midtrans answers 407" (merah sebelum perbaikan) dan tes bahwa `404` tetap "tidak ada kabar". Seluruh suite: SQLite 365 lulus + 5 dilewati, PostgreSQL 370 lulus. Dengan sandbox sungguhan: `MidtransClient::status()` untuk order kedaluwarsa kini mengembalikan `expire`, order yang tidak ada tetap error. **Browser lokal** (`php -S`, DB `kasir_pos_test`): QR split baru INV-20261126-0002 (Gula Pasir Rp 18.500, tunai Rp 10.000 + QRIS Rp 8.500, kedaluwarsa 21:50:10); pukul 21:51 dialog sendiri berganti ke "QRIS kedaluwarsa atau dibatalkan. Keranjang masih ada; pilih cara bayar lain atau buat QRIS baru." + "Kembalikan uang tunai Rp 10.000 ke pelanggan.", sale `cancelled`, header shift tetap "0 transaksi · kas Rp 200.000". Dua percobaan pertama ditolak 409 karena nomor INV hari yang sama sudah dipakai di sandbox (bukan bug).
- **Deploy log audit (§15) + perbaikan QRIS kedaluwarsa** (2026-11-26, commit `0f93900`). `tests` hijau → job migrasi `demo-db` menjalankan `2026_11_26_000002_create_account_events_table` di Neon → job deploy (`dpl_D8NHj9ETVoptp7Vr84m6nroHgSkv`, READY, commit yang sama) + health check lulus. `/up` 200; `/pengguna/riwayat` tanpa login dialihkan (302). Tanpa error runtime Vercel 30 menit setelah deploy. Belum dicek di live: halaman Riwayat akun dengan login owner, dan pembatalan QR kedaluwarsa lewat polling di demo live.
- **Ekspor PDF laporan (§16), browser lokal** (2026-11-27). Pest: `PdfExportTest` (32 tes); 30 di antaranya ditulis lebih dulu dan merah sebelum implementasi, 2 (pemecahan tabel, batas per sel) ditambahkan setelah pengukuran mengubah rancangannya; `ExportTest` (Excel) tidak diubah dan tetap hijau setelah tabel dipindah ke `ReportTables`. Seluruh suite: SQLite 397 lulus + 5 dilewati, PostgreSQL 402 lulus, Pint dan PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed` dengan data 30 hari, akun owner demo): keempat halaman laporan menampilkan "Ekspor Excel" + "Ekspor PDF" dengan filter halaman (mis. `/laporan/pdf/kasir?from=2026-11-21&to=2026-11-27`). Unduhan PDF: `200`, `application/pdf`, diawali `%PDF-`, nama file `laporan-penjualan-2026-10-29_2026-11-27.pdf` dst., juga penjualan bulanan. Isi PDF dari data demo dibaca ulang: penjualan 30 hari (2 halaman lanskap, judul kolom di halaman 2, Total Rp 52.416.385 / laba kotor Rp 5.489.414 / margin 11,7%, sama dengan kartu di halaman laporan), produk terlaris, nilai persediaan (Rp 3.855.237, per produk + per kategori, potret), rekap kasir & shift (3 halaman). Dua masalah tata letak ditemukan dan diperbaiki saat itu: judul kolom angka saling menimpa (judul kini boleh dibungkus) dan produk terlaris 12 kolom terlalu sempit di potret (kini lanskap). Penjualan harian 1 Jan 2024 – 27 Nov 2026 → "Ekspor PDF" kembali ke halaman yang sama dengan toast "Terlalu banyak baris untuk PDF (1062, maksimal 590). Persempit periode atau pakai Ekspor Excel." Tanpa error konsol dari aplikasi. **Deploy** (commit `cd57470`): `tests` hijau → migrasi `demo-db` (tidak ada migrasi baru) → deploy `dpl_2qKcetfNMhuL6vNe6NFwA77tsKw2` READY + health check lulus, jadi image Docker dengan dompdf ter-build. `/up` 200; `/laporan/pdf/penjualan` tanpa login dialihkan (302). Tanpa error runtime Vercel 30 menit setelah deploy. Belum dicek: mengunduh PDF di demo live dan waktu render di Vercel (butuh login owner, ditunda di bawah).
- **Pembaruan keamanan Symfony** (2026-11-27). `composer audit` melaporkan 7 advisory: `symfony/http-kernel` (CVE-2026-133743, `asset()`/`absolute_url()` lewat header forwarded), `symfony/routing` (CVE-2026-133746 dan -133754, `UrlGenerator` ke host lain), `symfony/mailer` (CVE-2026-133751 dan -133755, `require_tls` / STARTTLS), `symfony/serializer` (CVE-2026-133761 dan -133752; dipakai `web-auth/webauthn-lib` untuk passkey). Semua diperbaiki di 8.1.12. `composer update symfony/http-kernel symfony/mailer symfony/routing symfony/serializer -w` (Laravel tetap terkunci) hanya menaikkan versi patch: keempat paket itu + `symfony/http-foundation`, `symfony/mime`, `symfony/var-dumper` ke 8.1.12, dan `egulias/email-validator` 4.0.4 → 4.0.5; hanya `composer.lock` yang berubah. `composer audit` kini bersih. Seluruh suite: SQLite 397 lulus + 5 dilewati, PostgreSQL 402 lulus, Pint dan PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser lokal** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed`): login owner → dashboard, laporan penjualan tampil; `/kasir`, `/kasir/transaksi`, `/produk`, `/stok-opname`, `/stok-opname/baru`, `/pengguna`, `/pengguna/riwayat`, `/laporan/kasir` 200; ekspor Excel dan PDF terunduh; URL yang tidak ada 404. **Deploy** (commit `039e912`, tanpa migrasi): `tests` dan `demo-db` lulus → deployment `dpl_4LV5g41ECLAp6sXrf9aGn7GY9Kvj` READY untuk commit itu. Dari luar: `/up` 200, `/login` 200, `/laporan/pdf/penjualan` mengalihkan tamu ke login (302). Vercel tidak mencatat error runtime sejak deploy.
- **Kasbon / piutang pelanggan (§17), browser lokal** (2026-11-27). Pest: 51 tes di `tests/Feature/Customers`, `ReceivablesReportTest` dan `CustomerCreditRaceTest` ditulis lebih dulu dan semuanya merah sebelum implementasi; seluruh suite kini SQLite 450 lulus + 7 dilewati, PostgreSQL 457 lulus, Pint dan PHPStan bersih. Kedua tes `CustomerCreditRaceTest` merah bila `lockForUpdate()` di `CustomerLedger::lock()` dibuang sementara. `migrate:fresh --seed` (`APP_ENV=local`, `kasir_pos_test`): `stock:rebuild` dan `receivables:rebuild` cocok. **Browser** (`php -S` port 8001): kasir membuka shift Rp 200.000 → Beras Rojolele 5kg Rp 78.000 → F9 → tab "Kasbon" menampilkan pelanggan beserta utangnya → "Pelanggan baru" Pak Joko (HP `0812-9999-0001`, tersimpan `081299990001`, batas Rp 500.000) langsung terpilih → uang muka Rp 20.000, "Jadi kasbon Rp 58.000" → dialog sukses "Uang muka tunai Rp 20.000 / Kasbon Rp 58.000 / Sisa utang Rp 58.000", header "1 transaksi · kas Rp 220.000". Struk: "Uang muka tunai 20.000 / Kasbon 58.000 / Pelanggan Pak Joko / Sisa utang 58.000" + baris tanda tangan. Pelunasan Bu Sari Rp 100.000 (utang Rp 584.500, 5 kasbon): pratinjau alokasi FIFO = kasbon tertua lunas + Rp 59.500 ke berikutnya, dialog "PAY-20261127-0001 · sisa utang Rp 484.500", bukti pelunasan dengan alokasi per INV ("LUNAS" / "sisa 65.100"), kartu piutang menampilkan dua baris pelunasan. Retur kasbon Pak Joko (1 karung, PIN owner demo): pratinjau dan hasil "Mengurangi utang Rp 58.000 · tunai Rp 20.000", sisa utang transaksi Rp 0. Tutup shift: modal 200.000 + penjualan tunai 20.000 + "Pelunasan kasbon (1)" 100.000 − retur tunai 20.000 = kas seharusnya Rp 300.000 (shift dibiarkan terbuka). Owner (`owner.localhost:8001`): Laporan → Piutang Rp 1.225.100, 5 pelanggan, Pak Darto Rp 259.700 di 31–60 hari (lewat 30 hari 21%); rekap shift dengan kolom Kasbon Rp 58.000 / Pelunasan Rp 100.000 / kas seharusnya Rp 300.000; laporan penjualan Tunai + QRIS + Kasbon = Penjualan (1.724.300 + 761.500 + 322.500 = 2.808.300); `fetch` keempat unduhan (Excel/PDF piutang, Excel penjualan, PDF rekap kasir) 200 dengan tipe dan nama file benar; hapus utang Bu Dewi Rp 42.000 dengan alasan → kartu "Dihapuskan" + alasan, utang Rp 0; kartu dashboard "Piutang (kasbon)". Dua masalah ditemukan dan diperbaiki saat itu: halaman Pelanggan menghitung umur dari selisih jam (Bu Sari "5 hari", Pak Darto "39 hari") sementara halaman Pelunasan dan laporan memakai hari kalender (6 dan 40), kini satu fungsi `Receivables::ageInDays()` di server + tes regresi; lima kartu dashboard terlalu sempit di lebar ±800 px, kini `lg:3 / xl:5` kolom. Konsol aplikasi bersih, kecuali beberapa `ERR_NETWORK_CHANGED` sesaat dari jaringan mesin (request berikutnya semua 200). Yang hanya dibuktikan di Pest: ubah data/batas dan nonaktifkan pelanggan oleh owner, 403 untuk kasir/gudang, penolakan kasbon yang melebihi batas, filter "Lewat 30 hari", dan isi PDF piutang (HTML untuk dompdf). **Deploy** (push `f955fe2..c17cfca`, membawa migrasi `2026_11_27_000001_create_customer_credit_tables`): `tests` [45933697454](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45933697454) hijau → `demo-db` [45934031861](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45934031861): migrasi selesai 03:25:45 UTC (job migrate 03:25:00–03:25:47), job deploy baru mulai 03:25:50 dan selesai 03:27:36 dengan health check `/up` 200; `dpl_CooMj3QLj3hQXyQrjwtER4zmUBex` READY untuk SHA `c17cfca`. Dari luar: `/up` 200, `/login` 200; `/pelanggan`, `/kasir/pelunasan`, `/laporan/piutang`, `/laporan/pdf/piutang` mengalihkan tamu (302). Vercel tidak mencatat error runtime sejak 03:25 UTC. Belum dicek sambil login di live (lihat di bawah).
- **Deploy catatan kasbon** (2026-11-27, commit `d5013cc`, push `c17cfca..d5013cc`, tanpa migrasi; hasil dilaporkan pemilik repo): `tests` [45934449188](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45934449188) dan `demo-db` [45934884027](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45934884027) sukses, `/up` 200, tanpa error runtime Vercel sejak 03:25 UTC 27 Nov 2026.

- **Pelunasan kasbon lewat QRIS (§18), browser lokal** (2026-11-27). Pest: `QrisCustomerPaymentTest` (25 tes) ditulis lebih dulu dan semuanya merah sebelum implementasi; `QrisCustomerPaymentRaceTest` (PostgreSQL) lulus dan merah bila `CustomerLedger::lock()` di `StartQrisCustomerPayment` diganti pembacaan biasa (dicek sementara: proses kedua tidak lagi menunggu). Seluruh suite: SQLite 476 lulus + 8 dilewati, PostgreSQL 484 lulus, Pint dan PHPStan bersih. `migrate:fresh --seed` (`APP_ENV=local`, `kasir_pos_test`): 4 pelunasan QRIS + 3 tunai, `stock:rebuild` dan `receivables:rebuild` cocok. **Browser** (`php -S` port 8001, Midtrans Sandbox): kasir membuka shift Rp 200.000 → Pelunasan → Bu Dewi (utang Rp 88.000, 3 kasbon) → Rp 50.000 → "QRIS": QR Midtrans `PAY-20261127-0001` Rp 50.000 "Pelunasan kasbon Bu Dewi" (tanpa bentrok 409) → dibayar lewat simulator QRIS sandbox (GoPay, "PAID") → polling menampilkan "Pelunasan diterima · PAY-20261127-0001 · Bu Dewi · QRIS, sisa utang Rp 38.000". DB: `completed`, referensi Midtrans tersimpan, satu baris ledger −50.000 pada kasbon tertua (Rp 57.400 → Rp 7.400), 0 baris `payment_notifications` (jadi lunas lewat polling; webhook tidak bisa mencapai server lokal). Bukti pelunasan: "Bayar QRIS", "Ref." Midtrans, alokasi INV dan sisa utang. QR kedua untuk Mbak Rina (`PAY-20261127-0002`, Rp 22.200): kartu piutang menampilkan pemberitahuan QR menunggu dan label "QRIS · / Tunai ·" pada pelunasan demo; selama menunggu, `POST /kasir/pelunasan` (tunai) dan `PUT /kasir/shift` (tutup shift) dari sesi kasir dijawab 422 dengan pesan §18; "Batalkan QRIS" → toast "QRIS PAY-20261127-0002 dibatalkan.", pelanggan tetap terpilih, utang tetap. Pelunasan tunai Rp 10.000 → Tutup shift: kas seharusnya Rp 210.000, "Pelunasan QRIS (1) Rp 50.000 — tidak masuk laci", ditutup Rp 210.000 "Kas pas". Owner (`owner.localhost:8001`): Rekap kasir & shift kolom "Pelunasan tunai" Rp 10.000 / "Pelunasan QRIS" Rp 50.000 / kas seharusnya Rp 210.000; `fetch` Excel dan PDF rekap 200 dengan tipe dan nama file benar, baris ekspor per shift berisi angka yang sama. Dialog QRIS penjualan setelah refaktor komponen: campuran Rp 4.000 (tunai Rp 2.000) menampilkan `INV-20261127-0001` "Total Rp 4.000 · tunai Rp 2.000 sudah diterima", batal → sale `cancelled`. Dua masalah tampilan ditemukan dan diperbaiki saat itu: label tombol "Terima tunai Rp …" terpotong di kolom sempit (kini bertumpuk sampai `xl`) dan "Memproses…" muncul di tombol tunai saat QRIS ditekan; label "Cara bayar" di bukti 58 mm terbungkus (kini "Bayar"). Tanpa error konsol. Yang hanya dibuktikan di Pest: webhook pelunasan (signature, idempoten, `amount_mismatch`, `over_balance`), QR kedaluwarsa (407) dan pembersihan QR kedaluwarsa sebelum menolak, penolakan write-off/retur kasbon selama QR menunggu, dan penutupan shift oleh owner dengan QR pelunasan menunggu. **Deploy** (push `d5013cc..9980684`, membawa migrasi `2026_11_27_000002_add_qris_to_customer_payments`): `tests` [45984273639](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45984273639) hijau di ketiga job (Pest PostgreSQL selesai 10:46:54 UTC) → `demo-db` [45984988684](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45984988684): migrasi selesai 10:47:34 (job migrate 10:47:00–10:47:37), job deploy baru mulai 10:47:39 dan selesai 10:49:28; `dpl_3N8zHm8vqmCWNmdsVtoGMrqeiDsf` READY untuk SHA `9980684`. Dari luar: `/up` 200, `/login` 200, `/kasir/pelunasan` mengalihkan tamu (302), `POST /webhooks/midtrans` tanpa signature 403 (tercatat satu baris `invalid_signature` di DB demo). Vercel tidak mencatat error runtime sejak 10:47 UTC. Belum dicek sambil login di live (lihat di bawah).

- **Demo live sambil login: kasbon, pelunasan QRIS, split payment, PDF** (2026-11-27, deploy `657da8c`; pemilik repo login sendiri sebagai `kasir@kasir.test` lalu `owner@kasir.test`, Claude tidak mengetik kata sandi). **Kasir:** shift yang ditinggal terbuka sejak 26 Nov 2026 20.58 ditutup (kas seharusnya Rp 250.000, dihitung Rp 250.000, "Kas pas"); shift baru 18.26 modal Rp 200.000. Kasbon: pelanggan "Uji QRIS (Claude)" ditambah dari dialog bayar (batas bawaan Rp 500.000) → `INV-20261127-0001` Gula Pasir Rp 18.500, dialog "Kasbon Rp 18.500 atas nama Uji QRIS (Claude) · Sisa utang Rp 18.500". Pelunasan QRIS dengan tab terbuka: percobaan pertama ditolak Midtrans 409 karena `PAY-20261127-0001` sudah dipakai uji lokal di akun sandbox yang sama (pesan Midtrans tampil, baris `cancelled`); percobaan berikutnya `PAY-20261127-0002` Rp 10.000 dibayar lewat simulator (GoPay, PAID) → "Pelunasan diterima · QRIS, sisa utang Rp 8.500". Pelunasan QRIS lewat webhook: `PAY-20261127-0003` Rp 8.500 dibuat, halaman Pelunasan ditinggalkan sebelum dibayar (tidak ada polling), dibayar di simulator, lalu kartu piutang (yang tidak menghubungi Midtrans) menampilkan pelunasan `PAY-20261127-0003` "QRIS · untuk INV-20261127-0001" dan utang Rp 0. Kesimpulan "lewat webhook" berasal dari urutan ini; log runtime Vercel tidak bisa dicari per path request, jadi request webhook-nya tidak terlihat langsung. Split payment: Teh Botol Rp 5.000, tunai Rp 2.000 → QR `INV-20261127-0002` Rp 3.000 "Total Rp 5.000 · tunai Rp 2.000 sudah diterima" → dibayar dengan tab terbuka → dialog "Transaksi berhasil" Tunai Rp 2.000 / QRIS Rp 3.000 / "Pembayaran QRIS diterima" (menutup item §11 yang tertunda). Tutup shift: kas seharusnya Rp 202.000, "Pelunasan QRIS (2) Rp 18.500 — tidak masuk laci", ditutup Rp 202.000 "Kas pas". **Owner:** Rekap kasir & shift 26–27 Nov: shift 18.26 Tunai Rp 2.000, QRIS Rp 3.000, Kasbon Rp 18.500, Pelunasan tunai –, Pelunasan QRIS Rp 18.500, kas seharusnya Rp 202.000, pas; shift 26 Nov Rp 250.000 pas. Laporan piutang: Rp 0, 0 pelanggan (kasbon uji sudah lunas). `fetch` di halaman (tanpa disimpan): Excel + PDF rekap kasir, Excel + PDF piutang, PDF penjualan 30 hari, PDF produk terlaris, PDF nilai persediaan, semuanya 200 dengan tipe dan nama file benar, render 0,4–0,6 detik di Vercel. Penjualan harian 1 Jan 2024 – 27 Nov 2026 → "Ekspor PDF" → toast "Terlalu banyak baris untuk PDF (1062, maksimal 541). Persempit periode atau pakai Ekspor Excel." (menutup item §16 yang tertunda). Tanpa error konsol, tanpa error runtime Vercel. Data uji (pelanggan "Uji QRIS (Claude)", dua shift, dua transaksi) dibiarkan di DB demo.

- **Dua perbaikan dari uji demo live 27 Nov 2026** (2026-11-27, test-first; kedua tes baru merah sebelum perbaikan). (1) Setelah pelunasan **tunai**, `CustomerPaymentController::store` kini kembali ke `/kasir/pelunasan?pelanggan={id}` seperti QRIS, jadi pelanggan tetap terpilih; tes `CustomerHttpTest` "keeps the customer selected after a cash payment…" juga membuktikan dialog sukses (`lastPayment`, dari flash) muncul sekali dan hilang saat dimuat ulang. (2) `Shift::cashSalesCount()` kini menghitung sale `completed` dengan `Sql::cashPart() > 0` (ekspresi yang sama dengan `cashSalesTotal()`), bukan daftar cara bayar, jadi kasbon tanpa uang muka tidak lagi ikut "Penjualan tunai (N transaksi)". `PaymentMethod::cashMethods()` tidak punya pemakai lain dan dihapus. Satu-satunya pemakai `cashSalesCount()` adalah `PosController::shiftSummary()` (`salesCount`), yang dibaca tiga tempat: halaman Tutup shift kasir, halaman Tutup shift owner (§13), dan **header layar kasir** ("N transaksi · kas …"). Header ikut berubah dengan sengaja: angkanya kini juga hanya transaksi yang memasukkan tunai (QRIS murni sejak awal tidak dihitung di sana). `ShiftRecap` tidak memakai fungsi ini. Seluruh suite: SQLite 478 lulus + 8 dilewati; tes `tests/Feature/Customers` di PostgreSQL 75 lulus.
    - **Dicek di browser lokal** (2026-11-28; `php -S` port 8003 `laravel-demo-8003`, `kasir_pos_test` baru di-`migrate:fresh --seed` dengan `APP_ENV=local`; kasir di `kasir.localhost:8003`, owner di `owner.localhost:8003`, karena cookie `localhost` tanpa subdomain dipakai bersama sesi lain di port 8001 dan login di sana langsung terpental ke `/login`). Tidak ada error di konsol browser. Tidak ada yang perlu diperbaiki.
    - (1) Kasir buka shift modal Rp 200.000, Pelunasan → Mbak Rina (utang Rp 170.000), tunai Rp 50.000 → `PAY-20261128-0001`: URL menjadi `/kasir/pelunasan?pelanggan=3`, dialog "Pelunasan diterima" muncul sekali (Rp 50.000, sisa utang Rp 120.000), Mbak Rina tetap terpilih di belakangnya. Muat ulang: URL dan pilihan tetap, dialog tidak muncul lagi. Pembanding QRIS (Midtrans sandbox, dibayar lewat simulator QRIS sandbox) Rp 20.000 → `PAY-20261128-0002`: lunas lewat polling, dialog dan URL sama dengan tunai, muat ulang juga tidak memunculkan dialog lagi. Kedua alur kini berperilaku sama.
    - (2) Di shift yang sama: (a) Aqua Botol 1 pcs tunai pas → `INV-20261128-0001` Rp 4.000; (b) Beras 1 karung kasbon Bu Dewi tanpa uang muka → `INV-20261128-0002` Rp 78.000; (c) Gula 1 pcs kasbon Mbak Rina uang muka tunai Rp 10.000 → `INV-20261128-0003` Rp 18.500 (kasbon Rp 8.500). Header layar kasir: "1 transaksi · kas Rp 254.000" setelah (a), tetap sama setelah (b), "2 transaksi · kas Rp 264.000" setelah (c). Tutup shift kasir dan Tutup shift owner (`/laporan/kasir/shift/32/tutup`) sama-sama menampilkan "Penjualan tunai (2 transaksi) Rp 14.000" (= Rp 4.000 + uang muka Rp 10.000), "Pelunasan kasbon tunai (1) Rp 50.000", kas seharusnya Rp 264.000, dan "Pelunasan QRIS (1) Rp 20.000 — tidak masuk laci". Rekap kasir & shift menulis Trx 3 untuk shift itu: `ShiftRecap` menghitung semua transaksi dan memang tidak memakai `cashSalesCount()`. Shift **tidak ditutup** (sengaja dibiarkan berjalan). `stock:rebuild` dan `receivables:rebuild` cocok setelahnya.
    - Teramati sampingan, tidak diselidiki: dua kali, klik pertama pada produk setelah dialog "Transaksi berhasil" ditutup dengan Esc tidak menambah barang ke keranjang; klik kedua berhasil. Belum jelas apakah ini perilaku aplikasi atau cara alat browser mengirim klik; perlu dicoba dengan mouse sungguhan.
    - **Belum dicek di demo live**: Claude tidak mengetik kata sandi di produksi, jadi langkah login di live menunggu pemilik repo.

- **Batch & tanggal kedaluwarsa (§19), browser lokal** (2026-11-27). Pest: tes baru di §19.7, ditulis lebih dulu dan merah kecuali yang dicatat di sana. Seluruh suite: SQLite 525 lulus + 10 dilewati, PostgreSQL 535 lulus, Pint, PHPStan, `vp check` dan `tsc` bersih. Kedua tes `BatchFefoRaceTest` merah saat `lockForUpdate()` di `StockLedger::lock()` dibuang sementara, hijau lagi setelah dikembalikan. `migrate:fresh --seed` (`APP_ENV=local`, `kasir_pos_test`): `stock:rebuild` (termasuk per batch) dan `receivables:rebuild` cocok. **Browser** (`php -S` port 8003, konfigurasi baru `laravel-demo-8003` di `.claude/launch.json` karena port 8001 dipakai sesi lain). Gudang: dashboard menampilkan kartu "Hampir kedaluwarsa" (6 batch roti kedaluwarsa dari data demo, 2 ≤ 30 hari). GR Susu UHT 2 dus dipecah 1 dus exp 3 Des 2026 + 1 dus exp 20 Jan 2027 kode `UJI-CL` → `GR-20261127-0002`; detail GR menampilkan "Kedaluwarsa 3 Des 2026: 24 pcs / 20 Jan 2027 · UJI-CL: 24 pcs". Kartu stok Susu: bagian Batch (status, sisa, tautan Buang) dan kolom Batch; penjualan demo `INV-20261125-0004` terlihat terpecah ke dua batch. Kasir (`kasir.localhost`, cookie terpisah): katalog menampilkan Roti 10 pcs (stok 40, 30 kedaluwarsa tidak dihitung); jual 2 pcs Susu tunai → `INV-20261127-0001`, movement dari batch paling awal `2 Des 2026 · PROMO`; retur 1 pcs dengan PIN owner demo → `RET-20261127-0001` kembali ke batch PROMO (sisa 23). Gudang: "Buang yang kedaluwarsa" mengisi 6 batch, satu dihapus dari daftar, lalu `DSP-20261127-0002` (5 batch roti, Rp 353.705). Opname Roti per batch (27 Nov dihitung 9 dari 10, batch ditemukan 30 Nov 2 pcs, batch `RAK-B` tidak dihitung) → `SO-20261127-0002` diposting, detail per batch −1 / +2, `RAK-B` tetap 3. Laporan Kedaluwarsa (gudang, tanpa tab laporan owner): ringkasan per status dan 9 batch; `fetch` Excel dan PDF (`?status=expired`) 200 dengan tipe dan nama file `laporan-kedaluwarsa-2026-11-27` benar. `stock:rebuild` cocok setelah semua aksi ini. Satu masalah ditemukan dan diperbaiki saat itu: di kolom dashboard yang sempit, badge status memotong nama produk di kartu kedaluwarsa (kini status ditandai warna teks tanggal). Satu error 500 di konsol berasal dari URL yang salah ketik (`/penerimaan/create`; halaman buatnya `/penerimaan/baru`): parameter `create` jatuh ke route detail dan PostgreSQL menolak id bukan angka. Itu perilaku lama semua route resource, bukan dari fitur ini, dan tidak diubah. Yang hanya dibuktikan di Pest: QRIS yang lunas lewat tengah malam, penolakan jual barang kedaluwarsa lewat HTTP, penerimaan dari PO dengan tanggal, sakelar flag di form produk, halaman laporan untuk owner, 403 untuk kasir. **Deploy** (push `d01ec2c..d90ec48`, termasuk perbaikan pelunasan/hitungan tunai `63c5b6b`, membawa migrasi `2026_11_27_000003_create_stock_batches_tables`): `tests` [46010195902](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46010195902) hijau di ketiga job (Pest PostgreSQL selesai 13:52:54 UTC) → `demo-db` [46010932823](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46010932823): migrasi selesai 13:53:42 (job migrate 13:52:59–13:53:45), job deploy baru mulai 13:53:48 dan selesai 13:55:40 dengan health check `GET /up -> 200`; `dpl_EbmwZsHdD6ubnLhuyfoWghJGHER5` READY untuk SHA `d90ec48`. Dari luar: `/up` 200, `/login` 200; `/laporan/kedaluwarsa`, `/stok-dibuang`, `/laporan/pdf/kedaluwarsa` mengalihkan tamu (302). Vercel tidak mencatat error runtime sejak 13:53 UTC. Belum dicek sambil login di live (lihat di bawah).
- **"Stok menipis" memakai stok jual** (2026-11-28, test-first; keempat tes `tests/Feature/Inventory/LowStockSellableTest.php` merah lebih dulu). Sebelumnya scope `Product::lowStock()` membandingkan `stock_on_hand` (termasuk batch kedaluwarsa yang belum dibuang) dengan `min_stock`, jadi produk berflag dengan banyak stok kedaluwarsa tidak muncul walau stok jualnya tipis. Kini `lowStock()` dan scope baru `withSellableStock()` (kolom `sellable_stock`) memakai satu subquery: untuk produk berflag, jumlah batch yang belum kedaluwarsa (`StockBatch::sellableOn()`, sama dengan layar kasir dan `StockLedger::sellable()`; batch yang kedaluwarsa hari ini masih dihitung); untuk produk lain, `stock_on_hand`. Ikut berubah karena memakai definisi yang sama: kartu dashboard (angka dan urutan dari stok jual), badge sidebar, filter "Stok menipis" di daftar produk (yang kini juga menampilkan "bisa dijual N" di bawah stok fisik bila berbeda, dan penanda merah memakai stok jual), dan draf PO "dari stok minimum" (kekurangan = 2 × minimum − stok jual, karena barang kedaluwarsa akan dibuang). Stok fisik di daftar produk, kartu stok, nilai persediaan, dan form PO tetap `stock_on_hand`. `composer test` (Pint, PHPStan, SQLite 529 lulus + 10 dilewati), PostgreSQL 539 lulus, `vp check` dan `tsc` bersih. **Browser lokal** (`kasir_pos_test` di-seed ulang, owner di `owner.localhost:8003`): Roti Tawar demo 53 pcs fisik, "bisa dijual 9"; dengan minimum bawaan kartu tetap "Semua produk aktif di atas stok minimum". Setelah stok minimum Roti dinaikkan ke 15 lewat form ubah produk (hanya di DB uji), kartu menampilkan "1 produk … Roti Tawar Sari Roti 9 pcs, min. 15", badge sidebar Produk 1, filter Stok menipis berisi Roti ("53 pcs / bisa dijual 9 / min. 15"), dan draf PO dari stok minimum berisi Roti 21 pcs (2 × 15 − 9; draf tidak disimpan). Tidak ada error di konsol. **Deploy** (push `b374119..d1b9274`): `tests` [46087245288](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46087245288) hijau → `demo-db` [46087617970](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46087617970): migrasi 00:23:29–00:24:00 UTC, deploy Vercel 00:24:02–00:25:35 UTC, keduanya sukses. **Demo live** (2026-11-28, pemilik repo login sebagai owner di panel browser; Claude hanya membaca halaman, tidak mengetik kata sandi dan tidak mengubah data): 12 produk, tidak satu pun melacak kedaluwarsa. Dashboard "Stok menipis: Semua produk aktif di atas stok minimum" (sama seperti sebelum deploy), daftar produk tanpa baris "bisa dijual" dan tanpa penanda merah, `/produk?status=low` kosong, draf PO `?dari=stok-minimum` tanpa baris. Props Inertia `/produk` sudah memuat `sellableStock` (mis. Aqua 97/97, Beras 7/7), jadi kode baru yang berjalan, dan untuk produk tanpa flag angkanya sama dengan stok fisik. Tidak ada error di konsol. **Setelah `demo-db` mode `reset`** (dengan izin pemilik repo, 2026-11-28; `cc0cbe4` lebih dulu ter-deploy otomatis lewat [46088556179](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46088556179); reset [46088801612](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/46088801612) 00:36:40–00:39:41 UTC, deploy dilewati sesuai rancangan; isi lama DB live, termasuk sesi login, diganti data seeder): pemilik repo login ulang sebagai owner. Ada 14 produk; tiga berflag. Roti Tawar 53 pcs fisik, stok jual 9 (sisanya batch kedaluwarsa), minimum 5. Susu UHT 88/88 dan Teh Botol 68/68 tidak punya batch kedaluwarsa bersisa. Daftar produk menampilkan Roti "53 pcs / bisa dijual 9". Karena 9 > 5, kartu "Stok menipis" tetap "Semua produk aktif di atas stok minimum" dan `/produk?status=low` kosong, dan itu benar. Kasus "menipis karena kedaluwarsa" sendiri tidak dipicu di live, karena itu berarti mengubah data demo publik (misalnya stok minimum Roti); kasus itu terbukti di Pest dan browser lokal di atas. Tidak ada error dari aplikasi di konsol (dua 409 berasal dari `fetch` Claude tanpa versi Inertia). Reset yang sama mengisi data contoh lain di live: dashboard menampilkan piutang Rp 891.400 dari 4 pelanggan (Rp 225.500 lewat 30 hari), menu Pelanggan berisi 5 pelanggan demo (Pak RT Yanto, Pak Darto, Bu Sari, Mbak Rina, Bu Dewi), "PO belum selesai" 3 pesanan (1 terlambat, sisa Rp 1.748.800), dan penjualan 30 hari Rp 46.667.260 dari 470 transaksi.
- **Batch & kedaluwarsa (§19) di demo live, sisi gudang/owner** (2026-11-28, setelah `demo-db` mode `reset`, dengan izin pemilik repo; pemilik login sebagai owner di panel browser, Claude tidak mengetik kata sandi). Semua dokumen uji diberi catatan "Uji Claude". (1) GR Susu UHT 1 dus (Rp 119.958) dengan kedaluwarsa 31 Jan 2027, kode `UJI-LIVE` → `GR-20261128-0002`; detailnya menulis "Kedaluwarsa 31 Jan 2027 · UJI-LIVE: 24 pcs". (2) Buang batch Roti 24 Okt 2026, 15 pcs → `DSP-20261128-0002`, Rp 196.335 (15 × HPP Rp 13.089); stok Roti 53 → 38. (3) Opname per batch: Roti batch 7 Nov 2026 dihitung 3 dari 4, batch lain tidak dihitung → `SO-20261128-0002`, disimpan sebagai draft lalu diposting (−1, −Rp 13.089). (4) Telur Ayam Negeri: "Lacak tanggal kedaluwarsa" dinyalakan di form ubah produk (peringatan "Stok sekarang (66 butir) dicatat sebagai batch tanpa tanggal" tampil). Kartu stok mencatat dua penyesuaian "Mulai lacak kedaluwarsa" (keluar 66, masuk 66 ke batch tanpa tanggal); stok 66 dan HPP Rp 1.653,3 tidak berubah. (5) Laporan Kedaluwarsa (owner) cocok dengan keempat aksi itu: kedaluwarsa 7 batch / 28 unit (Rp 366.492), ≤ 30 hari 2 batch / 33 unit, tanpa tanggal 1 batch (Telur), semua batch Rp 1.407.059 dari 15 batch. `fetch` ekspor Excel (juga `?status=expired`) dan PDF: 200, tipe `…spreadsheetml.sheet` / `application/pdf`, nama file `laporan-kedaluwarsa-2026-11-28`, isi diawali `PK` / `%PDF`. Tidak ada error dari aplikasi di konsol (dua 409 masih dari `fetch` Claude sebelumnya). Data uji ini tetap ada di live sampai `demo-db` mode `reset` berikutnya.

### Belum terverifikasi

- **Cek demo live yang ditunda (butuh pemilik repo, dicatat 2026-11-26).** Claude tidak mengetik kata sandi di produksi, jadi langkah ini menunggu pemilik repo:
    - Reset 2FA & passkey (§14) alur penuh: owner membuat akun uji (kata sandi awal diketik pemilik), pemilik login sebagai akun itu, mengganti kata sandi, dan memasang 2FA dengan authenticator di HP; lalu owner mereset (kata sandi owner di dialog diketik pemilik). Yang sudah terbukti di live hanya props, menu, dan penolakan 422 tanpa kata sandi.
    - Kelola pengguna (§12) di live: tambah, reset kata sandi, dan nonaktifkan akun uji (kata sandi diketik pemilik).
- **Batch & kedaluwarsa (§19) di demo live, sisi kasir** (dicatat 2026-11-28). Sisi gudang/owner sudah dicek di live (entri di atas). Yang belum dicoba di live: menjual produk berflag dari layar kasir (FEFO, katalog yang hanya menghitung stok jual), retur ke batch asalnya, dan penolakan jual barang kedaluwarsa. Ini butuh login kasir di live dan shift kasir terbuka. `stock:rebuild` tidak bisa dijalankan Claude terhadap DB live (tidak ada akses ke `DEMO_DB_URL`).
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
- **Isi dari stok minimum**: setiap produk aktif dengan `stock_on_hand ≤ min_stock` (definisi yang sama dengan kartu stok menipis; sejak 2026-11-28 keduanya memakai stok jual, lihat §8) menjadi satu baris, urut nama; satuan = satuan GR terakhir produk itu (atau satuan dasar), qty = cukup untuk mencapai **2 × stok minimum**, dibulatkan ke atas dalam satuan itu. Pengguna tinggal menghapus baris yang bukan untuk supplier ini. Kartu stok minimum di dashboard mendapat tombol "Buat PO".

### 10.6 Dampak ke laporan

- Laporan penjualan, laba kotor, produk terlaris, nilai persediaan, dan rekap shift **tidak berubah**: semuanya membaca `sales` dan `stock_movements`, sedangkan PO tidak membuat movement.
- Dashboard owner & gudang mendapat kartu **"PO belum selesai"**: jumlah PO `ordered`/`partial`, nilai sisa pesanan (Σ sisa qty × harga PO), dan berapa yang sudah lewat `expected_at`.
- Di luar cakupan ini: ekspor Excel PO, cetak/kirim PO ke supplier, harga per supplier, utang dagang.

### 10.7 Data demo & deploy

- `DemoSalesSeeder`: sebagian restok dibuat lewat PO (dipesan lalu diterima penuh), dan di hari-hari terakhir ada satu PO diterima sebagian, satu PO dipesan yang belum datang (satu di antaranya terlambat), serta satu draft. Semuanya lewat Action yang sama.
- Migrasinya hanya menambah tabel dan kolom nullable, jadi workflow `demo-db` (migrate) aman untuk DB demo yang sudah ada. DB demo live **baru berisi contoh PO setelah `demo-db` mode `reset`**; reset itu dijalankan 2026-11-28 (lihat §8), dan sejak itu live punya 3 PO contoh yang belum selesai.

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
    - **Akun yang shift-nya masih terbuka** (kasir maupun owner yang membuka shift) tidak bisa dinonaktifkan, dan tidak bisa diubah ke role tanpa akses kasir (gudang): "Tutup shift dulu." Shift harus ditutup dengan hitungan laci, dan transaksi QRIS yang menunggu pembayaran tergantung pada shift itu. Sejak §13, owner bisa menutup shift itu sendiri (menu aksi "Tutup shift" dan badge "Shift terbuka" di Pengguna mengarah ke halaman tutup shift owner), lalu menonaktifkan atau mengubah role akunnya; kasirnya tidak perlu login lagi.
- **Hapus akun sendiri dihapus** (route, tombol, dan request-nya), digantikan oleh penonaktifan oleh owner.

### 12.4 Demo live

Akun demo dibuka untuk umum dengan kata sandi yang tertulis di README, jadi pengunjung bisa saja menonaktifkan owner demo atau mengganti kata sandinya. Konfigurasi baru `pos.protect_demo_accounts` (env `POS_PROTECT_DEMO_ACCOUNTS`, default `false`; diisi `true` di blok `ENV` `Dockerfile`, bukan lewat pengaturan Vercel): ketiga akun dari `DemoUserSeeder` tidak bisa dinonaktifkan, diubah role/emailnya, direset kata sandi/PIN-nya, dan juga tidak bisa mengganti kata sandi, email, maupun PIN-nya sendiri di Pengaturan. Akun baru yang dibuat pengunjung bisa dikelola penuh. Lokal dan tes memakai default `false`.

Celah yang tersisa setelah §12 (sudah ada sebelumnya): pengunjung masih bisa mengaktifkan 2FA atau mendaftarkan passkey di akun demo (Pengaturan → Keamanan). 2FA yang diaktifkan pengunjung mengunci akun demo itu bagi orang lain, dan hanya bisa dipulihkan dengan `demo-db` mode `reset` atau mengosongkan kolom 2FA di DB. Ditutup di §12.4.1.

#### 12.4.1 2FA dan passkey akun demo (2026-11-26)

Aturan yang sama dengan kata sandi/email/PIN: **cara akun demo yang dilindungi masuk tidak bisa diubah siapa pun**. Tanpa skema baru, tanpa migrasi.

- **Blokir di middleware `ProtectDemoAccountSignIn`** (grup `web`, setelah `EnsureUserIsActive`). Route 2FA (Fortify) dan passkey (Laravel Passkeys) sama-sama memakai middleware `web` (`fortify.middleware`, `passkeys.middleware`), jadi satu middleware menjangkau semuanya tanpa mengubah vendor. Daftar nama route yang ditolak bila `DemoAccounts::isProtected($request->user())`:
  `two-factor.enable`, `two-factor.confirm`, `two-factor.regenerate-recovery-codes`, `two-factor.disable`, `passkey.registration-options`, `passkey.store`, `passkey.destroy`.
  Jawaban: request Inertia/biasa → `ValidationException` dengan `DemoAccounts::MESSAGE` (redirect back + error, seperti `SecurityController::update`); request JSON non-Inertia (`passkey.registration-options` dan `passkey.store` dipanggil `fetch` dari `usePasskeyRegister`) → **403** `{ message }`. Middleware berjalan sebelum `password.confirm` milik route itu, jadi pengunjung tidak diminta konfirmasi kata sandi dulu hanya untuk ditolak.
  Alternatif yang ditimbang: mengganti binding action Fortify (`EnableTwoFactorAuthentication`, dll.) di container. Ditolak karena Laravel Passkeys tidak punya titik serupa untuk registrasi, dan hasilnya dua mekanisme untuk satu aturan; event Fortify terjadi setelah perubahan tersimpan.
- **`two-factor.disable` dan `passkey.destroy` ikut ditolak.** Pemulihan tidak butuh keduanya (lihat poin berikut), dan satu aturan "tidak ada yang berubah" lebih mudah diuji dan dijelaskan daripada "boleh menghapus, tidak boleh menambah". Route baca (`two-factor.qr-code`, `two-factor.secret-key`, `two-factor.recovery-codes`) tidak diblokir: tanpa `enable` isinya kosong.
- **Akun demo di DB live yang sudah terlanjur punya 2FA/passkey pulih sendiri saat login kata sandi berikutnya.** Di `Fortify::authenticateUsing`, setelah kata sandi cocok dan akun dilindungi: kolom `two_factor_secret`, `two_factor_recovery_codes`, `two_factor_confirmed_at` dikosongkan dan baris `passkeys` milik akun itu dihapus, sebelum `RedirectIfTwoFactorAuthenticatable` memeriksa 2FA pada model yang sama. Jadi tidak ada tantangan 2FA, tidak perlu `demo-db` mode `reset`, dan tidak perlu akses ke DB Neon. Kata sandi akun demo tetap `password` (tidak bisa diganti), jadi ini tidak membuka akses baru. Login lewat passkey lama tidak memicu pembersihan (passkey itu hanya bisa dipakai pemilik perangkatnya), tetapi login kata sandi siapa pun berikutnya menghapusnya.
- **UI:** `SecurityController::edit` mengirim prop `signInLocked` (= `DemoAccounts::isProtected`). Bila `true`, bagian 2FA dan passkey tetap tampil (judul, status 2FA, daftar passkey), tetapi tombol Aktifkan/Nonaktifkan 2FA, Tambah passkey, dan Hapus passkey tidak ditampilkan. Sebagai gantinya muncul catatan (`demo-sign-in-note.tsx`) bahwa 2FA dan passkey tidak bisa diubah di akun demo publik, dengan saran membuat akun baru. Server tetap menolak walau route-nya dipanggil manual.
- Implementasi: `App\Http\Middleware\ProtectDemoAccountSignIn`, `DemoAccounts::releaseSignIn()` (dipanggil dari `Fortify::authenticateUsing` di `FortifyServiceProvider`), tes di `tests/Feature/Settings/DemoAccountSignInTest.php`.
- **Tidak berubah:** akun lain (termasuk akun buatan pengunjung) memakai 2FA dan passkey seperti biasa, juga di demo publik; dengan `POS_PROTECT_DEMO_ACCOUNTS=false` (lokal, tes default) semua akun, termasuk tiga akun demo, tidak terpengaruh, dan login tidak membersihkan apa pun.
- **Tes (ditulis lebih dulu):**
    - Dataset tujuh route: akun demo dilindungi → ditolak (redirect + error, atau 403 untuk JSON) dan kolom 2FA / jumlah `passkeys` tidak berubah, termasuk saat 2FA/passkey sudah ada (untuk `disable`/`destroy`).
    - Akun bukan demo dengan pengaturan nyala → `enable`/`confirm`/`regenerate`/`disable` benar-benar jalan, `registration-options` 200, `destroy` menghapus passkey miliknya; `passkey.store` cukup dibuktikan tidak ditolak middleware (kredensial WebAuthn asli tidak bisa dibuat di tes).
    - Pengaturan mati → akun demo bisa mengaktifkan 2FA dan menghapus passkey.
    - Login kata sandi akun demo yang punya 2FA terkonfirmasi + passkey: pengaturan nyala → langsung masuk, kolom kosong, passkey terhapus; pengaturan mati → tetap ke tantangan 2FA, tidak ada yang dihapus.
    - Props halaman Keamanan: `signInLocked` true/false sesuai akun dan pengaturan.

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

Log audit siapa mengubah apa (kemudian dikerjakan di §15), reset 2FA/passkey milik orang lain (kemudian dikerjakan di §14), undangan lewat email, menutup shift orang lain (kemudian dikerjakan di §13), banyak role per akun, permission per fitur.

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

## 13. v2: Owner menutup shift kasir lain (2026-11-26)

Tujuan: owner bisa menutup shift yang ditinggal terbuka (kasir pulang, perangkat rusak) **tanpa login sebagai kasir itu**, dengan hitungan laci yang tetap tercatat, sehingga rekap kas benar dan akun itu bisa dinonaktifkan atau diubah role-nya (§12.3).

### 13.1 Kondisi sekarang (hasil pemeriksaan)

- `CloseShift::handle(Shift, countedCash, note)` mengunci baris shift, menolak bila ada QRIS `pending_payment` atau keranjang ditahan, lalu menyimpan `expected_cash`, `counted_cash`, `cash_difference`. Hanya dipanggil dari `ShiftController::update`, yang mencari shift terbuka milik pengguna yang login.
- Kasir sendiri tidak bisa membereskan QRIS orang lain: `QrisController` menolak (403) sale milik pengguna lain, dan `DiscardHeldSale` hanya untuk kasirnya. Jadi aturan "tolak penutupan" tidak bisa diselesaikan owner.
- `CompleteSale`, `HoldSale`, `StartQrisPayment`, `ProcessSaleReturn` mengambil shift terbuka lewat `ResolvesOpenShift` **tanpa mengunci shift**. Selama hanya kasir itu sendiri yang menutup, celahnya kecil; begitu orang lain bisa menutup, transaksi bisa tersimpan ke shift yang baru saja ditutup (kas seharusnya yang tersimpan jadi salah).
- `ShiftRecap` dan ekspor menampilkan dibuka/ditutup, tetapi tidak siapa yang menutup. Keranjang di layar kasir disimpan di `localStorage` per `shift.id`.

### 13.2 Keputusan

- **Tempat:** halaman baru khusus owner `GET /laporan/kasir/shift/{shift}/tutup` (form seperti layar Tutup shift kasir: modal, penjualan tunai, retur, kas seharusnya, uang dihitung, selisih langsung). Pintu masuknya: tombol **"Tutup shift"** di baris berstatus "Berjalan" pada Laporan → Rekap kasir & shift, dan di halaman Pengguna (badge "Shift terbuka" serta item menu "Nonaktifkan (tutup shift dulu)" menjadi tautan ke halaman ini). Shift milik owner sendiri dialihkan ke layar Tutup shift biasa.
- **Hitungan laci wajib** (owner menghitung uang yang ada di laci; bila uangnya tidak ada, isi 0 dan selisihnya terlihat). **Alasan wajib** (maks. 255 karakter), mis. "Kasir pulang, lupa tutup shift".
- **QRIS `pending_payment` milik shift itu dibereskan otomatis**, bukan menolak penutupan: sebelum transaksi DB, setiap sale dicek ke Midtrans lewat `QrisPayments::cancelByCashier()` (yang sudah ada: refresh status → lunas jadi `completed` dan ikut dihitung; selain itu dibatalkan di Midtrans lalu `cancelled`). Di dalam transaksi penutupan, sale yang masih `pending_payment` (mis. Midtrans tidak terjangkau) dibatalkan lokal. Halaman tutup menampilkan daftarnya lebih dulu, termasuk bagian tunai transaksi campuran yang mungkin masih dipegang kasir ("tunai Rp … mungkin ada di laci, tidak dihitung"). Lunas terlambat setelah dibatalkan tetap berlaku (§9.3, §11.3); kas seharusnya yang sudah tersimpan tidak berubah.
- **Keranjang ditahan milik shift itu dihapus** (tidak memesan stok, §5), jumlahnya ditampilkan di halaman sebelum menutup.
- **Siapa yang menutup:** migrasi expand saja (§9.6): `shifts.closed_by` (FK `users`, nullable, `restrictOnDelete`) dan `shifts.close_reason` (string, nullable). Mulai sekarang semua penutupan mengisi `closed_by` (kasir sendiri = dirinya); shift lama tetap `null` dan dibaca sebagai "ditutup kasirnya sendiri". Kode lama yang masih melayani selama migrasi tidak membaca kolom ini.
- **Rekap & ekspor:** kolom Ditutup menampilkan "oleh {nama owner}" + alasan di bawah waktu tutup bila `closed_by ≠ user_id`; ekspor Excel "Per shift" mendapat kolom **Ditutup oleh** (kosong bila kasirnya sendiri) dan **Alasan**.
- **Kasir yang masih login:** request berikutnya yang butuh shift (bayar, tahan, QRIS, retur) gagal dengan "Buka shift dulu sebelum bertransaksi." lalu `/kasir` menampilkan halaman Buka shift dengan pemberitahuan "Shift Anda ditutup oleh {owner} pukul {jam}: {alasan}" (prop dari shift terakhir kasir itu bila ditutup orang lain dan belum ada shift baru). Polling QRIS yang sedang tampil melihat status `cancelled`. Keranjang yang sedang diisi hilang (kunci `localStorage` per shift); ini diterima karena penutupan oleh owner adalah kejadian luar biasa, dan halaman owner menampilkan **transaksi terakhir shift itu** ("12 menit lalu") sebagai peringatan bahwa kasir mungkin masih bertugas.
- **Konkurensi:** `ResolvesOpenShift` ditambah varian terkunci yang dipanggil di dalam transaksi `CompleteSale`, `HoldSale`, `StartQrisPayment`, `ProcessSaleReturn`: `SELECT … FOR UPDATE` pada baris shift lalu cek ulang masih terbuka. Penutupan juga mengunci shift lebih dulu, jadi transaksi dan penutupan berjalan bergantian; transaksi yang kalah mendapat pesan "Buka shift dulu…". Urutan kunci di semua alur: **shift → sale → produk**; `QrisPayments::settle()` tidak mengunci shift, jadi tidak ada siklus.
- **Akun demo publik:** owner pengunjung **boleh** menutup shift `kasir@kasir.test`. `DemoAccounts::isProtected` menjaga cara akun masuk (kata sandi, email, role, 2FA); menutup shift tidak mengubah itu, dan siapa pun yang login sebagai kasir demo sudah bisa menutup shift itu sendiri. Melarangnya justru membuat shift terbuka yang ditinggal pengunjung tidak bisa dibereskan.
- **Tidak berubah:** penutupan oleh kasir sendiri tetap menolak bila ada QRIS menunggu atau keranjang ditahan (kasir bisa membereskannya sendiri). Pengaman §12.3 tetap: akun dengan shift terbuka tidak bisa dinonaktifkan/dijadikan gudang; bedanya sekarang owner bisa menutup shift itu dulu.

### 13.3 Implementasi

- Action baru `App\Actions\Sales\CloseShiftForUser` (owner): validasi alasan & hitungan → bereskan QRIS di luar transaksi → transaksi: kunci shift, cek masih terbuka, hapus keranjang ditahan, batalkan sisa `pending_payment`, lalu logika tutup yang sama dengan `CloseShift` (`CloseShift::lockOpen()` / `closeLocked()`) dengan `closed_by` dan `close_reason`. `CloseShift::handle()` mendapat parameter opsional `closedBy` (bawaan: kasir shift itu).
- Kunci shift di transaksi kasir: `ResolvesOpenShift::lockOpenShift()`.
- Controller `App\Http\Controllers\Reports\ShiftCloseController` (`edit`, `update`), route `reports.shifts.close.edit|update` (`/laporan/kasir/shift/{shift}/tutup`) di grup `role:owner`; halaman `resources/js/pages/reports/close-shift.tsx`. Prop `closedByOther` di `pos/open-shift`, `open_shift_id` di `users/index`, `closedBy`/`closeReason` di `ShiftRecap`.
- Migrasi `2026_11_26_000001_add_closed_by_to_shifts`. Tes: `tests/Feature/Sales/OwnerCloseShiftTest.php`, `tests/Concurrency/ShiftCloseRaceTest.php`.

### 13.4 Rencana tes (ditulis lebih dulu)

- Akses: kasir dan gudang 403; shift sudah ditutup → kembali dengan pesan; shift milik owner sendiri → dialihkan ke layar kasir.
- Tutup oleh owner: `expected_cash`/`counted_cash`/`cash_difference` benar (termasuk campuran dan retur), `closed_by` = owner, `close_reason` tersimpan; alasan kosong dan hitungan negatif ditolak.
- QRIS menunggu (fake Midtrans): yang ternyata lunas jadi `completed` dan masuk kas seharusnya, stok keluar sekali; yang belum dibayar `cancelled` dan dibatalkan di Midtrans; Midtrans gagal → tetap `cancelled` lokal.
- Keranjang ditahan shift itu terhapus; keranjang shift lain tidak.
- Kasir setelah shift-nya ditutup: bayar/tahan/QRIS/retur ditolak "Buka shift dulu…"; `/kasir` merender Buka shift dengan prop pemberitahuan; setelah membuka shift baru pemberitahuan hilang.
- Penutupan oleh kasir sendiri: `closed_by` = kasir, aturan tolak lama tetap.
- Rekap & ekspor: "Ditutup oleh" dan alasan muncul hanya untuk penutupan oleh orang lain.
- Setelah ditutup owner, `DeactivateUser` dan ubah role ke gudang berhasil.
- Konkurensi (PostgreSQL, `tests/Concurrency`): `CompleteSale` dan penutupan oleh owner berebut shift yang sama → sale tidak pernah tersimpan di shift yang sudah tertutup, dan `expected_cash` cocok dengan sale yang masuk.
- `pos.protect_demo_accounts = true`: owner tetap bisa menutup shift akun kasir demo.
- `DemoSalesSeeder` tetap jalan.

## 14. v2: Owner mereset 2FA & passkey akun lain (2026-11-26)

Tujuan: pengguna yang kehilangan HP authenticator (atau perangkat yang menyimpan passkey-nya hilang/dicuri) bisa masuk lagi **tanpa mengubah DB manual**: owner menghapus 2FA dan passkey akun itu dari halaman Pengguna, lalu pengguna masuk dengan kata sandi saja dan boleh memasang 2FA/passkey baru sendiri.

### 14.1 Kondisi sekarang (hasil pemeriksaan)

- 2FA (Fortify, `confirm: true`): kolom `users.two_factor_secret`, `two_factor_recovery_codes` (keduanya terenkripsi, `#[Hidden]`), `two_factor_confirmed_at`. Tantangan 2FA saat login hanya muncul bila `hasEnabledTwoFactorAuthentication()` (= secret ada **dan** sudah dikonfirmasi). Passkey (Laravel Passkeys lewat Fortify): tabel `passkeys` (`user_id` cascade, `name`, `credential_id`, `credential` JSON, `last_used_at`). Login passkey tidak melewati tantangan 2FA.
- Jadi yang **mengunci** pengguna hanya 2FA. Passkey tidak pernah mengunci (login kata sandi tetap jalan), tetapi passkey di perangkat yang dicuri adalah jalan masuk bagi pencurinya.
- Cara menghapus keduanya sudah ada di `DemoAccounts::releaseSignIn()` (kosongkan tiga kolom + `passkeys()->delete()`), hanya untuk akun demo saat login.
- Pengguna sendiri bisa menonaktifkan 2FA / menghapus passkey di Pengaturan → Keamanan (`RequirePassword` di halaman itu, `password.confirm` di route vendor). Aksi di `/pengguna` tidak memakai konfirmasi kata sandi; route `password.confirm` Fortify bekerja dengan redirect halaman penuh, tidak cocok untuk aksi `DELETE` dari dialog.
- Sesi berakhir hanya lewat `EnsureUserIsActive` (nonaktif) dan `AuthenticateSession` (hash kata sandi berubah); `endSessions()` di `GuardsAccounts` hanya mengganti `remember_token`.

### 14.2 Keputusan (disetujui 2026-11-26)

- **Satu aksi "Reset 2FA & passkey"**, bukan dua. Kasus utamanya satu HP hilang yang biasanya memegang authenticator dan passkey sekaligus; dua aksi menambah menu dan dialog tanpa kasus nyata yang butuh menghapus salah satu saja (pengguna yang masih bisa masuk mengatur sendiri di Keamanan). Yang dihapus: tiga kolom 2FA (termasuk setup yang belum dikonfirmasi) dan **semua** baris `passkeys` akun itu.
- **Owner wajib mengetik kata sandinya sendiri di dialog** (aturan `current_password`, route `throttle:6,1`), bukan `password.confirm`. Alasannya: dengan sesi owner yang dibajak, "Reset kata sandi" + "Reset 2FA" bersama-sama = mengambil alih akun lain; 2FA-lah satu-satunya yang masih menahan, jadi langkah ini yang dikunci. Field di dialog lebih sederhana daripada redirect halaman konfirmasi. **Tanpa alasan wajib**: belum ada tempat menyimpannya (log audit masih roadmap, §6); dicatat di log aplikasi saja (lihat skema).
- **Sesi yang sedang berjalan tidak diakhiri**, hanya `remember_token` diganti (`endSessions()`, sama dengan aksi lain). Mengakhiri sesi tanpa ganti kata sandi butuh kolom baru atau menghapus baris `sessions` (ditolak di §12.3 karena pengguna tidak melihat alasannya). Untuk perangkat yang **dicuri**, dialog menyarankan "Reset kata sandi" juga, yang mengakhiri semua sesi lewat `AuthenticateSession`.
- **Login berikutnya bebas**: kata sandi saja, tanpa paksaan memasang 2FA lagi. Aplikasi tidak pernah mewajibkan 2FA, jadi memaksa sesudah reset akan jadi satu-satunya tempat 2FA wajib (dan butuh kolom + middleware seperti `RequirePasswordChange`). Toast sukses mengingatkan owner untuk menyampaikannya.
- **Pengaman** (di Action, lewat `GuardsAccounts`):
    - **Akun sendiri ditolak**: "Atur 2FA dan passkey Anda sendiri di Pengaturan → Keamanan." (sama dengan reset kata sandi/PIN).
    - **Sesama owner boleh** — justru kasus terpenting (owner kedua kehilangan HP). Owner tunggal yang terkunci tetap butuh jalan lain; lihat poin opsional di bawah.
    - **Akun nonaktif boleh** (tidak bisa masuk, jadi tidak ada risiko; berguna sebelum diaktifkan lagi).
    - **Tidak ada yang direset** (tanpa 2FA dan tanpa passkey) → ditolak dengan pesan, dan item menu disembunyikan.
- **Akun demo dilindungi**: ditolak dengan `DemoAccounts::MESSAGE` (`ensureNotProtected`), sama seperti semua aksi akun lain dan aturan §12.4.1 "tidak ada yang berubah". Dalam praktik item menunya hampir tidak pernah muncul untuk akun demo, karena akun itu tidak bisa punya 2FA/passkey, dan sisa lama sudah dibersihkan saat login kata sandi.
- **Tanpa skema / migrasi.** "Siapa yang terakhir mereset" adalah bagian log audit (roadmap §6); satu kolom sekarang hanya akan menjawab satu kejadian terakhir. Sebagai gantinya `Log::notice('two-factor reset', [actor_id, user_id, had_two_factor, passkeys])` (tanpa data rahasia), yang terbaca di log runtime Vercel.
- **Satu sumber kebenaran untuk "hapus 2FA & passkey"**: Action baru `App\Actions\Users\ResetSignInFactors` (aturan + log) memakai method kecil di model, mis. `User::forgetSecondFactors()`, yang juga dipakai `DemoAccounts::releaseSignIn()` menggantikan kode duplikatnya.

### 14.3 Tampilan (halaman Pengguna)

- Prop baru per baris: `two_factor` (bool, = `hasEnabledTwoFactorAuthentication()`), `has_two_factor_state` (bool, termasuk setup yang belum dikonfirmasi; menentukan apakah item menu muncul) dan `passkeys` (jumlah, `withCount`). Tidak ada secret, kode pemulihan, nama perangkat, atau `credential` yang dikirim.
- Kolom Status mendapat badge **"2FA"** dan **"N passkey"** bila ada (di samping "Aktif", "Shift terbuka", dll.).
- Menu baris: **"Reset 2FA & passkey"** (ikon `Smartphone`) di bawah "Reset kata sandi", hanya bila bukan akun sendiri dan ada yang bisa direset; nonaktif untuk akun demo terlindungi.
- Dialog: judul "Reset 2FA & passkey {nama}?", daftar yang akan dihapus ("2FA (aplikasi authenticator dan kode pemulihan)" / "Pengaturan 2FA yang belum selesai", "2 passkey"), catatan "{nama} bisa masuk dengan kata sandi saja, lalu memasang 2FA/passkey baru di Pengaturan → Keamanan. Sesi yang sedang berjalan tidak berakhir; bila perangkatnya dicuri, reset juga kata sandinya.", input "Kata sandi Anda", tombol destruktif "Reset 2FA & passkey" (nonaktif selama kata sandi kosong).

### 14.4 Route & implementasi

- `DELETE /pengguna/{user}/2fa` → `UserController::signInFactors`, route `users.sign-in-factors`, grup `role:owner` yang sudah ada, `throttle:6,1`.
- Action `ResetSignInFactors::handle(User $actor, User $user)`: tolak diri sendiri / akun demo / tidak ada yang direset → transaksi: `forgetSecondFactors()` + `endSessions()` → log. Baris akun dikunci (`lockForUpdate`) dan isinya dibaca ulang di transaksi. Validasi `current_password` di controller (`$request->validate`).
- `User::hasTwoFactorState()` dan `User::forgetSecondFactors()`; `DemoAccounts::releaseSignIn()` kini memanggil yang kedua. Tes: `tests/Feature/Users/ResetSignInFactorsTest.php`.

### 14.5 Tidak dikerjakan

- **Owner tunggal yang terkunci**: perintah `php artisan user:reset-2fa {email}` (memakai Action yang sama, aktor = `null`/sistem) agar tidak perlu SQL manual. Di demo live perintah ini hanya bisa dijalankan dari runner CI/Neon, jadi gunanya terutama untuk instalasi toko sungguhan. Diputuskan tidak dibuat (2026-11-26); owner tunggal yang terkunci tetap butuh perubahan DB manual.

### 14.6 Rencana tes (ditulis lebih dulu)

- Akses: gudang/kasir 403, tamu ke login.
- Reset akun dengan 2FA terkonfirmasi + 2 passkey: ketiga kolom kosong, 0 passkey, `remember_token` berganti; passkey akun lain tidak tersentuh; login kata sandi berikutnya langsung masuk tanpa tantangan 2FA (tidak diarahkan ke `two-factor.login`).
- Setup 2FA yang belum dikonfirmasi saja juga bisa direset.
- Kata sandi owner salah/kosong → 422, tidak ada yang berubah.
- Pengaman: akun sendiri ditolak; sesama owner dan akun nonaktif berhasil; akun tanpa 2FA/passkey ditolak; `pos.protect_demo_accounts = true` → akun demo ditolak, akun buatan sendiri tetap bisa.
- `DemoAccounts::releaseSignIn()` tetap lulus `DemoAccountSignInTest` setelah memakai `forgetSecondFactors()`.
- Props halaman Pengguna: `two_factor` dan `passkeys` benar, dan tidak ada kunci `two_factor_secret` / `two_factor_recovery_codes` / `credential` di respons.

## 15. v2: Log audit perubahan akun (2026-11-26)

Tujuan: owner bisa melihat **siapa mengubah akun siapa, kapan, dan apa yang berubah**, termasuk perubahan yang dilakukan pengguna pada akunnya sendiri (kata sandi, PIN, 2FA, passkey). Ini menjawab pertanyaan yang sejak §12–§14 hanya tersisa di log aplikasi (`Log::notice` di `ResetSignInFactors`) atau tidak tercatat sama sekali.

### 15.1 Kondisi sekarang (hasil pemeriksaan)

- Perubahan akun oleh owner semuanya lewat Action di `app/Actions/Users/*` (`CreateUser`, `UpdateUser`, `DeactivateUser`, `ReactivateUser`, `SetTemporaryPassword`, `ResetOwnerPin`, `ResetSignInFactors`), masing-masing dengan transaksinya sendiri.
- Perubahan oleh pengguna sendiri tersebar: `ProfileController::update` (nama/email), `SecurityController::update` (kata sandi), `ForcedPasswordController::update` (kata sandi wajib), `OwnerPinController::update` (PIN), route vendor Fortify (2FA) dan Laravel Passkeys (passkey). Vendor memancarkan event setelah perubahan tersimpan: `TwoFactorAuthenticationEnabled/Confirmed/Disabled`, `RecoveryCodesGenerated`, `PasskeyRegistered`, `PasskeyDeleted`.
- Perubahan oleh sistem: `DemoAccounts::releaseSignIn()` (membersihkan 2FA/passkey akun demo saat login).
- Penutupan shift oleh owner sudah tercatat di `shifts.closed_by`/`close_reason` (§13), jadi bukan bagian log akun.

### 15.2 Keputusan (disetujui 2026-11-26)

- **Tabel baru `account_events`** (migrasi expand saja, §9.6): `id`, `user_id` (akun yang berubah, FK `restrictOnDelete`), `actor_id` (FK nullable; `null` = sistem), `type` (string, enum `App\Enums\AccountEventType`), `details` (json nullable), `created_at`. **Tanpa `updated_at`**: baris tidak pernah diubah atau dihapus oleh aplikasi, sama seperti `stock_movements`.
- **Yang dicatat** (`type`):
    - Oleh owner: `created`, `updated` (nama/email/role, lama → baru), `deactivated`, `reactivated`, `password_reset`, `pin_reset`, `sign_in_factors_reset` (punya 2FA atau tidak, jumlah passkey).
    - Oleh pengguna sendiri: `profile_updated` (nama/email lama → baru), `password_changed` (dari Keamanan atau halaman wajib ganti), `pin_set`, `two_factor_enabled` (saat dikonfirmasi), `two_factor_disabled`, `recovery_codes_regenerated`, `passkey_added`, `passkey_removed`.
    - Oleh sistem: `demo_sign_in_released` (hanya bila memang ada yang dibersihkan).
- **Tidak pernah disimpan:** kata sandi, PIN, secret/kode pemulihan 2FA, `credential` passkey, maupun nama passkey (konsisten dengan §14.3). `details` hanya berisi nilai lama/baru untuk nama, email, role, serta angka/boolean.
- **Tidak dicatat:** login berhasil/gagal dan logout (volume besar dan sudah dibatasi rate limiter; bisa jadi tambahan terpisah), alamat IP dan user agent (di demo publik semua pengunjung yang login sebagai owner bisa membuka log, jadi IP pengunjung lain akan bocor).
- **Satu titik pencatatan:** service `App\Services\Audit\AccountLog::record(User $user, AccountEventType $type, ?User $actor, array $details = [])`.
    - Action owner memanggilnya **di dalam transaksinya**, jadi log dan perubahan selalu sama-sama tersimpan atau sama-sama batal.
    - Controller self-service yang kita miliki juga memanggilnya langsung.
    - Event vendor (2FA, passkey) lewat listener; event itu dipancarkan setelah perubahan tersimpan, jadi tidak satu transaksi, dan ini diterima.
    - `Log::notice` di `ResetSignInFactors` (§14) diganti dengan `AccountLog`.
- **Tanpa backfill:** log mulai dari deploy ini. Halaman menyebutkan "Riwayat dicatat sejak {tanggal baris pertama}".
- **Akun demo publik:** log tetap dicatat dan terlihat oleh owner pengunjung. Isinya hanya perubahan pada akun buatan pengunjung (akun demo tidak bisa diubah, §12.4), plus `demo_sign_in_released`.

### 15.3 Tampilan (owner saja)

- Halaman baru **Pengguna → Riwayat akun** (`GET /pengguna/riwayat`, route `users.events.index`, grup `role:owner`): tabel terbaru dulu (waktu, akun, oleh siapa / "Sistem" / "Sendiri", kejadian dalam bahasa Indonesia, rincian mis. "Role: Kasir → Staf Gudang"), filter akun dan jenis kejadian, paginasi 50 per halaman.
- Menu aksi per baris di Pengguna mendapat **"Riwayat"** yang membuka halaman itu terfilter ke akun tersebut; tombol "Riwayat akun" di header halaman Pengguna.
- Tidak ada ekspor Excel di versi ini.

### 15.4 Rencana tes (ditulis lebih dulu)

- Setiap Action owner menulis tepat satu baris dengan `actor_id` = owner dan `details` yang benar; Action yang ditolak (pengaman §12.3 / §14) tidak menulis apa pun (transaksi yang sama).
- Self-service: profil, kata sandi (Keamanan dan wajib ganti), PIN, 2FA aktif/nonaktif/kode baru, passkey tambah/hapus menulis baris dengan `actor_id` = pengguna itu.
- `DemoAccounts::releaseSignIn()` menulis `demo_sign_in_released` dengan `actor_id = null` hanya bila ada yang dibersihkan.
- Tidak ada kata sandi, PIN, secret, kode pemulihan, `credential`, atau nama passkey di tabel maupun di props halaman.
- Halaman: gudang/kasir 403; filter akun dan jenis bekerja; urutan terbaru dulu.
- `DemoSalesSeeder` tetap jalan (seeder membuat akun lewat jalur yang tidak memanggil `AccountLog`, jadi log demo kosong sampai ada perubahan).

### 15.5 Implementasi

- Migrasi `2026_11_26_000002_create_account_events_table`, enum `App\Enums\AccountEventType` (dengan label Indonesia), model `App\Models\AccountEvent` (`UPDATED_AT = null`), service `App\Services\Audit\AccountLog` (`record()`, `changes()` untuk pasangan lama → baru, `describe()` untuk baris rincian di halaman, `listen()` untuk event vendor, dipasang di `AppServiceProvider`).
- `CreateUser::handle()` dan `ReactivateUser::handle()` kini menerima `$actor` sebagai argumen pertama (sama dengan Action lain). `ReactivateUser` dan `ResetOwnerPin` kini memakai transaksi; `ReactivateUser` pada akun yang sudah aktif tidak mencatat apa pun. `UpdateUser` dan perubahan profil yang tidak mengubah apa pun tidak mencatat baris.
- Pelaku untuk event vendor = pengguna yang sedang login (biasanya pemilik akun itu sendiri). `PasswordReset` (tautan lupa kata sandi) dicatat sebagai `password_changed` dengan `via: reset_link`.
- `DemoAccounts::releaseSignIn()` hanya membersihkan dan mencatat bila memang ada 2FA/passkey.
- Controller `Users/AccountEventController`, route `users.events.index`, halaman `resources/js/pages/users/events.tsx`; tes `tests/Feature/Users/AccountLogTest.php`.

## 16. v2: Ekspor PDF laporan (2026-11-27)

Tujuan: owner bisa mengunduh keempat laporan (penjualan & laba kotor, produk terlaris, nilai persediaan, rekap kasir & shift) sebagai **PDF siap cetak/kirim**, dengan filter yang sama seperti halaman dan ekspor Excel. Excel tetap untuk diolah, sedangkan PDF untuk dibaca, dicetak, atau dikirim ke orang lain apa adanya.

### 16.1 Kondisi sekarang (hasil pemeriksaan)

- Ekspor Excel (§9.4) ada di `ReportExportController`, satu method per laporan, di route `reports.export.*` (`/laporan/excel/...`, `role:owner`, `throttle:30,1`). Setiap method menyusun judul, kolom (label, format, lebar), baris, dan baris total dari query `app/Reports/*`, lalu `Spreadsheet` menulis `.xlsx`.
- Definisi tabel itu (kolom mana, urutan, tanda minus untuk retur, format jam) hanya ada di controller Excel. Ekspor PDF yang menyusun tabelnya sendiri akan membuat dua sumber yang bisa berbeda.
- Rentang tanggal (`DateRange`) tidak punya batas maksimum. Produk terlaris dibatasi 500 baris, sedangkan persediaan memuat semua produk aktif.
- Composer belum punya library PDF. Ekstensi PHP di `Dockerfile`/CI: `bcmath intl pdo_pgsql zip opcache pcntl`, ditambah ekstensi bawaan image (`dom`, `mbstring`).
- Tombol ekspor di halaman laporan: `ExportButton` di `components/reports/report-nav.tsx` ("Ekspor Excel").

### 16.2 Keputusan (disetujui 2026-11-27; batas ukuran dan tata letak disesuaikan setelah diukur, lihat §16.5)

- **Satu definisi tabel untuk dua format.** Penyusunan judul, kolom, baris, dan total dipindah dari `ReportExportController` ke `App\Exports\ReportTables` (satu method per laporan, mengembalikan daftar "lembar"). `Spreadsheet` dan renderer PDF yang baru sama-sama membaca dari sana, jadi isi Excel tidak berubah; tes Excel yang sudah ada menjadi penjaganya.
- **Library: `dompdf/dompdf`** (PHP murni, LGPL-2.1, butuh `dom` + `mbstring` yang sudah ada; font DejaVu Sans bawaan). PDF dibuat dari view Blade `resources/views/reports/pdf.blade.php` dengan CSS sederhana: tabel, judul kolom berulang di setiap halaman, nomor halaman.
    - Ditolak: **cetak dari browser** ("Simpan sebagai PDF", seperti struk). Tanpa dependency, tetapi hasilnya bergantung browser dan setelan cetak, tidak menghasilkan file yang bisa diuji, dan bukan "ekspor".
    - Ditolak: **Chromium headless** (Browsershot/Gotenberg). Hasilnya paling rapi, tetapi butuh Node/Chromium di image atau layanan terpisah, terlalu berat untuk Vercel Hobby.
    - Ditolak: **TCPDF/FPDF**. Tata letaknya harus ditulis lewat kode PHP, bukan HTML/CSS.
- **Isi sama dengan Excel**, dengan format angka Indonesia sebagai teks: `Rp 15.000`, `-Rp 2.500` untuk retur, `12,5%`, tanggal `27 Nov 2026`, jam WIB. Laporan yang punya beberapa lembar di Excel (persediaan: per produk + per kategori; rekap kasir: per kasir + per shift) menjadi beberapa bagian dalam satu PDF.
- **Kertas A4.** Penjualan (11 kolom), produk terlaris (12 kolom), dan rekap kasir & shift (13 kolom) memakai lanskap; persediaan (7 kolom) memakai potret. Huruf 8–9 pt; kolom "Alasan" dibungkus, bukan dipotong.
- **Kepala dokumen:** nama, alamat, dan NPWP toko dari `config('pos.store')`, nama laporan, periode (atau "Per {tanggal jam} WIB" untuk persediaan), catatan cara hitung yang sama dengan Excel, serta "Dicetak {waktu} oleh {nama owner}". Kaki halaman: "Halaman X dari Y".
- **Batas ukuran:** dompdf lambat dan boros memori untuk tabel panjang. Bila satu tabel melebihi **6.500 sel** (baris × kolom; `POS_PDF_MAX_CELLS`), PDF ditolak dengan pesan "Terlalu banyak baris untuk PDF ({n}, maksimal {batas}). Persempit periode atau pakai Ekspor Excel." Batasnya: rekap per shift 500 baris, produk terlaris 541, penjualan 590, persediaan per produk 928. Excel tetap tanpa batas. (Usulan awal 1.000 baris ternyata terlalu berat, lihat §16.5.)
- **Keamanan dompdf:** `isRemoteEnabled = false` (tidak memuat URL luar), `isPhpEnabled = false`, `isJavascriptEnabled = false`, `chroot` = direktori font/view saja. Semua teks dari database (nama produk, kasir, alasan tutup shift) di-escape lewat `{{ }}` Blade.
- **Route:** `GET /laporan/pdf/{penjualan|produk-terlaris|persediaan|kasir}`, nama `reports.pdf.sales|products|inventory|shifts`, middleware yang sama dengan Excel (`role:owner`, `throttle:30,1`). Nama file sama dengan Excel tetapi `.pdf`. Respons `Content-Disposition: attachment`.
- **Tampilan:** di sebelah "Ekspor Excel" ada tombol **"Ekspor PDF"** (ikon `FileText`) dengan filter halaman yang sama.

### 16.3 Tidak dikerjakan

- PDF untuk halaman lain (riwayat transaksi, PO, opname, log akun §15), grafik di PDF, kirim PDF lewat email/WhatsApp, logo toko (belum ada setelan logo).

### 16.4 Rencana tes (ditulis lebih dulu)

- `ReportTables`: setiap laporan menghasilkan kolom/baris/total yang sama dengan isi Excel sekarang (tes Excel yang ada tetap hijau tanpa diubah).
- Setiap route PDF: owner mendapat `application/pdf` diawali `%PDF-`, nama file benar, dan filter periode/grup dipakai. Isi diperiksa pada HTML yang diberikan ke dompdf (`ReportPdf::html()`), karena teks di PDF dengan font tertanam tidak bisa dibaca ulang dengan andal. Gudang dan kasir 403, tamu dialihkan ke login.
- Format angka: rupiah, minus retur, persen, dan jam WIB.
- Escape: nama produk berisi `<b>` tampil sebagai teks.
- Batas ukuran: di atas batas → redirect kembali dengan pesan error dan tanpa PDF; tepat di batas → PDF dibuat; tabel yang lebih sempit boleh lebih banyak baris.
- Tabel panjang dipecah: setiap potongan punya judul kolom, baris total hanya sekali.
- Dompdf tidak memuat sumber luar (`isRemoteEnabled` mati).

### 16.5 Implementasi

- `App\Exports\ReportDocument` (judul, periode, catatan, nama file, lanskap, daftar tabel) dibuat oleh `App\Exports\ReportTables` (satu method per laporan, isinya dipindah apa adanya dari `ReportExportController`). `ReportDocument::toSpreadsheet()` menulis `.xlsx` seperti sebelumnya; satu-satunya perubahan pada Excel: jam di judul nilai persediaan kini `10.00` (bukan `10:00`), sama dengan PDF.
- `App\Exports\ReportPdf`: `html()` (view `resources/views/reports/pdf.blade.php`), `download()` (cek batas sel, render A4, kaki halaman "{laporan} — {toko} · Halaman X dari Y"), `dompdf()` (remote/PHP/JavaScript mati, folder sementara di `storage/app/private/exports`), dan `cell()` (format Indonesia: `Rp 1.500.000`, `-Rp 2.500`, `Rp 8.123,46` untuk HPP rata-rata, `1.234`, `12,5%`, `5 Nov 2026`, `Nov 2026`, `21 Nov 2026 08.05`). Kolom di `ReportTables` mendapat `kind` (`date`/`month`/`datetime`) yang hanya dipakai PDF, dan format baru `Spreadsheet::RUPIAH_CENTS`.
- Satu controller untuk dua format: route `reports.export.*` (`/laporan/excel/...`) dan `reports.pdf.*` (`/laporan/pdf/...`) memanggil method yang sama dengan default route `format` (`xlsx`/`pdf`). Bila PDF terlalu besar: toast error dan kembali ke halaman laporan. Tombol `ExportButtons` di `components/reports/report-nav.tsx`; link PDF sengaja tanpa atribut `download` supaya redirect penolakan kembali ke halaman, bukan tersimpan sebagai file.
- **Ukuran diukur** (lokal, PHP 8.5, `memory_limit` produksi 256M dari `docker/php.ini`). Satu tabel panjang: 1.000 baris × 11 kolom butuh 14,8 detik dan 508 MB, sedangkan 2.000 baris kehabisan 1 GB. `table-layout: fixed` saja tidak cukup (500 × 13: 9,5 detik, 404 MB). Memecah tabel menjadi potongan 50 baris (`ReportPdf::ROWS_PER_TABLE`, lebar kolom tetap dari lebar Excel supaya potongan tetap sejajar): 500 × 13 = 3,4 detik / 182 MB, 590 × 11 = 3,1 detik / 170 MB, 928 × 7 = 3,5 detik / 170 MB. Biaya mengikuti jumlah sel, jadi batasnya 6.500 sel per tabel, bukan jumlah baris tetap.
- Dependency baru: `dompdf/dompdf` ^3.1.6 (beserta `dompdf/php-font-lib`, `dompdf/php-svg-lib`, `masterminds/html5`, `sabberworm/php-css-parser`); paket lain tidak berubah. `composer audit` melaporkan 7 advisory di paket Symfony yang sudah terpasang sebelumnya (`http-kernel`, `mailer`, `routing`, `serializer`), bukan dari dompdf; diperbarui 2026-11-27 (lihat §8).
- Tes: `tests/Feature/Reports/PdfExportTest.php`; `ExportTest` (Excel) tidak diubah dan tetap hijau.

## 17. v2: Kasbon / piutang pelanggan (2026-11-27)

Tujuan: kasir bisa mencatat penjualan yang **dibayar belakangan** oleh pelanggan langganan (warung tetangga, pegawai, pelanggan tetap), lalu pelanggan melunasinya sebagian atau penuh di kasir mana pun. Owner bisa melihat siapa berutang berapa dan sejak kapan.

### 17.1 Kondisi sekarang (hasil pemeriksaan)

- **Belum ada pelanggan** sama sekali: tidak ada tabel, model, atau kolom `customer_id`. Satu-satunya nama pelanggan adalah `sales.held_label` (label bebas keranjang ditahan).
- `PaymentMethod`: `cash`, `qris`, `split` (§11); `sales.payment_method` `varchar(10)`. `SaleStatus`: `held`, `pending_payment`, `completed`, `cancelled`. Hanya `CompleteSale` (tunai) dan `QrisPayments::settle()` (QRIS/campuran) yang membuat sale `completed` dan mengeluarkan stok lewat `BooksSaleStock` → `StockLedger::issue()`.
- **Kas laci**: `Shift::expectedCash()` = modal + `cashSalesTotal()` (Σ `Sql::cashPart()` sale `completed` di shift itu) − `refundsTotal()` (Σ `sale_returns.total` yang diproses di shift itu). `ShiftRecap` dan `CloseShift`/`CloseShiftForUser` memakai rumus yang sama; `Sql::cashPart()`/`qrisPart()` adalah satu-satunya ekspresi pembagian cara bayar, dipakai juga oleh `SalesSummary`.
- **Retur** (`ProcessSaleReturn`, §9.2): PIN owner, shift terbuka wajib, nilai retur = bagian yang dibayar pelanggan (`Apportion`), dan **seluruh** `sale_returns.total` dianggap uang tunai keluar dari laci. `SalesSummary` memakai `sale_returns.total` sebagai pengurang omzet.
- **Laba kotor** (§9.1) diakui saat sale `completed` (`completed_at`), tidak bergantung cara bayar.
- **Laporan & ekspor**: `app/Reports/*` → `App\Exports\ReportTables` → `ReportDocument` → Excel (`Spreadsheet`) dan PDF (`ReportPdf`, batas 6.500 sel per tabel). Ringkasan penjualan punya kolom Tunai dan QRIS (Tunai + QRIS = Penjualan); rekap shift punya Tunai, QRIS, Retur, Kas seharusnya.
- **Nomor dokumen**: `DocumentNumber` punya `GR`, `SO`, `PO`, `INV`, `RET`.
- **Pola yang ditiru**: `StockLedger` (buku besar immutable + cache di baris induk yang dikunci `FOR UPDATE`, `stock:rebuild` untuk membuktikan cache = replay), urutan kunci **shift → sale → produk** (§13.2).

### 17.2 Keputusan (disetujui 2026-11-27)

**Data pelanggan**

- Tabel `customers`: `name` (wajib, maks. 100), `phone` (nullable, unik bila diisi, dinormalisasi ke angka saja; dipakai untuk mencari), `address` (nullable), `note` (nullable), `credit_limit` (integer Rupiah, ≥ 0), `balance` (integer Rupiah, **cache** sisa utang, hanya diubah lewat `CustomerLedger`), `deactivated_at` (nullable), `created_by` (FK `users`). Pelanggan tidak pernah dihapus (dirujuk oleh sale dan ledger), hanya dinonaktifkan.
- **Siapa yang membuat**: kasir **boleh** menambah pelanggan langsung dari dialog bayar (nama + HP saja), supaya kasbon pertama tidak menunggu owner. Pelanggan baru mendapat **batas kredit bawaan global** `pos.credit.default_limit` (env `POS_CREDIT_DEFAULT_LIMIT`, default Rp 500.000; boleh 0 bila toko ingin semua kasbon disetujui owner dulu). Hanya owner yang mengubah data, batas kredit, dan status pelanggan.
- **Batas kredit per pelanggan** (bawaan dari global saat dibuat). Aturannya: `balance + kasbon baru ≤ credit_limit`; batas 0 = tidak boleh kasbon. Melebihi batas **ditolak** dengan pesan sisa batas ("Batas kasbon Bu Sari Rp 500.000, sisa Rp 120.000."); tidak ada override PIN, owner menaikkan batas di halaman pelanggan bila setuju. Pelanggan nonaktif tidak bisa diberi kasbon, tetapi tetap bisa melunasi.

**Cara bayar**

- `PaymentMethod::Credit` (`credit`, label **"Kasbon"**), dengan **uang muka tunai opsional**: pelanggan boleh membayar sebagian tunai sekarang dan sisanya jadi utang (mis. belanja Rp 87.500, DP Rp 20.000, kasbon Rp 67.500). Kolom baru `sales.credit_amount` = bagian yang menjadi utang (≥ Rp 1, ≤ total); bagian tunai = `total − credit_amount`, diterima pas tanpa kembalian (sama seperti tunai di §11.1). `sales.customer_id` (FK nullable) diisi untuk kasbon.
- **Tidak digabung dengan QRIS** dalam satu transaksi (tidak ada "QRIS + kasbon" atau "tunai + QRIS + kasbon"). Kasbon selesai seketika di `CompleteSale` (tanpa menunggu Midtrans), sedangkan campuran §11 menunggu settlement; menggabungkan keduanya berarti utang tercatat untuk sale yang mungkin batal. Pelanggan yang ingin membayar sebagian lewat QRIS bisa kasbon penuh lalu langsung melunasi sebagian (lihat Pelunasan).
- `Sql::cashPart()` mendapat `WHEN 'credit' THEN sales.total - sales.credit_amount` dan ekspresi baru `Sql::creditPart()`, sehingga Tunai + QRIS + Kasbon = Penjualan di semua laporan, dan uang muka masuk kas laci lewat rumus yang sudah ada.
- Transaksi kasbon tetap satu `Sale` bernomor `INV-…`, `status = completed`, `paid_amount` = uang muka, `change_amount = 0`. Keranjang ditahan bisa dilanjutkan sebagai kasbon (pelanggan dipilih saat bayar, bukan saat tahan).

**Stok, HPP, laba kotor**

- Stok keluar **saat transaksi**, di `CompleteSale` seperti penjualan tunai (`StockLedger::issue()` dengan HPP rata-rata saat itu). Tidak ada perubahan pada `StockLedger` maupun `stock:rebuild`.
- Laba kotor **diakui saat jual** (`completed_at`), sama dengan §9.1: penjualan terjadi saat barang diserahkan, pelunasan hanya penerimaan kas atas piutang. Jadi `SalesSummary`, `TopProducts`, dan dashboard tidak mengubah rumus; laporan penjualan hanya mendapat kolom **Kasbon**.
- **Penghapusan utang** (lihat Role) tidak mengurangi penjualan maupun laba kotor; ia beban piutang tak tertagih yang terlihat di kartu dan laporan piutang, bukan di laporan penjualan.

**Ledger piutang (immutable, seperti `StockLedger`)**

- Tabel `customer_ledger_entries`: `customer_id`, `sale_id` (FK, **wajib**: setiap baris menempel ke satu transaksi kasbon), `type` (`App\Enums\LedgerEntryType`: `sale`, `payment`, `sale_return`, `write_off`), `amount` (signed: + menambah utang, − mengurangi), `balance_after` (saldo pelanggan setelah baris ini), `reference` (polymorphic: `Sale`, `CustomerPayment`, `SaleReturn`, atau `CustomerWriteOff`), `user_id`, `note`, `created_at` (tanpa `updated_at`). Baris tidak pernah diubah atau dihapus; koreksi = baris baru.
- **Satu-satunya pengubah utang**: service `App\Services\Receivables\CustomerLedger` (`charge()`, `settle()`, `reverse()` untuk retur, `writeOff()`), yang mengunci baris `customers` dengan `SELECT … FOR UPDATE`, menulis baris ledger, dan memperbarui `customers.balance` di transaksi yang sama. Sisa utang per transaksi = Σ `amount` per `sale_id`, jadi umur piutang dihitung dari ledger tanpa kolom cache tambahan.
- `php artisan receivables:rebuild [--fix]` menjumlah ulang ledger per pelanggan dan membandingkannya dengan `customers.balance` (dan memeriksa tidak ada sisa per transaksi yang negatif), seperti `stock:rebuild`.
- **Urutan kunci**: shift → pelanggan → sale → produk. `CompleteSale` (kasbon) mengunci pelanggan setelah shift dan sebelum produk; pelunasan mengunci shift lalu pelanggan.

**Pelunasan**

- Dicatat di tabel `customer_payments`: `number` (`PAY-YYYYMMDD-0001`, konstanta baru `DocumentNumber::CUSTOMER_PAYMENT`), `customer_id`, `shift_id` (shift **pengguna yang menerima**, wajib terbuka), `user_id`, `amount`, `method` (`cash`), `note`, `created_at`. Kasir mana pun (atau owner yang membuka shift) bisa menerima pelunasan pelanggan mana pun, dari shift-nya sendiri.
- **Tunai masuk kas shift penerima**: `Shift::expectedCash()` = modal + penjualan tunai (termasuk uang muka kasbon) + **pelunasan tunai** − refund tunai. Halaman Tutup shift (kasir dan owner §13) menampilkan baris "Pelunasan kasbon (N)".
- **Sebagian atau penuh**: 1 ≤ jumlah ≤ `balance`. Kelebihan bayar ditolak (tidak ada saldo deposit); kembalian diberikan kasir di luar sistem seperti biasa, yang dicatat hanya jumlah yang dilunasi.
- **Alokasi FIFO**: pembayaran menutup transaksi kasbon **tertua lebih dulu** (urut `completed_at`, lalu `id`), satu baris ledger `payment` per transaksi yang tersentuh. Tidak ada pilihan transaksi manual: FIFO cocok dengan cara warung menagih ("bayar yang lama dulu") dan membuat umur piutang jujur. Struk pelunasan menampilkan alokasinya.
- **QRIS untuk pelunasan: tidak di versi ini.** Mesin status QRIS (`QrisPayments`, webhook, polling, pembatalan saat tutup shift) melekat pada `Sale`; memakainya untuk pelunasan berarti mesin status kedua atau generalisasi `QrisPayments` ke dokumen lain, dengan risiko yang sama besarnya dengan fitur ini sendiri. Kolom `customer_payments.method` disiapkan supaya QRIS bisa ditambah kemudian tanpa migrasi ulang (lihat 17.4).

**Retur atas transaksi kasbon (§9.2)**

- Nilai retur dihitung persis seperti sekarang (`Apportion`, retur unit terakhir mengembalikan sisa bagian). Bedanya: nilai itu **lebih dulu mengurangi sisa utang transaksi itu**, dan hanya kelebihannya (bila transaksi sudah dibayar sebagian lewat uang muka/pelunasan) dikembalikan tunai dari laci.
- Kolom baru `sale_returns.credit_amount` (default 0) = bagian yang mengurangi utang. `sale_returns.total` tetap = nilai retur penuh (pengurang omzet dan PPN di `SalesSummary`, tidak berubah). Uang tunai keluar = `total − credit_amount`, jadi `Shift::refundsTotal()` dan `ShiftRecap` menjumlah `total − credit_amount`.
- PIN owner dan shift terbuka tetap wajib (aturan §9.2 tidak dilonggarkan, dan `sale_returns.shift_id` tetap terisi).
- Contoh: kasbon Rp 67.500 + DP Rp 20.000, lalu pelanggan melunasi Rp 50.000 (sisa utang transaksi Rp 17.500), lalu meretur barang senilai Rp 30.000 → utang berkurang Rp 17.500 (lunas), tunai Rp 12.500 dikembalikan dari laci.

**Struk**

- Struk penjualan kasbon: nama pelanggan, baris "Uang muka tunai Rp …" (bila ada) dan **"Kasbon Rp …"**, lalu **"Sisa utang {nama}: Rp …"** = `balance_after` baris ledger transaksi itu (bukan saldo saat dicetak ulang, supaya cetak ulang tetap sama), plus baris tanda tangan pelanggan.
- **Bukti pelunasan** (view Blade baru, ukuran 58/80mm yang sama): nomor `PAY-…`, pelanggan, jumlah, alokasi per `INV-…` (dibayar / sisa), "Sisa utang: Rp …", kasir dan waktu. Dicetak dari dialog sukses pelunasan dan dari kartu piutang.

**Role & PIN**

| Aksi                                           | Kasir                      | Owner | Gudang |
| ---------------------------------------------- | -------------------------- | ----- | ------ |
| Tambah pelanggan (nama + HP, batas bawaan)     | ✅ (dari dialog bayar)     | ✅    | —      |
| Ubah data, batas kredit, nonaktifkan pelanggan | —                          | ✅    | —      |
| Memberi kasbon (dalam batas)                   | ✅ tanpa PIN               | ✅    | —      |
| Menerima pelunasan                             | ✅ (shift sendiri terbuka) | ✅    | —      |
| Lihat daftar pelanggan + saldo (untuk mencari) | ✅                         | ✅    | —      |
| Kartu piutang (riwayat per pelanggan)          | ✅ baca                    | ✅    | —      |
| Hapus (write-off) sisa utang                   | —                          | ✅    | —      |
| Laporan piutang + ekspor                       | —                          | ✅    | —      |

- **Kasbon tidak butuh PIN owner**: batas kredit per pelanggan adalah kontrolnya, dan PIN di setiap kasbon berarti owner harus selalu ada di toko. Retur atas kasbon tetap butuh PIN seperti retur lain.
- **Write-off** (owner saja, alasan wajib): menghapus sisa utang satu transaksi atau seluruh sisa pelanggan (FIFO), tercatat sebagai baris ledger `write_off` + dokumen `customer_write_offs` (siapa, kapan, alasan). Tidak ada "tambah utang manual": utang hanya lahir dari transaksi kasbon.

**Migrasi (expand saja, §9.6)**

- Tabel baru `customers`, `customer_payments`, `customer_write_offs`, `customer_ledger_entries`.
- Kolom baru nullable/ber-default: `sales.customer_id`, `sales.credit_amount`, `sale_returns.credit_amount` (default 0). Tanpa backfill. Kode lama yang masih melayani selama migrasi tidak pernah menulis `credit`, dan untuk retur lama `credit_amount = 0` berarti rumus kas baru = rumus lama.

### 17.3 Tampilan

- **Dialog bayar (F9)**: pilihan keempat **"Kasbon"**. Isi: pencarian pelanggan (nama/HP, hanya yang aktif) dengan baris "Utang Rp … · sisa batas Rp …", tombol "+ Pelanggan baru" (nama, HP), input "Uang muka tunai" (boleh 0), baris "Jadi kasbon Rp …". Tombol "Simpan kasbon" nonaktif bila belum ada pelanggan, uang muka ≥ total, atau melebihi sisa batas (server tetap memeriksa ulang di dalam transaksi).
- **Dialog "Transaksi berhasil"**: "Kasbon Rp … atas nama {pelanggan} · sisa utang Rp …" + tombol Cetak struk.
- **Pelunasan di layar kasir**: tombol header "Pelunasan" (dan route `GET /kasir/pelunasan`): cari pelanggan berutang → daftar transaksi belum lunas (tertua dulu, umur hari) → jumlah (tombol "Lunasi semua") → "Terima Rp …" → dialog sukses + cetak bukti. Header shift menampilkan kas termasuk pelunasan.
- **Riwayat transaksi**: filter cara bayar "Kasbon", kolom pelanggan; detail menampilkan uang muka, kasbon, dan sisa utang transaksi itu sekarang; dialog retur menampilkan "Mengurangi utang Rp … / Dikembalikan tunai Rp …" sebelum PIN.
- **Menu "Pelanggan"** (owner + kasir; gudang tidak): daftar (nama, HP, utang, batas, transaksi tertua belum lunas, status), filter "berutang"/"lewat 30 hari"/nonaktif. **Kartu piutang** `/pelanggan/{customer}`: baris ledger (tanggal, dokumen, keterangan, +/−, saldo), transaksi belum lunas, tombol Ubah/Nonaktifkan/Hapus utang (owner).
- **Laporan → Piutang** (`/laporan/piutang`, owner): per pelanggan per hari ini (WIB): utang total, umur **0–30 / 31–60 / 61–90 / > 90 hari** (dari `completed_at` transaksi, per sisa transaksi), transaksi tertua, pelunasan terakhir; total di bawah. **Ekspor Excel + PDF** lewat `ReportTables::receivables()`, route `reports.export.receivables` / `reports.pdf.receivables` (`/laporan/excel/piutang`, `/laporan/pdf/piutang`), potret.
- **Laporan penjualan**: kolom **Kasbon** di sebelah Tunai dan QRIS (Excel + PDF ikut, 12 kolom; batas PDF jadi 541 baris). Kolom Tunai sudah termasuk uang muka.
- **Rekap kasir & shift**: kolom **Kasbon** (diberikan) dan **Pelunasan** (tunai diterima), Kas seharusnya ikut pelunasan dan refund tunai; Excel/PDF ikut.
- **Dashboard owner**: kartu "Piutang": total utang, jumlah pelanggan berutang, dan bagian yang lewat 30 hari.

### 17.4 Tidak dikerjakan

- Pelunasan lewat QRIS/transfer (alasannya di 17.2; `customer_payments.method` sudah disiapkan), kasbon digabung QRIS dalam satu transaksi. Pelunasan QRIS kemudian dikerjakan di §18.
- Jatuh tempo per transaksi, bunga/denda, pengingat WhatsApp/SMS, cetak rekening koran pelanggan.
- Saldo deposit / kelebihan bayar, harga khusus per pelanggan, poin/loyalti, pelanggan di transaksi non-kasbon.
- Laporan beban piutang tak tertagih per periode (write-off terlihat di kartu piutang dan ledger saja).
- Multi-cabang: pelanggan berlaku untuk satu toko.

### 17.5 Rencana tes (ditulis lebih dulu)

- **Ledger**: `charge`/`settle`/`reverse`/`writeOff` menulis baris immutable dengan `balance_after` benar dan memperbarui `customers.balance`; sisa per transaksi tidak pernah negatif; `receivables:rebuild` cocok setelah skenario campuran dan melaporkan selisih bila cache dirusak.
- **Kasbon**: penuh dan dengan uang muka → sale `completed`, `credit_amount` benar, stok keluar sekali dengan HPP rata-rata, saldo pelanggan naik; melebihi batas, batas 0, pelanggan nonaktif, uang muka ≥ total, tanpa pelanggan → ditolak tanpa sale, movement, maupun baris ledger. Dari keranjang ditahan: keranjang terhapus.
- **Kas & laporan**: `expectedCash` = modal + tunai + uang muka + pelunasan − refund tunai (shift kasir dan penutupan owner §13); `SalesSummary` Tunai + QRIS + Kasbon = Penjualan, laba kotor sama dengan penjualan tunai yang setara; `ShiftRecap` kolom Kasbon/Pelunasan.
- **Pelunasan**: sebagian dan penuh, FIFO lintas tiga transaksi (baris ledger per transaksi), melebihi saldo/0/tanpa shift terbuka ditolak, tunai masuk shift penerima (bukan shift tempat kasbon dibuat).
- **Retur**: retur kasbon belum dibayar → utang berkurang, tunai 0; sudah dibayar sebagian → utang dulu, sisanya tunai (contoh di 17.2); retur penuh mengembalikan utang ke 0 tepat; PIN tetap wajib.
- **Write-off**: owner saja (kasir/gudang 403), alasan wajib, tidak mengubah laba kotor.
- **Akses**: gudang 403 di semua route pelanggan/pelunasan; kasir 403 di ubah/nonaktif/batas/write-off/laporan piutang; kasir boleh tambah pelanggan dengan batas bawaan (batas yang dikirim kasir diabaikan).
- **Struk & bukti pelunasan**: "Kasbon", uang muka, sisa utang dari `balance_after` (cetak ulang setelah pelunasan tetap menampilkan angka saat transaksi); bukti pelunasan berisi alokasi.
- **Laporan piutang + ekspor**: umur 0–30/31–60/61–90/>90 dengan `setTestNow`; Excel dan PDF dari `ReportTables` (isi sama); kolom Kasbon di ekspor penjualan dan rekap shift.
- **Konkurensi (PostgreSQL, `tests/Concurrency`)**: dua kasbon bersamaan untuk pelanggan yang sama yang masing-masing muat tetapi berdua melebihi batas → hanya satu yang tersimpan; write-off owner dan pelunasan bersamaan atas sisa utang terakhir → hanya satu yang mengambilnya (rencana awal dua pelunasan; diganti, lihat §17.6).
- `DemoSalesSeeder` tetap jalan: 5 pelanggan langganan, ±1 dari 25 transaksi kasbon (sebagian dengan uang muka), pelunasan sebagian dan penuh di hari berikutnya, satu retur atas kasbon, satu pelanggan dengan utang > 30 hari; semuanya lewat Action asli, lalu `stock:rebuild` dan `receivables:rebuild` cocok.

### 17.6 Implementasi

- Migrasi `2026_11_27_000001_create_customer_credit_tables` (tabel `customers`, `customer_payments`, `customer_write_offs`, `customer_ledger_entries`; kolom `sales.customer_id`, `sales.credit_amount`, `sale_returns.credit_amount` default 0). Enum `PaymentMethod::Credit` dan `App\Enums\LedgerEntryType`; `PaymentMethod::usesQris()` kini hanya `qris`/`split` (sebelumnya "semua selain tunai") (`PaymentMethod::cashMethods()` sempat dipakai `Shift::cashSalesCount()`; dihapus, lihat §8 "Dua perbaikan dari uji demo live").
- `App\Services\Receivables\CustomerLedger`: `lock()` (wajib di dalam transaksi), `charge()`, `allocate()` (pelunasan dan write-off, FIFO atau satu transaksi), `reverse()` (retur), `owedOn()`, `openSales()`, dan `ensureCanBorrow()` (pesan "Batas kasbon {nama} Rp …, sisa Rp …."). Baris ledger menolak `update`/`delete` di model, seperti `StockMovement`. Perintah `receivables:rebuild [--fix]` (`App\Console\Commands\RebuildReceivables`).
- Kasbon lewat `CompleteSale::handle(..., customer: $customer)`: `paidAmount` menjadi uang muka (0 sampai kurang dari total), pelanggan dikunci setelah shift dan sebelum keranjang ditahan/produk. Checkout HTTP: `payment_method=credit` + `customer_id` di `CheckoutRequest`.
- Action baru di `app/Actions/Customers`: `CreateCustomer` (kasir selalu dapat `pos.credit.default_limit`, env `POS_CREDIT_DEFAULT_LIMIT`), `UpdateCustomer`, `SetCustomerActive`, `ReceiveCustomerPayment` (nomor `PAY-…`), `WriteOffCustomerDebt`; aturan bersama di trait `SavesCustomers` (nomor HP hanya angka dan unik, owner saja untuk ubah). `ProcessSaleReturn` mengunci pelanggan sebelum sale dan mengisi `sale_returns.credit_amount`.
- Kas: `Shift::expectedCash()` = modal + `cashSalesTotal()` + `paymentsTotal()` − `refundsTotal()`; `refundsTotal()` dan `ShiftRecap` memakai `Sql::cashRefund()` (`total − credit_amount`). `Sql::cashPart()` mendapat cabang `credit`; ekspresi baru `Sql::creditPart()`. `SalesSummary` mendapat `credit`; `ShiftRecap` mendapat `creditSales` dan `payments`.
- Laporan `App\Reports\Receivables` (umur per transaksi terbuka, `Receivables::ageInDays()` = hari kalender WIB, juga dipakai halaman Pelanggan dan Pelunasan), `ReportTables::receivables()` (potret, 10 kolom), route `reports.receivables`, `reports.export.receivables`, `reports.pdf.receivables`. Kolom baru di ekspor menggeser batas PDF (§16.2): penjualan 12 kolom → 541 baris, rekap per shift 15 kolom → 433 baris.
- Layar & route: `pos.customers.store` (tambah pelanggan dari dialog bayar), `pos.payments.create|store|receipt` (`/kasir/pelunasan`, bukti `receipts/customer-payment.blade.php`), `customers.*` (`/pelanggan`, `routes/customers.php`: baca untuk kasir + owner, ubah/nonaktif/aktif/hapus utang untuk owner). Halaman `pos/payments`, `customers/index`, `customers/show`, `reports/receivables`; dialog bayar mendapat tab "Kasbon"; struk penjualan menampilkan uang muka, kasbon, pelanggan, sisa utang (`balance_after`) dan baris tanda tangan; kartu "Piutang (kasbon)" di dashboard owner; menu "Pelanggan & kasbon" untuk kasir dan owner.
- Data demo: `DemoSalesSeeder` membuat lima pelanggan lewat `CreateCustomer`, kasbon Pak Darto 40 hari sebelumnya di shift tersendiri (tidak pernah dibayar), ±1 dari 25 transaksi kasbon (sepertiga dengan uang muka), pelunasan di sekitar seperempat hari, dan satu retur kasbon di tengah bulan.
- Tes: `tests/Feature/Customers/{CreditSaleTest,CustomerPaymentTest,CreditReturnTest,CustomerHttpTest}.php`, `tests/Feature/Reports/ReceivablesReportTest.php`, `tests/Concurrency/CustomerCreditRaceTest.php` (+ `tests/Support/concurrent_credit.php`), dan satu tes baru di `DemoSeederTest`. `ExportTest`/`PdfExportTest` disesuaikan untuk kolom Kasbon (baris Excel mendapat satu sel, batas sel uji 22 → 24). Tes kedua di `CustomerCreditRaceTest` memakai write-off melawan pelunasan, bukan dua pelunasan: dua pelunasan sudah diserialkan oleh kunci nomor `PAY-…`, sehingga tes itu tetap hijau walau kunci pelanggan dibuang (dicek dengan membuangnya sementara); versi akhirnya merah tanpa kunci pelanggan.

## 18. v2: Pelunasan kasbon lewat QRIS (2026-11-27)

Tujuan: pelanggan bisa melunasi kasbon, sebagian atau penuh, dengan **QRIS Midtrans**, bukan hanya tunai. Alokasi FIFO, nomor `PAY-…`, bukti pelunasan, dan kartu piutang tetap sama seperti pelunasan tunai (§17.2).

### 18.1 Kondisi sekarang (hasil pemeriksaan)

- **Pelunasan**: `ReceiveCustomerPayment::handle(actor, customer, amount, note)` di satu transaksi: `lockOpenShift` → `CustomerLedger::lock(customer)` → cek `1 ≤ amount ≤ balance` → nomor `PAY-…` (`DocumentNumber`, kunci baris urutan per hari) → `customer_payments` (`method = 'cash'` di-hardcode) → `CustomerLedger::allocate(Payment)` FIFO. Pelunasan langsung selesai; tidak ada status.
- **`customer_payments`**: `number` (unik), `customer_id`, `shift_id` (wajib), `user_id`, `amount`, `method` `varchar(10)` default `cash`, `note`, timestamps. Tidak ada kolom status, referensi Midtrans, atau QR.
- **Pembaca `customer_payments`** yang menjumlah semua baris tanpa melihat `method`: `Shift::paymentsTotal()` (masuk `expectedCash()`), `ShiftRecap` (kolom Pelunasan per shift dan per kasir → `ReportTables` Excel/PDF), `Receivables` (Pelunasan terakhir = `MAX(created_at)`), `PosController::shiftSummary()` (`paymentsCount`/`paymentsTotal` di header kasir, halaman Pelunasan, dan kedua halaman Tutup shift).
- **Mesin status QRIS** (`QrisPayments`) melekat ke `Sale`: `settle()` mengunci sale, memeriksa `payment_method->usesQris()` dan status, lalu **mengeluarkan stok** (`BooksSaleStock`) dan menangani `InsufficientStockException`; `cancel()`, `refresh()` (status ke Midtrans + `payment_checked_at`), `cancelByCashier()` (refresh dulu, lalu cancel ke Midtrans, lalu cancel lokal). `apply()` memetakan `transaction_status` Midtrans ke settle/cancel/abaikan.
- **Webhook** `MidtransWebhookController`: verifikasi signature (`MidtransClient::signatureMatches`), log setiap notifikasi ke `payment_notifications`, lalu mencari `Sale` dengan `number = order_id` dan `payment_method ∈ {qris, split}`; tidak ketemu → `unknown_order` (200); `gross_amount ≠ qrisPart()` → `amount_mismatch` (200).
- **Polling**: `QrisController::show` (hanya pemilik sale, `refresh()` paling sering tiap 3 detik lewat `payment_checked_at`); `qris-dialog.tsx` memanggil `pos.qris.show` / `pos.qris.cancel` secara langsung dan memakai tipe `PendingQris` dari props layar kasir.
- **Tutup shift**: `CloseShift` menolak bila ada sale `pending_payment` di shift itu; `CloseShiftForUser` (§13) memanggil `cancelByCashier()` untuk setiap sale menunggu di luar transaksi, lalu di dalam transaksi membatalkan sisanya secara lokal.
- **Data demo**: `DemoSalesSeeder` menulis sale QRIS/campuran langsung sebagai lunas (`forceFill`), tanpa Midtrans; pelunasan lewat `ReceiveCustomerPayment` asli.

### 18.2 Keputusan (disetujui 2026-11-27)

**Mesin status: mesin kedua khusus pelunasan, bukan generalisasi `QrisPayments`**

- Kelas baru `App\Actions\Customers\QrisCustomerPayments` (`apply`, `settle`, `cancel`, `refresh`, `cancelByCashier`) dan `StartQrisCustomerPayment`, dengan bentuk yang sama seperti versi sale:

    ```
    pending_payment ──settlement──▶ completed   (utang berkurang di sini, tepat sekali)
    pending_payment ──expire/cancel/deny/failure──▶ cancelled
    cancelled ──settlement──▶ completed          (hanya bila masih muat di utang, lihat di bawah)
    ```

- **Alasan**: `QrisPayments::settle()` membawa stok, `usesQris()`, bagian tunai campuran, dan penanganan stok kurang; alur ini sudah terbukti di sandbox dan demo live (webhook, polling, kedaluwarsa 407, tutup shift owner). Menggeneralisasikannya (antarmuka "dokumen yang bisa dibayar QRIS" untuk `Sale` dan `CustomerPayment`) berarti mengubah setiap jalur QRIS penjualan untuk fitur yang tidak menyentuh stok; kesalahan di sana mengeluarkan stok dua kali atau tidak sama sekali. Mesin kedua ±120 baris, terisolasi, dengan tesnya sendiri.
- **Yang dipakai bersama** (satu sumber, bukan salinan): `MidtransClient` (charge, status, cancel, signature), dan pemetaan status Midtrans → hasil. Pemetaan `match` di `QrisPayments::apply()` dipindah ke satu fungsi kecil (mis. `MidtransClient::outcome(transactionStatus, fraudStatus)` → `settle`/`cancel`/`none`) yang dipakai kedua mesin. Ini satu-satunya perubahan di `QrisPayments`, perilakunya sama, dan `QrisPaymentTest` yang sudah ada menjadi penjaganya.
- `StartQrisPayment`, `QrisController`, dan `CloseShift`/`CloseShiftForUser` untuk sale **tidak diubah** selain tambahan pemeriksaan pelunasan di penutupan shift (lihat "Tutup shift").

**Kapan utang berkurang: saat settlement**

- Seperti stok yang baru keluar saat lunas (§9.3): saat QR dibuat hanya ada baris `customer_payments` berstatus `pending_payment` **tanpa baris ledger**. `customers.balance`, kartu piutang, umur piutang, dan `receivables:rebuild` tidak berubah sampai Midtrans melaporkan settlement. Yang ditolak saat QR dibuat: jumlah < 1, jumlah > utang, tidak ada utang, tidak ada shift terbuka, Midtrans belum dikonfigurasi.
- **Menjaga jumlah QR tidak melebihi utang selama QR menunggu**: satu pelanggan hanya boleh punya **satu QRIS pelunasan menunggu**, dan selama itu semua yang bisa mengurangi utang pelanggan itu **ditolak** dengan pesan "Masih ada QRIS pelunasan PAY-… untuk {nama} yang menunggu pembayaran. Selesaikan atau batalkan dulu.":
    - pelunasan tunai dan QRIS pelunasan kedua (siapa pun kasirnya),
    - write-off oleh owner,
    - retur atas transaksi kasbon pelanggan itu (`ProcessSaleReturn` yang memanggil `CustomerLedger::reverse()`; retur atas transaksi non-kasbon tidak terpengaruh).
      Kasbon baru (menambah utang) tetap boleh. Semua pemeriksaan ini dilakukan **di bawah kunci baris pelanggan** (`CustomerLedger::lock()`), dan `StartQrisCustomerPayment` membuat baris menunggu juga di bawah kunci itu, jadi tidak ada celah antara "cek" dan "buat".
    - Ditimbang: "reservasi" (utang yang bisa dibayar = saldo − QR menunggu). Ditolak karena retur dan write-off memakai sisa per transaksi (FIFO, `owedOn()`), sehingga reservasi harus dialokasikan per transaksi juga; aturan "satu menunggu, yang lain menunggu giliran" lebih sederhana dan cukup untuk satu toko (QR kedaluwarsa dalam 15 menit).
- **QR menunggu yang terlupa tidak mengunci pelanggan selamanya**: sebelum menolak karena QR menunggu, aksi-aksi di atas (di luar transaksi) memanggil `refresh()` untuk QR pelanggan itu yang sudah lewat `payment_expires_at`, jadi QR kedaluwarsa dibatalkan dulu walau tab kasirnya sudah ditutup dan webhook tidak sampai.
- **Saat settlement**: transaksi mengunci **pelanggan → baris pembayaran**, memeriksa status (idempoten), lalu `CustomerLedger::allocate(Payment)` FIFO seperti pelunasan tunai, `status = completed`, `completed_at = now()`. Shift tidak dikunci: QRIS tidak mengubah kas laci, jadi settlement setelah shift ditutup tidak mengubah `expected_cash` yang tersimpan.

**Nomor order, signature, idempotensi, amount_mismatch**

- **Nomor `PAY-…` yang sama** (urutan yang sama dengan pelunasan tunai), diberikan saat QR dibuat dan dipakai sebagai `order_id` Midtrans. Satu jenis dokumen dengan dua cara bayar, sama seperti INV untuk tunai/QRIS. Pelunasan QRIS yang batal tetap tersimpan sebagai baris `cancelled` dengan nomornya, jadi tidak ada nomor yang hilang tanpa jejak. Bukan `INV-…`: INV adalah penjualan, dan webhook memakai awalan untuk memilih mesin status.
- **Bentrok nomor 409** dengan demo live di akun sandbox yang sama (§8) diperlakukan seperti sale: charge gagal → baris `cancelled`, pesan Midtrans tampil di halaman, kasir mencoba lagi (nomor berikutnya).
- **Webhook**: satu URL yang sama. Setelah signature valid, `order_id` berawalan `PAY-` → cari `CustomerPayment` dengan `method = 'qris'`; selain itu → jalur `Sale` yang sudah ada, tidak berubah. Log `payment_notifications` sama untuk keduanya (`unknown_order`, `amount_mismatch`, `settled`, `cancelled`, `unchanged`, plus hasil baru `over_balance`).
- **Idempotensi**: baris pembayaran dikunci `FOR UPDATE` lalu statusnya diperiksa; notifikasi ganda, webhook + polling bersamaan, atau settlement atas baris yang sudah `completed` tidak menulis ledger lagi.
- **amount_mismatch**: `gross_amount` (dibulatkan ke rupiah) harus sama dengan `customer_payments.amount`; tidak sama → diabaikan, 200, dicatat.

**Kas laci, rekap, ekspor**

- `Shift::paymentsTotal()` dipecah: **`cashPaymentsTotal()`** (method `cash`, status `completed`) masuk `expectedCash()`; **`qrisPaymentsTotal()`** (method `qris`, status `completed`) hanya ditampilkan. Semua pembaca `customer_payments` memakai scope `CustomerPayment::received()` (status `completed`) supaya baris menunggu/batal tidak pernah terhitung; `Receivables` memakai waktu diterima (`completed_at`, atau `created_at` untuk baris tunai lama).
- **Rekap shift** (`ShiftRecap`): kolom "Pelunasan" menjadi **"Pelunasan tunai"** dan ada kolom baru **"Pelunasan QRIS"**, per shift dan per kasir. Kas seharusnya hanya ikut yang tunai. Ekspor Excel/PDF (`ReportTables`) ikut: rekap per shift 15 → 16 kolom (batas PDF 6.500 sel → 406 baris), per kasir 9 → 10 kolom. Ringkasan penjualan dan laba kotor tidak berubah (pelunasan bukan penjualan, §17.2).
- **Halaman Tutup shift** (kasir dan owner §13): baris "Pelunasan kasbon (N)" di hitungan kas menjadi "Pelunasan kasbon tunai (N)", dan di bawah hitungan ada baris informasi "Pelunasan QRIS (N) Rp … — tidak masuk laci". Header kasir dan halaman Pelunasan menampilkan angka tunai saja dalam "kas …".

**Pelunasan campuran tunai + QRIS dalam satu pembayaran: tidak**

- Satu pembayaran = satu cara bayar. Pelanggan yang ingin membayar sebagian tunai dan sebagian QRIS membuat **dua pelunasan**: tunai lebih dulu (selesai seketika), lalu QRIS untuk sisanya. Urutan sebaliknya ditolak sementara QR menunggu (aturan di atas), dan halaman memberi petunjuk "Terima tunai dulu, lalu QRIS untuk sisanya".
- Alasannya: campuran di §11 butuh `split_cash_amount`, pesan "kembalikan uang tunai" saat QR batal, dan risiko lunas-terlambat setelah uang dikembalikan. Untuk pelunasan, dua dokumen berurutan memberi hasil yang sama tanpa semua itu, dan masing-masing punya bukti sendiri.

**Tutup shift, batal, kedaluwarsa, lunas terlambat**

- **Kasir menutup shift sendiri**: ditolak bila ada QRIS pelunasan menunggu di shift itu ("Masih ada QRIS pelunasan yang menunggu pembayaran…"), sama dengan sale. Pemeriksaannya di bawah kunci shift, dan `StartQrisCustomerPayment` mengunci shift lebih dulu, jadi tidak ada QR baru yang lolos saat shift ditutup.
- **Owner menutup shift (§13)**: QRIS pelunasan menunggu di shift itu dibereskan seperti QRIS sale: `QrisCustomerPayments::cancelByCashier()` untuk masing-masing di luar transaksi (yang ternyata lunas jadi `completed` dan utang berkurang; sisanya dibatalkan di Midtrans), lalu di dalam transaksi penutupan baris yang masih `pending_payment` dibatalkan lokal. Halaman tutup owner menampilkan daftarnya lebih dulu ("QRIS pelunasan menunggu: PAY-… Bu Sari Rp …"). Tidak ada uang tunai yang perlu dikembalikan (tidak ada campuran).
- **Batal oleh kasir**: tombol "Batalkan QRIS" di dialog QR halaman Pelunasan → `cancelByCashier()` (cek ke Midtrans dulu; kalau ternyata sudah dibayar, selesai sebagai lunas). Hanya pengguna yang membuat QR itu; owner lewat penutupan shift.
- **Kedaluwarsa**: polling (termasuk jawaban 407 `expire`, §8) dan webhook `expire` → `cancelled`.
- **Lunas terlambat setelah dibatalkan** (`cancelled → completed`, §9.3): uangnya nyata, jadi dibukukan bila **masih muat** di utang pelanggan saat itu (dikunci, FIFO). Bila tidak muat (setelah batal, pelanggan sudah melunasi tunai atau owner menghapus utangnya), baris tetap `cancelled`, hasil `over_balance` dicatat di `payment_notifications` dan `Log::error`, dan owner mengembalikan dananya lewat dashboard Midtrans. Ini meniru `STOCK_SHORTAGE` di §9.3: kasusnya hanya mungkin bila pembatalan ke Midtrans gagal (Midtrans tidak terjangkau) dan pelanggan tetap membayar QR yang sudah tidak tampil; risiko ini diterima dan dicatat, bukan disembunyikan. Tidak ada saldo deposit (§17.4).

**Bukti pelunasan, kartu piutang, laporan**

- **Bukti pelunasan**: baris "Cara bayar: Tunai / QRIS"; untuk QRIS juga "Ref. Midtrans {transaction_id}". Hanya pembayaran `completed` yang punya bukti (`pos.payments.receipt` 404 untuk yang menunggu/batal).
- **Kartu piutang**: baris ledger pelunasan menampilkan "PAY-… · QRIS" (atau "· Tunai"); bagian kecil "QRIS pelunasan menunggu" bila ada, supaya kasir di terminal lain tahu kenapa pelunasan ditolak. Baris batal tidak tampil di kartu (tidak punya ledger).
- **Laporan piutang**: tidak berubah selain "Pelunasan terakhir" yang hanya menghitung pembayaran diterima.

**Skema (migrasi expand saja, §9.6)**

- Kolom baru di `customer_payments`: `status` (`varchar(20)`, default `completed`), `payment_reference` (64, nullable; `transaction_id` Midtrans), `qr_string` (text, nullable), `qr_url` (500, nullable), `payment_expires_at`, `payment_checked_at`, `completed_at` (timestamp, nullable), indeks `(customer_id, status)` dan `(shift_id, status)`. `method` (`varchar(10)`) sudah ada; nilai baru `qris`. Status memakai enum yang sudah ada `SaleStatus` (`pending_payment`/`completed`/`cancelled`) atau enum kecil baru dengan nilai yang sama, diputuskan saat implementasi.
- Tanpa backfill. Baris lama otomatis `completed` lewat default. Kode lama yang masih melayani selama migrasi hanya menulis baris tunai, yang mendapat default yang benar (`completed`, `cash`), dan tidak pernah melihat baris `qris`.

**Data demo**

- `DemoSalesSeeder`: sekitar sepertiga pelunasan dibuat lewat QRIS dan langsung lunas, lewat **Action asli** (`StartQrisCustomerPayment` lalu `QrisCustomerPayments::settle()`), dengan `Http::fake()` untuk endpoint charge Midtrans yang dipasang hanya selama pelunasan itu dibuat (dan server key sementara di config), karena `MidtransClient` adalah kelas `final` dan seeder tidak boleh memanggil sandbox sungguhan. Ledger dan `receivables:rebuild` tetap cocok.

### 18.3 Tampilan

- **Halaman Pelunasan** (`/kasir/pelunasan`): di form pelanggan, dua tombol: **"Terima tunai Rp …"** (seperti sekarang) dan **"QRIS Rp …"**. QRIS membuka dialog QR yang sama gayanya dengan layar kasir: nominal, nomor `PAY-…`, nama pelanggan, hitung mundur, tombol "Batalkan QRIS", polling 3 detik. Lunas → dialog sukses yang sudah ada ("PAY-… · sisa utang Rp …" + Cetak bukti) dengan tambahan "QRIS". Kedaluwarsa/batal → pesan di dialog, jumlah tetap terisi.
- `qris-dialog.tsx` dibuat menerima URL status dan batal (serta judul/keterangan) lewat props, supaya satu komponen melayani sale dan pelunasan; pemanggil di layar kasir mengirim URL `pos.qris.*` yang sama seperti sekarang. Kalau perubahan itu ternyata menyentuh lebih dari props, pelunasan mendapat komponen sendiri dan dialog sale dibiarkan.
- QR menunggu milik kasir itu muncul lagi saat halaman Pelunasan dibuka ulang (prop `pendingPayment`, seperti `pendingQris` di layar kasir). Satu QRIS pelunasan menunggu per shift (tidak bergantung pada QRIS sale yang menunggu, supaya `StartQrisPayment` tidak berubah).
- Pelanggan yang punya QR menunggu: tombol tunai dan QRIS nonaktif dengan keterangan "QRIS PAY-… menunggu pembayaran" (server tetap menolak).
- Route baru (grup kasir yang sama dengan `pos.payments.*`): `POST /kasir/pelunasan/qris` (`pos.payments.qris.store`), `GET /kasir/pelunasan/{payment}/qris` (`…qris.show`, polling JSON), `POST /kasir/pelunasan/{payment}/qris/batal` (`…qris.cancel`).

### 18.4 Tidak dikerjakan

- Pelunasan campuran tunai + QRIS dalam satu dokumen (lihat 18.2), transfer bank / virtual account / e-wallet selain QRIS.
- Refund otomatis lewat Midtrans untuk lunas terlambat yang melebihi utang (`over_balance`), dan halaman owner untuk notifikasi Midtrans; keduanya tetap lewat dashboard Midtrans dan log.
- Saldo deposit dari kelebihan bayar (tetap di §17.4).
- Menggabungkan QRIS sale dan QRIS pelunasan menjadi satu mesin status (bisa dilakukan kemudian bila ada dokumen ketiga yang dibayar lewat QRIS).

### 18.5 Rencana tes (ditulis lebih dulu)

Fake Midtrans memakai kode status asli (charge `201`, status `pending` `201`, `settlement` `200`, `expire` `407`, order tidak ada `404`, bentrok `409`), seperti `QrisPaymentTest` sejak entri "QRIS kedaluwarsa lewat polling" di §8.

- **Mulai**: baris `pending_payment` bernomor `PAY-…`, `method = qris`, referensi + QR + kedaluwarsa tersimpan, Midtrans di-charge sebesar jumlahnya; **tanpa** baris ledger dan saldo pelanggan tetap. Ditolak tanpa baris baru: jumlah 0 / > utang, tanpa utang, tanpa shift terbuka, Midtrans belum dikonfigurasi. Charge gagal/409 → baris `cancelled`, pesan Midtrans tampil.
- **Satu menunggu per pelanggan**: QRIS kedua, pelunasan tunai, write-off, dan retur atas kasbon pelanggan itu ditolak dengan pesannya; kasbon baru tetap boleh; retur transaksi non-kasbon tetap boleh. QR menunggu yang sudah lewat kedaluwarsa dibatalkan lewat `refresh()` lalu aksi berikutnya berhasil.
- **Lunas lewat polling dan webhook**: ledger FIFO lintas beberapa transaksi kasbon, saldo turun, `completed_at` terisi; notifikasi kedua / polling setelah webhook tidak menulis ledger lagi; `receivables:rebuild` cocok.
- **Webhook**: signature salah → 403; `PAY-` yang tidak ada → `unknown_order`; `gross_amount` beda → `amount_mismatch` tanpa ledger; notifikasi `INV-…` tetap ke jalur sale (tes webhook sale yang ada tetap hijau).
- **Batal / kedaluwarsa**: tombol batal (cek Midtrans dulu: yang ternyata lunas jadi `completed`), polling `407 expire`, webhook `expire` → `cancelled`, tanpa ledger. Hanya pembuatnya yang bisa polling/membatalkan (403 untuk kasir lain).
- **Lunas terlambat**: `cancelled → completed` bila masih muat; tidak muat → tetap `cancelled`, hasil `over_balance`, tanpa ledger.
- **Kas & laporan**: `expectedCash()` tidak ikut pelunasan QRIS (dan tidak ikut baris menunggu/batal); `ShiftRecap` Pelunasan tunai / Pelunasan QRIS per shift dan per kasir; ekspor Excel/PDF dengan kolom baru dan batas PDF rekap shift 406 baris; laporan piutang "Pelunasan terakhir" mengabaikan baris menunggu.
- **Tutup shift**: kasir ditolak selama ada QRIS pelunasan menunggu; owner (§13) membereskannya (lunas → utang turun; belum dibayar → dibatalkan di Midtrans; Midtrans gagal → `cancelled` lokal).
- **Bukti & kartu piutang**: bukti QRIS memuat cara bayar dan referensi Midtrans; 404 untuk pembayaran menunggu/batal; kartu piutang menandai baris QRIS dan menampilkan QR menunggu.
- **Konkurensi (PostgreSQL, `tests/Concurrency`)**: `StartQrisCustomerPayment` dan write-off owner atas seluruh utang pelanggan yang sama berjalan bersamaan → hanya satu yang berhasil (tidak pernah QR menunggu sementara utangnya sudah dihapus). Write-off tidak memakai kunci nomor `PAY-…`, jadi tes ini merah bila kunci pelanggan di `StartQrisCustomerPayment` dibuang (dicek dengan membuangnya sementara, pelajaran dari §17.6).
- **`QrisPaymentTest` dan tes webhook sale** tetap hijau tanpa diubah setelah pemetaan status dipindah.
- `DemoSalesSeeder` tetap jalan: ada pelunasan QRIS lunas, `stock:rebuild` dan `receivables:rebuild` cocok.

### 18.6 Implementasi

- Migrasi `2026_11_27_000002_add_qris_to_customer_payments` (kolom di 18.2, default `status = completed`). Status memakai enum baru `App\Enums\CustomerPaymentStatus` (nilai sama dengan `SaleStatus` tanpa `held`), bukan `SaleStatus`, karena pembayaran tidak pernah ditahan. `CustomerPayment::CASH`/`QRIS`, scope `received()` dan `waiting()`, `receivedAt()` (`completed_at` atau `created_at` untuk baris tunai lama).
- Pemetaan status Midtrans: `MidtransClient::outcome()`, dipakai `QrisPayments::apply()` (satu-satunya perubahan di mesin sale) dan `QrisCustomerPayments::apply()`.
- `App\Actions\Customers\QrisCustomerPayments` (`apply`, `settle`, `cancel`, `refresh`, `cancelByCashier`, `clearExpired`, `ensureNoneWaiting`) dan `StartQrisCustomerPayment`. Pemeriksaan jumlah dipakai bersama dengan pelunasan tunai (`ReceiveCustomerPayment::ensurePositive()` / `ensurePayable()`). Penjaga "satu QR menunggu per pelanggan" dipanggil di `ReceiveCustomerPayment`, `StartQrisCustomerPayment`, `WriteOffCustomerDebt`, dan `ProcessSaleReturn` (hanya untuk transaksi kasbon), semuanya setelah `CustomerLedger::lock()`; `clearExpired()` dipanggil sebelum transaksinya. Satu QR pelunasan menunggu per shift juga ditolak, terpisah dari QRIS sale.
- Webhook: `order_id` berawalan `PAY-` → `CustomerPayment` (`method = qris`), selain itu jalur sale apa adanya. Hasil baru `over_balance` di `payment_notifications`.
- Kas: `Shift::cashPaymentsTotal()` (masuk `expectedCash()`) dan `qrisPaymentsTotal()`, keduanya lewat `receivedPayments()`; `shiftSummary` mendapat `qrisPaymentsCount`/`qrisPaymentsTotal`; `ShiftRecap` mendapat `qrisPayments` (per shift dan per kasir), `payments` kini berarti pelunasan tunai saja; `Receivables` "Pelunasan terakhir" hanya pembayaran diterima. Ekspor rekap: "Pelunasan tunai" + "Pelunasan QRIS" (per kasir 10 kolom, per shift 16 kolom → batas PDF 406 baris).
- `CloseShift` menolak QR pelunasan menunggu; `CloseShiftForUser` membereskannya seperti QRIS sale; halaman tutup owner mendapat prop `pendingPayments`.
- Route `pos.payments.qris.store|show|cancel` (`CustomerPaymentQrisController`); setelah membuat atau membatalkan QR, halaman kembali ke `/kasir/pelunasan?pelanggan={id}` supaya pelanggan tetap terpilih. Halaman Pelunasan: tombol "Terima tunai" dan "QRIS", prop `pendingPayment`, `qrisEnabled`, `debtors[].waitingQris`. `qris-dialog.tsx` kini menerima `statusUrl`/`cancelUrl`/`reloadOnly`/`note`/`cancelledNote` lewat props dan dipakai layar kasir maupun halaman Pelunasan (teks layar kasir dipindah ke pemanggilnya, tidak berubah). Bukti pelunasan: "Cara bayar" + "Ref." Midtrans, 404 untuk pembayaran yang belum/tidak diterima; kartu piutang: "QRIS · / Tunai ·" per baris pelunasan dan pemberitahuan QR menunggu (`waitingQris`); halaman Rekap kasir & shift mendapat kolom "Pelunasan QRIS".
- Data demo: `DemoSalesSeeder::payByQris()` (±sepertiga pelunasan) memasang `Http\Client\Factory` sendiri dengan `preventStrayRequests()` dan stub charge saja, lalu mengembalikan factory dan server key semula.
- Tes: `tests/Feature/Customers/QrisCustomerPaymentTest.php` (25 tes, semuanya merah sebelum implementasi), `tests/Concurrency/QrisCustomerPaymentRaceTest.php` (+ `tests/Support/concurrent_qris_payment.php`; merah bila `CustomerLedger::lock()` di `StartQrisCustomerPayment` diganti pembacaan biasa, dicek sementara), satu tes baru di `DemoSeederTest` (ditulis setelah perubahan seeder, bukan lebih dulu), dan `ReceivablesReportTest` disesuaikan untuk label kolom baru.

## 19. v2: Batch & tanggal kedaluwarsa (2026-11-27)

Tujuan: toko bisa mencatat **tanggal kedaluwarsa per batch** untuk produk yang membutuhkannya (susu, roti, obat, minuman), melihat barang yang hampir atau sudah kedaluwarsa, dan stok keluar dengan urutan **FEFO** (kedaluwarsa paling awal keluar lebih dulu). Produk tanpa pelacakan berperilaku persis seperti sekarang.

Pilihan diberi kode **P1–P10**; yang dipilih ditulis lebih dulu, alternatif yang ditolak di bawahnya.

### 19.1 Kondisi sekarang (hasil pemeriksaan)

- **`StockLedger`** punya tiga pintu: `receive()` (purchase / sale_return, menghitung ulang rata-rata), `issue()` (keluar dengan HPP rata-rata), `adjustTo()` (opname ke hitungan). Ketiganya lewat `apply()`: kunci baris `products` `FOR UPDATE` → cek stok tidak negatif → tulis cache `stock_on_hand`/`avg_cost` (nilai hasil hitung, bukan `qty - n`) → satu baris `stock_movements`. Tidak ada konsep batch atau tanggal di mana pun.
- **`stock_movements`**: `product_id`, `type` (`varchar(20)`), `qty` bertanda, `unit_cost`, `stock_after`, `avg_cost_after`, `reference` (morph: GR, Sale, SaleReturn, StockOpname), `user_id`, `note`, `created_at`. `StockMovementType::Adjustment` ("Penyesuaian") ada di enum tetapi **tidak dipakai** kode mana pun.
- **Pemanggil**: `ReceiveGoods` (GR langsung, dan dari PO lewat `ReceivePurchaseOrder`; satu movement per baris GR, urut `product_id`), `BooksSaleStock` (dipakai `CompleteSale` dan `QrisPayments::settle()`; satu `issue()` per baris sale, `sale_items.unit_cost` diambil dari movement itu), `ProcessSaleReturn` (satu `receive(sale_return)` per baris retur dengan HPP baris asli), `PostStockOpname` (`adjustTo()` per produk; `stock_opname_items` unik per `(stock_opname_id, product_id)`). `CatalogSeeder` (stok awal) dan `DemoSalesSeeder` lewat `ReceiveGoods`/`ReceivePurchaseOrder` asli.
- **Pemeriksaan stok di luar ledger**: `StartQrisPayment::ensureStock()` membandingkan `stock_on_hand` sebelum membuat QR; katalog layar kasir (`PosController::catalog()`) mengirim `stock` = `stock_on_hand`.
- **`stock:rebuild`** memutar ulang movement per produk (qty, type, unit_cost) dan membandingkan dengan cache; mengabaikan `stock_after`/`avg_cost_after`.
- **Tampilan stok**: kartu stok `/produk/{product}/kartu-stok` (`StockCardController`), kartu dashboard "Stok menipis" (`LowStock`) dan "PO belum selesai" untuk owner + gudang, laporan nilai persediaan (`InventoryValuation`: `stock_on_hand × avg_cost`). Laporan (`routes/reports.php`) khusus owner; ekspor lewat `ReportTables` → Excel/PDF.
- **Urutan kunci** saat ini: shift → pelanggan → sale → produk (urut `product_id`).

### 19.2 Keputusan (disetujui 2026-11-27, semua rekomendasi P1–P10)

**P1. Model data: `stock_movements.stock_batch_id`, satu movement per batch** (rekomendasi)

- Tabel baru `stock_batches`: `product_id` (`restrictOnDelete`), `code` (`varchar(50)`, nullable, kode batch/lot dari kemasan), `expires_on` (`date`, nullable = batch **tanpa tanggal**), `qty_on_hand` (`bigint`, **cache** sisa dalam satuan dasar), timestamps; indeks `(product_id, expires_on)`.
- Kolom baru nullable `stock_movements.stock_batch_id` (FK, `restrictOnDelete`, berindeks). Untuk produk berflag **setiap** movement menyebut batch-nya; satu baris penjualan yang mengambil dari dua batch menjadi **dua movement** (`type = sale`, referensi sale yang sama, HPP rata-rata yang sama). Produk tanpa flag: `stock_batch_id = null`, persis seperti sekarang.
- `StockLedger` tetap **satu-satunya pengubah stok**: ia memilih batch (FEFO), menulis movement, dan memperbarui `stock_batches.qty_on_hand` + cache produk di transaksi yang sama. Tidak ada kode lain yang menulis `qty_on_hand`.
- **Sisa per batch disimpan sebagai cache** (seperti `products.stock_on_hand`), bukan diturunkan dari SUM movement setiap kali: FEFO, kartu dashboard, dan laporan butuh "batch dengan sisa > 0 urut tanggal" yang cepat. Cache ini dibuktikan oleh `stock:rebuild` (19.2 P8).
- Alternatif ditolak: (a) tabel alokasi terpisah `stock_movement_batches` di bawah satu movement per baris dokumen. Membuat koreksi antar-batch tanpa selisih produk (opname yang memindahkan stok tanpa tanggal ke batch bertanggal) butuh movement `qty = 0` atau baris alokasi tanpa movement; dengan P1, koreksi itu cukup dua movement biasa (−x batch lama, +x batch baru). (b) Sisa batch diturunkan dari SUM movement: tidak ada yang bisa dicache untuk dashboard, dan pembacaan FEFO menjadi agregat di setiap penjualan.
- Konsekuensi kecil: GR atau retur yang dipecah ke dua batch menghasilkan dua movement `purchase`/`sale_return`, jadi rata-rata dihitung ulang dua kali dengan harga yang sama. Hasilnya bisa berbeda di digit desimal ke-4 dibanding satu baris; replay memutar baris yang sama, jadi `stock:rebuild` tetap identik.

**P2. Flag per produk** (rekomendasi: flag + batch "tanpa tanggal" untuk stok lama)

- Kolom baru `products.tracks_expiry` (boolean, default `false`), diubah di form produk (owner + gudang) dengan keterangan "Lacak tanggal kedaluwarsa".
- **Menyalakan** dengan stok S > 0: di transaksi yang sama, `StockLedger::startTracking()` membuat satu batch **tanpa tanggal** dan menulis pasangan movement `adjustment` ("Penyesuaian", note "Mulai lacak kedaluwarsa"): −S tanpa batch, +S ke batch tanpa tanggal. Stok dan HPP tidak berubah (keduanya pada rata-rata sekarang; `adjustment` tidak menghitung ulang rata-rata). Kartu stok memperlihatkannya, dan stok lama bisa diberi tanggal lewat opname (P6).
- **Mematikan**: pasangan kebalikannya (semua sisa batch → tanpa batch), batch tetap ada sebagai riwayat dengan sisa 0. Dengan begitu salah centang bisa dibatalkan tanpa opname.
- Alternatif ditolak: menolak flag selama stok > 0 (memaksa opname ke 0 dulu, tidak realistis di toko yang buka), atau membagi stok lama ke batch "dianggap kedaluwarsa hari X" (tebakan yang menyesatkan kartu dashboard).

**P3. HPP tetap moving average per produk** (rekomendasi)

- §4 tidak berubah: batch tidak punya HPP sendiri. FEFO hanya mengatur **batch mana yang keluar secara fisik**; nilainya selalu `avg_cost` produk saat itu.
- Dampak: laba kotor (§9.1) tidak berubah sama sekali (`sale_items.unit_cost` tetap rata-rata). Nilai persediaan tetap `stock_on_hand × avg_cost`, termasuk barang yang sudah kedaluwarsa tetapi belum dibuang; laporan kedaluwarsa (19.3) menampilkan nilai itu terpisah supaya owner melihat berapa yang akan jadi kerugian. Barang yang dibuang (P6) keluar dengan HPP rata-rata, seperti selisih opname sekarang, dan tidak masuk laba kotor.
- Alternatif ditolak: HPP per batch (identifikasi khusus / FIFO harga). Mengubah §4, `reweigh`, `stock:rebuild`, retur, dan semua laporan; untuk toko kecil selisihnya kecil karena harga beli antar batch jarang berbeda jauh.

**P4. Barang masuk** (rekomendasi: tanggal wajib, satu baris GR boleh dipecah)

- GR langsung dan penerimaan dari PO (§10): untuk produk berflag, setiap baris **wajib** punya minimal satu rincian `(tanggal kedaluwarsa, kode batch opsional, qty dalam satuan baris)`; jumlah qty rincian = qty baris. Satu baris "2 dus" boleh dipecah jadi 1 dus exp 10 Des + 1 dus exp 24 Des. Produk tanpa flag tidak punya rincian (form sama seperti sekarang).
- Batch **digabung** bila produk, tanggal, dan kode sama (di bawah kunci produk), jadi dua GR dengan tanggal yang sama menambah batch yang sama; movement tetap menunjukkan GR asalnya. Tanggal yang sudah lewat ditolak saat penerimaan ("Tanggal kedaluwarsa sudah lewat").
- Tidak ada tabel rincian GR: rincian hanya input untuk membuat movement, dan detail GR menampilkan batch per produk dari movement-nya (satu sumber).
- `ReceiveGoods::handle()` mendapat kunci opsional `batches` per baris; pemanggil lama untuk produk tanpa flag tidak berubah. `CatalogSeeder` mengisi tanggal untuk produk berflag.

**P5. Barang keluar: FEFO, batch kedaluwarsa tidak boleh dijual** (rekomendasi: ditolak)

- `StockLedger::issue()` untuk produk berflag mengambil batch dengan sisa > 0 dan **belum kedaluwarsa** (`expires_on ≥ hari ini` WIB; barang masih boleh dijual pada tanggal yang tertera), urut: **batch tanpa tanggal dulu** (stok lama yang sudah ada sebelum pelacakan), lalu `expires_on`, lalu `id`. Satu baris penjualan bisa menjadi beberapa movement; `BooksSaleStock` menjumlah HPP-nya.
- Bila stok yang belum kedaluwarsa tidak cukup, penjualan **ditolak** di `CompleteSale` dengan pesan "Stok Susu UHT yang belum kedaluwarsa tinggal 3 pcs (5 pcs sudah kedaluwarsa)." — pengecualian turunan `InsufficientStockException`, jadi penanganan yang ada tetap berlaku. `StartQrisPayment::ensureStock()` memakai stok jual yang sama sebelum membuat QR, dan katalog layar kasir mengirim stok jual (`stock` = sisa batch belum kedaluwarsa) untuk produk berflag.
- **QRIS** (§9.3): stok keluar saat settlement seperti sekarang; acuan "hari ini" untuk kedaluwarsa = tanggal QR dibuat, supaya QR yang dibuat 23.55 dan dibayar 00.05 tidak gagal karena batch-nya lewat tengah malam. Kekurangan stok tetap jalur `STOCK_SHORTAGE` yang sudah ada.
- Alasan menolak: UU Perlindungan Konsumen melarang memperdagangkan barang kedaluwarsa, dan barang yang dipegang kasir tetapi tercatat kedaluwarsa berarti barangnya memang kedaluwarsa atau datanya salah (dibetulkan lewat opname). Alternatif: (b) peringatan lalu tetap dijual dari batch kedaluwarsa setelah yang baik habis; (c) dijual tanpa peringatan. Catatan: FEFO murni tanpa aturan ini justru menjual batch kedaluwarsa **lebih dulu**, jadi (b) dan (c) tetap butuh urutan khusus.

**P6. Retur, opname, dan barang dibuang**

- **Retur (§9.2)**: barang kembali ke batch yang diambil penjualan itu. Sisa yang bisa dikembalikan per batch = yang diambil sale itu untuk produk itu − yang sudah diretur ke batch itu, **dihitung dari movement** (tanpa tabel baru). Bila sale mengambil dari beberapa batch, retur mengisi **batch dengan kedaluwarsa paling awal dulu** (rekomendasi, P6a): kasir tidak tahu unit mana yang kembali, dan menganggapnya yang paling cepat kedaluwarsa adalah sisi aman (diperingatkan lebih awal, terjual lebih dulu). Alternatif: kasir memilih tanggal dari kemasan di dialog retur (lebih tepat, tetapi menambah langkah di dialog yang sudah memakai PIN). Batch asal yang sudah kedaluwarsa tetap diisi; barangnya muncul di daftar kedaluwarsa untuk dibuang.
- **Opname (P6b, rekomendasi: per batch untuk produk berflag)**: baris opname produk berflag berisi hitungan per batch (sisa sistem per batch tampil saat draf) dan boleh menambah batch yang ditemukan (tanggal + kode + qty). Disimpan di tabel baru `stock_opname_item_batches` (`stock_opname_item_id`, `stock_batch_id` nullable untuk batch baru, `expires_on`, `code`, `counted_qty`, lalu `system_qty`/`difference` saat posting); `stock_opname_items.counted_qty` = jumlahnya, jadi unik `(stock_opname_id, product_id)` tetap. Posting: satu movement `opname` per batch yang berselisih, pada HPP rata-rata. Inilah cara memberi tanggal pada stok "tanpa tanggal" (hitung batch tanpa tanggal 0, tambah batch bertanggal). Alternatif ditolak: opname per produk saja, karena selisihnya harus ditebak ke batch mana.
- **Barang dibuang (P6c, rekomendasi: dokumen + movement baru `disposal`)**: Action `DisposeStock` (owner + gudang) dengan dokumen `stock_disposals` (nomor `DSP-YYYYMMDD-0001`, konstanta baru di `DocumentNumber`, alasan wajib) dan barisnya `stock_disposal_items` (batch, produk, qty, HPP, nilai); movement `type = disposal` ("Dibuang") per batch, keluar pada HPP rata-rata. Tombol "Buang yang kedaluwarsa" mengisi semua batch kedaluwarsa bersisa, dan qty boleh dikurangi (sebagian sudah dibuang tanpa dicatat → sisanya lewat opname). Alternatif ditolak: membuang lewat opname ke 0, karena kerugian kedaluwarsa jadi tercampur dengan barang hilang dan tidak bisa dilaporkan. Cakupan versi ini: batch produk berflag saja; barang rusak produk tanpa flag tetap lewat opname.

**P7. Konkurensi** (rekomendasi: batch dilindungi kunci produk, tanpa level kunci baru)

- Urutan kunci tetap **shift → pelanggan → sale → produk** (urut `product_id`). Baris `stock_batches` hanya ditulis `StockLedger` **setelah** memegang kunci produknya, dan movement/cache batch ditulis di transaksi yang sama, jadi kunci produk sudah menyerialkan semua perubahan batch produk itu. Pembacaan FEFO dilakukan setelah kunci produk didapat (di PostgreSQL READ COMMITTED setiap statement melihat commit terakhir), dan cache batch ditulis sebagai nilai hasil hitung seperti cache produk. Tidak ada kode yang mengunci batch tanpa kunci produk, jadi tidak ada siklus kunci baru.
- Pembuatan/penggabungan batch saat GR juga di bawah kunci produk (tidak ada indeks unik untuk `(product_id, expires_on, code)` karena `code` nullable; penggabungan dijaga kunci itu).
- Tes `tests/Concurrency/BatchFefoRaceTest.php` (PostgreSQL, dua proses): (1) batch paling awal bersisa 1, dua checkout masing-masing 1 unit bersamaan → satu mengambil batch awal, yang lain batch berikutnya; Σ sisa batch = stok produk = awal − 2, tidak ada batch negatif, `stock:rebuild` cocok. (2) satu-satunya batch jual bersisa 2: `DisposeStock` 2 unit dan checkout 1 unit bersamaan → hanya satu yang berhasil, yang lain ditolak dengan pesan stok, tidak ada batch negatif. Keduanya harus merah bila `lockForUpdate()` di `StockLedger::lock()` dibuang sementara (dicek, seperti §17.6/§18.6).

**P8. `stock:rebuild`**

- Replay per produk **tidak berubah** (semua movement, termasuk yang berbatch).
- Tambahan per batch, dari movement mentah: Σ `qty` per `stock_batch_id` = `stock_batches.qty_on_hand`, dan jumlah berjalan tidak pernah negatif. Untuk produk berflag: Σ sisa batch = `stock_on_hand`, dan Σ `qty` movement tanpa batch = 0 (sebelum pelacakan semua movement tanpa batch; pasangan `adjustment` P2 memindahkan totalnya ke batch). Untuk produk tanpa flag: tidak ada batch bersisa ≠ 0. `--fix` menulis ulang cache batch juga.

**P9. Peringatan "hampir kedaluwarsa"** (rekomendasi: satu angka global)

- `config('pos.expiry.warn_days')`, env `POS_EXPIRY_WARN_DAYS`, default **30** hari. Status batch: **kedaluwarsa** (`expires_on < hari ini`), **hampir** (≤ 30 hari lagi), **aman**, **tanpa tanggal**.
- Alternatif: kolom per produk `expiry_warn_days` (nullable, menimpa angka global). Berguna untuk roti (umur 5–7 hari, jadi dengan 30 hari semua batch roti selalu "hampir"), tetapi menambah input di form produk; bisa ditambah kemudian tanpa migrasi yang merusak.

**P10. Siapa yang melihat laporan kedaluwarsa** (rekomendasi: owner + gudang)

- Laporan lain khusus owner (`routes/reports.php`), tetapi gudanglah yang membuang dan merotasi barang. Laporan kedaluwarsa ditaruh di grup `role:owner|gudang` (route `reports.expiry`, `/laporan/kedaluwarsa`), dengan ekspornya. Alternatif: owner saja, dan gudang cukup kartu dashboard.

**Migrasi (expand saja, §9.6)**

- Kolom baru: `products.tracks_expiry` (default `false`), `stock_movements.stock_batch_id` (nullable). Tabel baru: `stock_batches`, `stock_opname_item_batches`, `stock_disposals`, `stock_disposal_items`. Tipe movement baru `disposal` (`varchar(20)` sudah cukup). Tanpa backfill.
- Selama migrasi berjalan, kode lama tidak membaca kolom/tabel baru; semua produk `tracks_expiry = false`, jadi kode lama dan baru menulis movement yang sama. Baris `disposal` hanya ditulis kode baru.

### 19.3 Tampilan

- **Form produk**: sakelar "Lacak tanggal kedaluwarsa"; saat dinyalakan dengan stok, keterangan "Stok sekarang (N pcs) dicatat sebagai batch tanpa tanggal. Beri tanggal lewat stok opname." Daftar produk: badge "Kedaluwarsa".
- **Form GR dan terima PO**: baris produk berflag mendapat rincian tanggal (input `date`, kode opsional, qty) dengan tombol "+ tanggal lain"; jumlah rincian harus sama dengan qty baris. Detail GR menampilkan batch per produk.
- **Kartu dashboard "Hampir kedaluwarsa"** (owner + gudang, di samping "Stok menipis"): jumlah batch kedaluwarsa bersisa (dan nilainya), jumlah yang ≤ N hari, lima batch terdekat (produk, tanggal, sisa, "3 hari lagi" / "lewat 2 hari"), tautan ke laporan, dan tombol "Buang yang kedaluwarsa" bila ada.
- **Kartu stok**: bagian **Batch** di atas tabel (kode, kedaluwarsa, sisa, status) dan kolom **Batch** di tabel movement, dengan filter `?batch={id}`.
- **Laporan → Kedaluwarsa** (`/laporan/kedaluwarsa`): semua batch bersisa dari produk berflag (produk, kode, kedaluwarsa, sisa, nilai pada HPP rata-rata, status), filter status, total per status. **Ekspor Excel + PDF** lewat `ReportTables::expiry()` (potret), route `reports.export.expiry` / `reports.pdf.expiry`.
- **Stok opname**: produk berflag menampilkan satu baris hitungan per batch + "Tambah batch". **Halaman Buang barang** (`/stok-dibuang`, daftar + form + detail `DSP-…`), menu di bawah Stok opname.
- **Layar kasir**: tidak berubah selain angka stok produk berflag = stok jual, dan pesan penolakan P5. **Struk tidak berubah.** Nilai persediaan tidak berubah (P3).

### 19.4 Tidak dikerjakan

- HPP per batch, harga jual berbeda per batch / diskon otomatis untuk barang hampir kedaluwarsa.
- Memilih batch manual di layar kasir atau memindai kode batch/GS1; nomor seri per unit.
- Pemberitahuan (email/WhatsApp) barang hampir kedaluwarsa; retur ke supplier.
- Pembuangan barang rusak untuk produk tanpa flag (tetap lewat opname); batas peringatan per produk (P9 alternatif).
- Multi-cabang / transfer batch antar gudang.

### 19.5 Rencana tes (ditulis lebih dulu)

- **Ledger**: produk tanpa flag menghasilkan movement dan cache yang sama persis seperti sebelumnya (`stock_batch_id` null). `startTracking()`/`stopTracking()`: pasangan `adjustment`, stok dan HPP tetap. `receive()` berbatch: batch baru/digabung, tanggal lewat ditolak, rincian yang tidak sama dengan qty ditolak. `issue()` FEFO lintas batch (tanpa tanggal dulu, lalu tanggal, lalu id), melewati batch kedaluwarsa, menolak dengan pesan stok jual; HPP baris = rata-rata.
- **Penjualan**: tunai, kasbon (§17), dan QRIS/campuran saat settlement mengambil dari batch paling awal; satu baris dari dua batch → dua movement, `sale_items.cogs` benar; QRIS dibuat sebelum tengah malam dan dibayar sesudahnya tetap lunas; `StartQrisPayment` menolak bila stok jual kurang; katalog kasir mengirim stok jual.
- **Retur**: kembali ke batch asal; dari dua batch → yang paling awal dulu, tidak melebihi yang diambil per batch; retur ke batch yang sudah kedaluwarsa tetap tercatat di batch itu.
- **GR dari PO** dan GR langsung dengan rincian; `ReceivePurchaseOrder` tetap menolak melebihi sisa PO.
- **Opname per batch**: selisih per batch, batch baru dari opname, stok tanpa tanggal dipindah ke batch bertanggal tanpa mengubah stok produk.
- **Dibuang**: movement `disposal` per batch, alasan wajib, tidak bisa melebihi sisa batch, kasir 403; laba kotor tidak berubah.
- **`stock:rebuild`**: cocok setelah skenario campuran; melaporkan selisih bila cache batch dirusak, bila Σ batch ≠ stok produk, dan bila ada movement tanpa batch yang tidak berpasangan pada produk berflag; `--fix` memperbaikinya.
- **Tampilan & laporan**: kartu dashboard (batas hari dari config, `setTestNow`), kartu stok per batch dan filter, laporan kedaluwarsa + Excel/PDF dari `ReportTables` (isi sama), akses owner + gudang, kasir 403.
- **Konkurensi**: `BatchFefoRaceTest` (P7), merah bila kunci produk dibuang.
- `LastUnitRaceTest` dan seluruh suite lama tetap hijau tanpa diubah selain penyesuaian tipe kembalian `issue()` bila diperlukan.
- `DemoSalesSeeder` tetap jalan: produk berflag dengan batch hampir dan sudah kedaluwarsa lewat Action asli; `stock:rebuild` (termasuk per batch) dan `receivables:rebuild` cocok.

### 19.6 Data demo

- `CatalogSeeder`: dua produk baru berflag, **Susu UHT Ultra Milk 250ml** (pcs / dus 24) dan **Roti Tawar Sari Roti** (pcs), dan **Teh Botol Sosro 450ml** dinyalakan flag-nya. Stok awal lewat `ReceiveGoods` dengan tanggal kedaluwarsa.
- `DemoSalesSeeder`: restok produk berflag dengan tanggal berbeda (lewat GR/PO asli), sehingga di akhir 30 hari ada satu batch roti yang sudah kedaluwarsa bersisa, satu batch susu ≤ 7 hari, satu pembuangan roti kedaluwarsa di tengah bulan (`DisposeStock`), dan satu opname per batch. Penjualan otomatis mengambil FEFO. DB demo live baru berisi contoh ini setelah `demo-db` mode `reset` (menunggu izin); tanpa itu fitur bisa dicoba dengan menyalakan flag produk di demo.

### 19.7 Implementasi

- Migrasi `2026_11_27_000003_create_stock_batches_tables` (expand saja): `products.tracks_expiry` (default `false`), `stock_batches`, `stock_movements.stock_batch_id` (nullable), `stock_opname_item_batches`, `stock_disposals`, `stock_disposal_items`. Tipe movement baru `StockMovementType::Disposal` ("Dibuang"); `Adjustment` ("Penyesuaian") kini dipakai pasangan mulai/berhenti lacak. Config `pos.expiry.warn_days` (env `POS_EXPIRY_WARN_DAYS`, default 30). Model `StockBatch` (`status()`, `label()`, scope `fefo()` dan `sellableOn()`), `StockOpnameItemBatch`, `StockDisposal`, `StockDisposalItem`; konstanta `DocumentNumber::STOCK_DISPOSAL` (`DSP`).
- `StockLedger`: `receive(..., batches:)` (wajib dan harus pas untuk produk berflag, ditolak untuk yang tidak), `issue(..., sellableOn:, batch:)` (FEFO tanpa batch kedaluwarsa, atau satu batch tertentu untuk pembuangan), `adjustBatchTo()` (opname per batch, termasuk batch yang ditemukan), `startTracking()`/`stopTracking()`, `sellable()`. `adjustTo()` menolak produk berflag. Semua penulisan batch lewat satu `write()` di bawah kunci produk; batch yang disebut dengan tanggal + kode dicari atau dibuat saat itu juga, jadi dua bagian dengan tanggal yang sama menjadi satu baris. `issue()`/`receive()` tetap mengembalikan satu `StockMovement` (yang terakhir; semua bagian penjualan punya HPP yang sama), jadi `BooksSaleStock` dan pemanggil lain tidak berubah selain `sellableOn`. Pengecualian baru `ExpiredStockException` (turunan `InsufficientStockException`, pesan "Stok … yang belum kedaluwarsa tinggal N (M sudah kedaluwarsa).").
- `stock:rebuild`: replay per produk tetap; tambahan `replayBatch()` (Σ movement per batch = cache, tidak pernah negatif), produk berflag tanpa stok di luar batch, produk tanpa flag tanpa sisa di batch; `--fix` menulis ulang cache batch.
- Penjualan: `QrisPayments::settle()` memakai `sale.created_at` sebagai acuan kedaluwarsa; `StartQrisPayment::ensureStock()` dan katalog layar kasir memakai stok jual. Retur: `ProcessSaleReturn::originBatches()` (diambil sale − sudah diretur per batch, dari movement; kedaluwarsa paling awal dulu; sisa dari penjualan sebelum pelacakan ke batch tanpa tanggal).
- Penerimaan: `ReceiveGoods` memvalidasi rincian per baris (`items.N.batches`; dari PO kunci barisnya ikut baris PO lewat `line`), qty rincian dalam satuan baris lalu dikali konversi. `GoodsReceiptRequest::batchRules()`/`batchesOf()` dipakai juga oleh `PurchaseOrderReceiptRequest`. Detail GR menampilkan batch dari movement-nya.
- Opname: trait `SavesOpnameCounts` (dipakai `CreateStockOpname`/`UpdateStockOpname`, parameter baru `batches`); `counted_qty` produk berflag = jumlah batch. `PostStockOpname::postBatches()` menyetel tiap batch yang dihitung (batch yang tidak dihitung tidak disentuh) dan menolak draft lama produk yang baru berflag tanpa hitungan per batch.
- Buang barang: `DisposeStock`, `StockDisposalController` (`/stok-dibuang`, route `stock-disposals.*`, owner + gudang; `?kedaluwarsa=1` mengisi semua batch kedaluwarsa, `?batch=` satu batch).
- Flag produk: `SaveProduct::handle(..., tracksExpiry:, by:)` memanggil `startTracking`/`stopTracking`; `ProductRequest::tracksExpiry()` (null bila tidak dikirim).
- Tampilan: `ProductOptions` mengirim `tracksExpiry` + batch terbuka; kartu stok (`batches`, `batchId`, kolom Batch, filter `?batch=`); laporan `App\Reports\Expiry` (halaman `reports/expiry`, ringkasan dashboard `Expiry::summary()`, `ReportTables::expiry()`, route `reports.expiry`, `reports.export.expiry`, `reports.pdf.expiry` di grup `role:owner|gudang`). Komponen `components/inventory/expiry.tsx` (`BatchStatusBadge`, `ExpiryBatchesInput`), `components/dashboard/expiry-card.tsx`; menu Inventori mendapat "Barang dibuang" dan "Kedaluwarsa"; tab "Kedaluwarsa" di navigasi laporan owner.
- Data demo: `CatalogSeeder::SHELF_LIFE_DAYS` (Teh Botol 120 hari, Susu UHT 45, Roti Tawar 3) dan `batchesFor()` (satu satuan dari lot lama yang lebih cepat kedaluwarsa bila umurnya ≥ 10 hari), dipakai stok awal dan semua restok `DemoSalesSeeder` (GR dan PO). Restok kini melihat stok jual, bukan `stock_on_hand`. Rencana awal "pembuangan di tengah bulan" diganti skenario pasti di pagi terakhir (`expiryAtTheEnd()`): opname per batch (susu kurang 1, dua nampan roti `RAK-B` kedaluwarsa ditemukan), nampan yang lebih lama dibuang lewat `DisposeStock`, yang lain dibiarkan untuk kartu dashboard, lalu satu dus susu `PROMO` yang kedaluwarsa 5 hari lagi. Alasannya: dengan permintaan demo, roti selalu habis sebelum kedaluwarsa, jadi pembuangan acak tidak pernah terjadi.
- Tes: `tests/Feature/Inventory/{BatchLedgerTest,BatchReceiptTest,BatchOpnameTest,StockDisposalTest,ExpiryScreensTest}.php`, `tests/Feature/Sales/BatchSaleTest.php`, tiga tes batch di `RebuildStockCommandTest`, satu di `DemoSeederTest`, dan `tests/Concurrency/BatchFefoRaceTest.php`. Semua ditulis lebih dulu dan merah, kecuali tiga tes pertama `BatchSaleTest` (tunai/kasbon FEFO dan penolakan kedaluwarsa), yang sudah hijau saat pertama dijalankan karena FEFO sudah dibangun di ledger untuk `BatchLedgerTest`; dan tes seeder ditulis bersamaan dengan perubahan seeder. Lawan di tes konkurensi adalah pembuangan, bukan checkout kedua, karena dua checkout sudah antre di baris nomor `INV-…` (pelajaran §17.6); kedua tes merah saat `lockForUpdate()` di `StockLedger::lock()` dibuang sementara (checkout mengambil batch yang sudah dibuang / menjual unit yang sudah dibuang).
