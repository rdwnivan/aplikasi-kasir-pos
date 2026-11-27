<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch & tanggal kedaluwarsa (DESIGN.md §19). Expand only: new tables and nullable or
 * defaulted columns, so the code still serving during the migration is unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('tracks_expiry')->default(false);
        });

        Schema::create('stock_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('code', 50)->nullable(); // kode batch/lot dari kemasan
            $table->date('expires_on')->nullable(); // null = batch tanpa tanggal
            $table->bigInteger('qty_on_hand')->default(0); // cache, ditulis hanya oleh StockLedger
            $table->timestamps();

            $table->index(['product_id', 'expires_on']);
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete();
        });

        Schema::create('stock_opname_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->nullable()->constrained()->restrictOnDelete(); // null = batch ditemukan saat opname
            $table->date('expires_on')->nullable();
            $table->string('code', 50)->nullable();
            $table->unsignedBigInteger('counted_qty');
            $table->bigInteger('system_qty')->nullable(); // diisi saat posting
            $table->bigInteger('difference')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_disposals', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique(); // DSP-20261127-0001
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('reason');
            $table->unsignedBigInteger('total_value')->default(0); // Σ nilai pada HPP rata-rata, rupiah
            $table->timestamps();
        });

        Schema::create('stock_disposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_disposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_batch_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('qty'); // satuan dasar
            $table->decimal('unit_cost', 15, 4);
            $table->unsignedBigInteger('value');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_disposal_items');
        Schema::dropIfExists('stock_disposals');
        Schema::dropIfExists('stock_opname_item_batches');
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('stock_batch_id');
        });
        Schema::dropIfExists('stock_batches');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('tracks_expiry');
        });
    }
};
