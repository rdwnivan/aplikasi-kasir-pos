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
- GitHub Actions: Pint (lint) + Pest + build frontend
- Docker (Laravel Sail) untuk `up` sekali jalan
- Seeder data realistis (Indomie, Aqua, Teh Botol, Beras 5kg, dll.) + akun demo per role
- Demo live (hosting ditentukan kemudian)

## 8. Milestone

| Minggu | Target                                                                                        |
| ------ | --------------------------------------------------------------------------------------------- |
| 1      | Scaffold, auth & role, skema DB, master produk/satuan/supplier, service buku besar stok + tes |
| 2      | Penerimaan barang, stok opname, layar kasir (tunai), shift                                    |
| 3      | Struk, hold, diskon, retur → **MVP demo**                                                     |
| 4      | QRIS Midtrans + webhook, laporan & dashboard                                                  |
| 5      | Ekspor Excel, polish UI, seeder, CI, Docker                                                   |
| 6      | Deploy demo, README + screenshot/GIF, buffer                                                  |
