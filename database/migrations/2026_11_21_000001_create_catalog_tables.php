<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Master data: kategori, satuan, produk, dan satuan bertingkat per produk.
 *
 * Konvensi:
 * - Uang (Rupiah) disimpan sebagai bigint tanpa desimal.
 * - HPP rata-rata (avg_cost) disimpan decimal(15,4) karena moving average menghasilkan pecahan.
 * - Kuantitas stok disimpan dalam SATUAN DASAR produk (integer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 30)->unique(); // pcs, pak, dus, kg, ...
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('base_unit_id')->constrained('units')->restrictOnDelete();
            $table->string('sku', 50)->unique();
            $table->string('name');
            $table->unsignedInteger('min_stock')->default(0);

            // Cache dari buku besar stock_movements. Hanya boleh diubah lewat StockLedger.
            $table->bigInteger('stock_on_hand')->default(0);
            $table->decimal('avg_cost', 15, 4)->default(0);

            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('name');
        });

        Schema::create('product_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('conversion'); // jumlah satuan dasar dalam 1 satuan ini
            $table->string('barcode', 64)->nullable()->unique();
            $table->unsignedBigInteger('price'); // harga jual per satuan ini (Rupiah)
            $table->timestamps();

            $table->unique(['product_id', 'unit_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_units');
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('categories');
    }
};
