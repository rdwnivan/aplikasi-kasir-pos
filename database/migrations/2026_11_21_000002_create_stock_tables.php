<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buku besar stok + dokumen yang menghasilkan pergerakan stok
 * (penerimaan barang dan stok opname).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('type', 20); // App\Enums\StockMovementType
            $table->bigInteger('qty'); // bertanda, dalam satuan dasar
            $table->decimal('unit_cost', 15, 4); // HPP per satuan dasar untuk baris ini
            $table->bigInteger('stock_after');
            $table->decimal('avg_cost_after', 15, 4);
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'id']);
            $table->index('created_at');
        });

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique(); // GR-20261121-0001
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->date('received_at');
            $table->unsignedBigInteger('total')->default(0);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('goods_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty'); // dalam satuan yang dipilih
            $table->unsignedInteger('conversion'); // snapshot konversi saat diterima
            $table->unsignedBigInteger('unit_price'); // harga beli per satuan yang dipilih
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique(); // SO-20261121-0001
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 10)->default('draft'); // draft | posted
            $table->string('note')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->bigInteger('system_qty')->nullable(); // diisi saat posting
            $table->unsignedBigInteger('counted_qty');
            $table->bigInteger('difference')->nullable();
            $table->timestamps();

            $table->unique(['stock_opname_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('goods_receipt_items');
        Schema::dropIfExists('goods_receipts');
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('suppliers');
    }
};
