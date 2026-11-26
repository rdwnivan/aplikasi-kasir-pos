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
- Batch & tanggal kedaluwarsa
- ~~Pembayaran campuran (split payment)~~ → tunai + QRIS selesai di v2, lihat §11
- Kasbon / piutang pelanggan
- Multi-cabang & transfer stok
- Mode offline (PWA + antrean sinkronisasi)
- Cetak ESC/POS langsung (WebUSB / QZ Tray)
- Ekspor PDF
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
- **Pembayaran campuran tunai + QRIS (§11), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, Midtrans Sandbox). Akun kasir, modal Rp 200.000, Beras Rojolele 5kg Rp 78.000 → F9 → "Tunai + QRIS", tunai Rp 50.000: dialog menampilkan "Sisa lewat QRIS Rp 28.000". Percobaan pertama ditolak Midtrans (409 conflict) karena nomor INV-20261123-0001 sudah dipakai transaksi demo live di akun sandbox yang sama; sale itu otomatis `cancelled` dan pesan Midtrans tampil di dialog. Percobaan kedua (INV-20261123-0002): QR Rp 28.000 dengan keterangan "tunai Rp 50.000 sudah diterima", dibayar lewat simulator sandbox (GoPay) → polling menyelesaikan transaksi, dialog sukses menampilkan Tunai Rp 50.000 / QRIS Rp 28.000, header shift "1 transaksi · kas Rp 250.000", stok beras 5 → 4. Riwayat menampilkan "Tunai + QRIS", detail "Dibayar tunai / Dibayar QRIS", struk "Tunai 50.000 / QRIS 28.000" dengan referensi Midtrans. Pembatalan oleh kasir (Gula Pasir Rp 18.500, tunai Rp 10.000, INV-20261123-0004) awalnya hanya memunculkan toast "QRIS … dibatalkan" tanpa pengingat uang tunai; sekarang toast peringatan "Kembalikan uang tunai Rp 10.000 ke pelanggan." muncul dan kas shift tetap Rp 250.000. Tanpa error di konsol. Data demo hasil seeder: 41 dari 542 transaksi lunas campuran, `stock:rebuild` cocok. Pesan pengembalian di dialog saat QR kedaluwarsa sendiri belum dilihat di browser (perlu menunggu 15 menit). **Demo live** (2026-11-26, pemilik repo login sendiri sebagai `kasir@kasir.test`, Claude tidak mengetik kata sandi): halaman Buka shift lebih dulu menampilkan pemberitahuan §13 "Shift Anda sebelumnya ditutup oleh Budi Santoso pada 26 Nov 2026, 20.48: …". Shift baru modal Rp 200.000 → Beras Rojolele 5kg Rp 78.000 (stok 8) → F9 → "Tunai + QRIS", tunai Rp 50.000: "Sisa lewat QRIS Rp 28.000" → QR Midtrans sandbox INV-20261126-0001 Rp 28.000 dengan "tunai Rp 50.000 sudah diterima" (kali ini tanpa bentrok nomor). Pemilik repo membayar lewat simulator QRIS sandbox; transaksi menjadi lunas: keranjang kosong, header "1 transaksi · kas Rp 250.000", stok beras 8 → 7. Riwayat: "Tunai + QRIS" Rp 78.000; detail "Dibayar tunai Rp 50.000 / Dibayar QRIS Rp 28.000"; struk "Tunai 50.000 / QRIS 28.000" + referensi Midtrans. Tanpa error runtime Vercel. Tidak terlihat: dialog "Transaksi berhasil" (sudah tertutup saat dicek), dan apakah pelunasan datang dari webhook atau polling (log runtime tidak menunjukkannya). Shift kasir ini dibiarkan terbuka di DB demo.
- **Kelola pengguna (§12), browser lokal** (2026-11-23, `php -S`, DB `kasir_pos_test` hasil `migrate:fresh --seed`, akun owner demo). Menu "Pengguna" tampil di bawah Laporan; menu aksi baris sendiri hanya berisi "Ubah". Tambah kasir "Dewi Lestari" dengan email `Dewi@Kasir.test` → tersimpan sebagai `dewi@kasir.test` dengan badge "Belum ganti kata sandi". Login sebagai Dewi: `/kasir` dialihkan ke "Ganti kata sandi"; kata sandi sementara yang sama ditolak ("Pilih kata sandi yang berbeda…"), kata sandi baru diterima dan Dewi masuk ke "Buka shift". Dewi membuka shift; `DeactivateUser` yang dipanggil dari luar (script dengan app ter-bootstrap, meniru owner di perangkat lain) ditolak "Dewi Lestari masih punya shift terbuka. Tutup shift dulu." Setelah shift ditutup, penonaktifan berhasil dan request Dewi berikutnya dialihkan ke login. Percobaan pertama berakhir di halaman login **tanpa pesan**, karena Action juga menghapus baris `sessions` dan halaman login tidak menampilkan error yang datang lewat redirect. Keduanya diperbaiki (baris sesi tidak lagi dihapus; login menampilkan `errors.email` dari props), lalu diulang: sesi yang sedang berjalan berakhir dengan "Akun ini dinonaktifkan. Hubungi owner.", dan login kata sandi saat nonaktif menampilkan pesan yang sama. Dari UI owner: tab "Nonaktif (1)" → Aktifkan lagi, Reset kata sandi (badge muncul lagi, sesi owner tetap), Nonaktifkan lewat dialog konfirmasi. Tanpa error di konsol. Yang tidak dicoba di browser (hanya tes Pest): akses 403 untuk gudang/kasir, pengaman owner aktif terakhir, hapus PIN owner lain, dan perlindungan akun demo (`POS_PROTECT_DEMO_ACCOUNTS`), karena server lokal berjalan tanpa variabel itu. **Deploy** (push `386bb54`): `tests` hijau di ketiga job (Pest PostgreSQL selesai 01:58:10 UTC) → `demo-db` run [45097507783](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45097507783) menjalankan `2026_11_23_000003_add_account_status_to_users` pukul 01:58:48 → job deploy 01:58:54–02:00:39, `dpl_AZV59cFotV3YqXmCy8avZeivj2jC` `READY` untuk SHA itu. Dari luar: `/up` 200, `/login` 200, `/pengguna` dan `/ganti-kata-sandi` mengalihkan tamu ke login; Vercel tidak mencatat error runtime sejak deploy. Fitur ini belum dicoba sambil login di demo live, termasuk perlindungan akun demo.
- **2FA dan passkey akun demo (§12.4.1)** (2026-11-26). Pest: `DemoAccountSignInTest` (19 tes) ditulis lebih dulu dan merah sebelum implementasi. **Browser lokal** (`php -S` port 8002 dengan `POS_PROTECT_DEMO_ACCOUNTS=true`, DB `kasir_pos_test` hasil `migrate:fresh --seed`): `owner@kasir.test` diberi 2FA terkonfirmasi + satu passkey lewat script (meniru akun yang dikunci pengunjung). Login kata sandi langsung masuk ke dashboard tanpa tantangan 2FA, lalu DB menunjukkan kolom 2FA kosong dan 0 passkey. Halaman Keamanan akun itu menampilkan status dan catatan per bagian, tanpa tombol Aktifkan 2FA / Tambah / Hapus passkey. `GET /user/passkeys/options` dan `POST /user/two-factor-authentication` dari halaman itu dijawab 403 dengan pesan demo. Akun lokal bukan demo di server yang sama masih melihat "Aktifkan 2FA" dan mendapat QR + kunci manual (konfirmasi kode TOTP hanya dibuktikan di Pest). Dengan pengaturan mati (port 8001), halaman Keamanan owner demo tampil seperti biasa. Pendaftaran passkey sungguhan (WebAuthn) tidak dicoba. **Deploy** (push `5aaea74`, tanpa migrasi): `tests` [45806380085](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45806380085) hijau di ketiga job (Pest SQLite 309 lulus + 4 dilewati, PostgreSQL 313 lulus; selesai 11:00:54 UTC) → `demo-db` [45806643270](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45806643270) migrate 11:01:00–11:01:32 → deploy 11:01:34–11:05:27, `dpl_9z2Mryv9Rg14My2RmxiyF1hXu68m` `READY` untuk SHA itu. `/up` 200, `/login` 200, Vercel tidak mencatat error runtime dalam satu jam setelahnya. **Demo live sambil login** (2026-11-26, pemilik repo login sendiri sebagai `owner@kasir.test`): Pengaturan → Keamanan menampilkan "2FA tidak aktif di akun ini." dan catatan per bagian, tanpa tombol Aktifkan 2FA / Tambah / Hapus passkey, daftar passkey kosong (jadi tidak ada 2FA/passkey pengunjung yang tersisa, atau sudah terhapus oleh login itu; tidak bisa dibedakan dari luar). Dari sesi owner itu, `fetch` tanpa konfirmasi kata sandi ke enable, confirm, regenerate, disable, passkey options, dan passkey store semuanya 403 dengan pesan demo; `DELETE /user/passkeys/999999` 404 karena passkey itu tidak ada (route model binding berjalan sebelum middleware). Di Pengguna, ketiga akun berbadge "Akun demo"; untuk Siti, "Reset kata sandi" dan "Nonaktifkan" nonaktif di menu, kolom Email dan Role di dialog "Ubah" nonaktif; `PUT /pengguna/2` (email + role baru) dan `POST /pengguna/3/nonaktifkan` langsung ke server keduanya 422 dengan pesan demo, data tetap. Tanpa error konsol, tanpa error runtime Vercel. Alur tambah/reset/nonaktifkan akun uji di live tidak dicoba, karena butuh mengetik kata sandi di produksi (sudah dicoba di browser lokal, lihat entri §12 di atas).
- **Owner menutup shift kasir lain (§13), browser lokal** (2026-11-26, commit `44210f3`). Pest: `OwnerCloseShiftTest` (21 tes) ditulis lebih dulu dan merah sebelum implementasi; `ShiftCloseRaceTest` (PostgreSQL) lulus, dan merah bila kunci shift di `CompleteSale` dibuang (dicek dengan menghapusnya sementara). Seluruh suite: SQLite 330 lulus + 5 dilewati, PostgreSQL 335 lulus. **Browser** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed`): kasir demo membuka shift (modal Rp 200.000), menjual Gula Pasir Rp 18.500 tunai, menahan satu keranjang, dan membiarkan satu barang di keranjang aktif. Owner login di tab lain (`owner.localhost:8001`, cookie terpisah) → Laporan → Rekap kasir & shift: baris "Berjalan" punya tombol "Tutup shift"; halaman tutup menampilkan kas seharusnya Rp 218.500, "Transaksi terakhir 1 menit yang lalu" dan "1 transaksi ditahan akan dihapus". Hitungan Rp 218.000 + alasan → toast "Shift Andi Pratama ditutup. Kas kurang Rp 500.", baris rekap "oleh Budi Santoso: {alasan}", total selisih −Rp 500. DB: `closed_by` = owner, `close_reason` tersimpan, selisih −500, keranjang ditahan terhapus. Tab kasir yang masih terbuka menekan bayar → tidak ada sale baru, layar beralih ke Buka shift dengan "Shift Anda sebelumnya ditutup oleh Budi Santoso pada … : {alasan}" (toast "Buka shift dulu…" tidak sempat terlihat karena halaman langsung berganti). Setelah kasir membuka shift baru, badge "Shift terbuka" di Pengguna menaut ke halaman tutup shift itu dan menu baris berisi "Tutup shift" di atas "Nonaktifkan (tutup shift dulu)". Tanpa error konsol. Yang hanya dibuktikan di Pest: pemberesan QRIS menunggu (lunas / dibatalkan / Midtrans gagal), ekspor Excel "Ditutup oleh"/"Alasan", 403 untuk kasir/gudang, dan mode demo publik. **Deploy** (push `dc29af2`, membawa migrasi `2026_11_26_000001_add_closed_by_to_shifts`): `tests` [45814066987](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45814066987) hijau di ketiga job (Pest PostgreSQL selesai 11:51:56 UTC) → `demo-db` [45814394092](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45814394092): migrasi selesai 11:52:39, job deploy baru mulai 11:52:44 dan selesai 11:54:23, `dpl_EgnMjkiq5A7gTKjh6J78EbwoMNh3` `READY` untuk SHA itu. `/up` 200, `/laporan/kasir/shift/1/tutup` mengalihkan tamu ke login, Vercel tidak mencatat error runtime sejak deploy. **Demo live sambil login** (2026-11-26, pemilik repo login sendiri sebagai `owner@kasir.test`, Claude tidak mengetik kata sandi): di Pengguna, Andi (kasir demo) berbadge "Shift terbuka" dan menunya berisi "Tutup shift" + "Nonaktifkan (tutup shift dulu)", keduanya menaut ke `/laporan/kasir/shift/31/tutup`. Shift #31 dibuka 22 Nov 2026 19.39 dan ditinggal terbuka 4 hari (sisa uji atau pengunjung). Halaman tutup: modal Rp 200.000, penjualan tunai 0 transaksi, kas seharusnya Rp 200.000, "Transaksi terakhir 4 hari yang lalu (23 Nov 2026, 06.36)", tanpa QRIS menunggu/keranjang ditahan. Dengan izin pemilik: hitungan Rp 200.000 ("Kas pas, tidak ada selisih.") + alasan "Uji tutup shift oleh owner (Claude)" → toast "Shift Andi Pratama ditutup. Kas pas.", baris rekap "26 Nov 2026, 20.48 oleh Budi Santoso: Uji tutup shift oleh owner (Claude)" (2 transaksi, QRIS Rp 8.000, selisih pas), tidak ada baris "Berjalan" lagi, badge "Shift terbuka" hilang di Pengguna. Ekspor Excel kasir (20–26 Nov, dibaca di halaman tanpa disimpan) memuat kolom "Ditutup oleh"/"Alasan" berisi "Budi Santoso" dan alasan itu. Tanpa error konsol aplikasi, tanpa error runtime Vercel. Halaman kasir yang melihat pemberitahuan "ditutup oleh" tidak dicoba di live (butuh login kasir bersamaan).
- **Owner mereset 2FA & passkey akun lain (§14), browser lokal** (2026-11-26, commit `73a9ec5`). Pest: `ResetSignInFactorsTest` (15 tes) ditulis lebih dulu dan semuanya merah sebelum implementasi. Seluruh suite: SQLite 345 lulus + 5 dilewati, PostgreSQL 350 lulus, PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser** (`php -S` port 8001, DB `kasir_pos_test` hasil `migrate:fresh --seed`): `kasir@kasir.test` diberi 2FA terkonfirmasi + dua passkey lewat script (meniru HP hilang). Login kata sandi kasir berhenti di tantangan 2FA. Owner (`owner.localhost:8001`) → Pengguna: baris Andi berbadge "2FA" dan "2 passkey"; menu Andi berisi "Reset 2FA & passkey", menu Siti (tanpa 2FA/passkey) tidak. Props halaman hanya berisi `two_factor`/`has_two_factor_state`/`passkeys`; HTML halaman tidak memuat `two_factor_secret`, `recovery_codes`, `credential`, maupun nama passkey. Dialog menampilkan "2FA (aplikasi authenticator dan kode pemulihan)" dan "2 passkey" beserta catatan sesi; kata sandi owner yang salah ditolak "Kata sandi salah." dan dialog tetap terbuka; kata sandi benar → badge hilang, DB: kolom 2FA kosong, 0 passkey, log `Owner reset 2FA and passkeys {actor_id: 1, user_id: 3, had_two_factor: true, passkeys: 2}`. Login kata sandi kasir berikutnya langsung ke "Buka shift" tanpa tantangan 2FA. Tanpa error konsol dari aplikasi. Yang tidak dilihat di browser (hanya Pest): toast sukses (halaman sudah berganti saat dicek), penolakan akun sendiri/akun demo, akun nonaktif, sesama owner, dan setup 2FA yang belum dikonfirmasi. **Deploy** (push `e1f13dc`, tanpa migrasi): `tests` [45830210912](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45830210912) hijau di ketiga job (Pest PostgreSQL selesai 13:35:05 UTC) → `demo-db` [45830567227](https://github.com/rdwnivan/aplikasi-kasir-pos/actions/runs/45830567227): migrate 13:35:12–13:35:49, deploy 13:35:51–13:37:40, `dpl_Aj3pYrku5Fo3CgupiaR8Bdu1PxxX` `READY` untuk SHA itu. `/up` 200, `/login` 200, `/pengguna` mengalihkan tamu ke login, `DELETE /pengguna/3/2fa` tanpa sesi/token CSRF dijawab 419. Vercel tidak mencatat error runtime sejak deploy. **Demo live sambil login** (2026-11-26, sesi owner demo yang sama dengan cek §13 di atas): props Pengguna berisi `two_factor: false`, `has_two_factor_state: false`, `passkeys: 0` untuk ketiga akun demo, HTML tanpa `two_factor_secret`/`recovery_codes`/`credential`; tidak ada badge 2FA/passkey dan menu Andi tidak berisi "Reset 2FA & passkey" (sesuai: akun demo tidak bisa punya 2FA). `DELETE /pengguna/2/2fa` dari sesi itu tanpa kata sandi → 422 "Kata sandi wajib diisi.", tidak ada yang berubah. Alur reset penuh belum dicoba di live: butuh akun uji yang 2FA-nya dipasang pemilik repo sendiri (Claude tidak mengetik kata sandi di produksi).
- **Log audit perubahan akun (§15), browser lokal** (2026-11-26, commit `3eb0186`). Pest: `AccountLogTest` (18 tes) ditulis lebih dulu dan semuanya merah sebelum implementasi; tes log di `ResetSignInFactorsTest` diganti dari `Log::notice` ke `account_events`. Seluruh suite: SQLite 363 lulus + 5 dilewati, PostgreSQL 368 lulus, Pint dan PHPStan bersih, `DemoSalesSeeder` tetap jalan. **Browser** (`php -S` port 8001, DB `kasir_pos_test` + migrasi baru): owner membuka menu Siti → item "Riwayat" menaut ke `/pengguna/riwayat?user=2`; Siti dinonaktifkan lalu diaktifkan lagi lewat dialog → halaman Riwayat akun menampilkan "Diaktifkan lagi" (21.18) di atas "Dinonaktifkan" (21.17), keduanya "Oleh Budi Santoso", dengan keterangan "Dicatat sejak 26 Nov 2026"; filter `?type=deactivated` menyisakan satu baris. Yang hanya dibuktikan di Pest: rincian lama → baru ("Role: Kasir → Staf Gudang"), perubahan oleh pengguna sendiri (profil, kata sandi, PIN, 2FA, passkey), `demo_sign_in_released`, 403 untuk gudang/kasir, dan tidak adanya kata sandi/PIN/nama passkey di tabel maupun halaman. Belum di-deploy.

### Belum terverifikasi

- **Cek demo live yang ditunda (butuh pemilik repo, dicatat 2026-11-26).** Claude tidak mengetik kata sandi di produksi, jadi langkah ini menunggu pemilik repo:
    - Reset 2FA & passkey (§14) alur penuh: owner membuat akun uji (kata sandi awal diketik pemilik), pemilik login sebagai akun itu, mengganti kata sandi, dan memasang 2FA dengan authenticator di HP; lalu owner mereset (kata sandi owner di dialog diketik pemilik). Yang sudah terbukti di live hanya props, menu, dan penolakan 422 tanpa kata sandi.
    - Kelola pengguna (§12) di live: tambah, reset kata sandi, dan nonaktifkan akun uji (kata sandi diketik pemilik).
    - Split payment (§11): dialog "Transaksi berhasil" dan apakah pelunasan datang dari webhook atau polling (perlu tab kasir yang tetap terbuka saat membayar, atau tab ditutup untuk menguji webhook saja).
    - Shift kasir demo yang dibuka saat cek split payment (26 Nov 2026, 20.58) masih terbuka di DB demo; tutup dari akun kasir atau oleh owner (§13).
- **QRIS kedaluwarsa tidak terdeteksi lewat polling (bug, ditemukan 2026-11-26).** Cek lokal QR split INV-20261126-0002 (tunai Rp 10.000 + QRIS Rp 8.500): setelah `expiresAt` 21:23:58 layar tetap "Menunggu pembayaran" lebih dari 3 menit. Midtrans menjawab `GET /v2/{order}/status` dengan HTTP 200, `status_code` `407`, `transaction_status` `expire`; `MidtransClient::send()` menganggap `status_code` selain 2xx sebagai gagal dan melempar `MidtransException`, jadi `QrisPayments::refresh()` mengembalikan `unchanged` dan transaksi tidak pernah dibatalkan oleh polling. Pesan "Kembalikan uang tunai …" di dialog saat QR kedaluwarsa (§11.4) karena itu belum pernah bisa muncul dari polling. Yang masih membatalkan: tombol "Batalkan QRIS" (dicoba: transaksi menjadi `cancelled`), penutupan shift oleh owner, dan, di demo live, notifikasi webhook `expire` (tidak memanggil `status()`; belum dicek di live). Belum diperbaiki.
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
