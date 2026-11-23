<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase orders (docs/DESIGN.md §10). An order never moves stock: goods arrive through
 * goods receipts that point back at the order line they fill. Additive only, so existing
 * receipts keep working with null links.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique(); // PO-20261123-0001, null while draft
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 12); // App\Enums\PurchaseOrderStatus
            $table->date('expected_at')->nullable();
            $table->timestamp('ordered_at')->nullable();
            $table->timestamp('finished_at')->nullable(); // received in full, closed or cancelled
            $table->unsignedBigInteger('total')->default(0);
            $table->string('note')->nullable();
            $table->string('close_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty'); // in the ordered unit
            $table->unsignedBigInteger('unit_price'); // agreed price per ordered unit
            $table->unsignedBigInteger('subtotal');
            $table->timestamps();

            $table->unique(['purchase_order_id', 'product_id', 'unit_id']);
        });

        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->foreignId('purchase_order_id')->nullable()->after('supplier_id')->constrained()->restrictOnDelete();
        });

        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->foreignId('purchase_order_item_id')->nullable()->after('goods_receipt_id')->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_item_id');
        });
        Schema::table('goods_receipts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('purchase_order_id');
        });
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
