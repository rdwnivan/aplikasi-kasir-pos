<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cashier shifts and sales. Every amount the receipt shows is stored, not recomputed, so a
 * later price or tax change never alters a past transaction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->unsignedBigInteger('opening_cash'); // modal awal laci
            $table->timestamp('closed_at')->nullable();
            $table->unsignedBigInteger('expected_cash')->nullable();
            $table->unsignedBigInteger('counted_cash')->nullable();
            $table->bigInteger('cash_difference')->nullable(); // counted − expected
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'closed_at']);
        });

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->nullable()->unique(); // INV-20261121-0001, null while held
            $table->foreignId('shift_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('status', 12); // App\Enums\SaleStatus
            $table->string('held_label')->nullable(); // e.g. customer name while parked

            $table->unsignedBigInteger('subtotal'); // after line discounts
            $table->string('discount_type', 10)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->unsignedBigInteger('discount_amount')->default(0);

            $table->boolean('prices_include_tax');
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->unsignedBigInteger('taxable_amount'); // selling price excluding PPN
            $table->unsignedBigInteger('dpp_amount')->default(0);
            $table->unsignedBigInteger('tax_amount')->default(0);
            $table->unsignedBigInteger('total');

            $table->string('payment_method', 10)->nullable();
            $table->unsignedBigInteger('paid_amount')->nullable();
            $table->unsignedBigInteger('change_amount')->nullable();
            $table->decimal('cogs_total', 17, 4)->default(0);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'completed_at']);
        });

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name'); // snapshot for the receipt
            $table->string('unit_name', 30);
            $table->unsignedInteger('qty');
            $table->unsignedInteger('conversion');
            $table->unsignedBigInteger('unit_price');
            $table->string('discount_type', 10)->nullable();
            $table->unsignedInteger('discount_value')->nullable();
            $table->unsignedBigInteger('discount_amount')->default(0);
            $table->unsignedBigInteger('line_total');
            $table->decimal('unit_cost', 15, 4)->nullable(); // HPP per base unit, set on completion
            $table->decimal('cogs', 17, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
        Schema::dropIfExists('sales');
        Schema::dropIfExists('shifts');
    }
};
