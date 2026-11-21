<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sales returns (retur). Goods go back into stock at the HPP they left with; the refund is
 * paid in cash from the drawer of the shift that processes it. An owner approves each one
 * with a PIN, stored hashed on the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pin_hash')->nullable()->after('password');
        });

        Schema::create('sale_returns', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique(); // RET-20261122-0001
            $table->foreignId('sale_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete(); // drawer the cash left
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // who processed it
            $table->foreignId('approved_by')->constrained('users')->restrictOnDelete(); // owner PIN
            $table->unsignedBigInteger('total'); // cash refunded, Rupiah
            $table->unsignedBigInteger('tax_amount')->default(0); // PPN part of the refund
            $table->decimal('cogs_total', 17, 4)->default(0); // HPP of goods taken back
            $table->string('reason')->nullable();
            $table->timestamps();

            $table->index(['shift_id']);
            $table->index(['created_at']);
        });

        Schema::create('sale_return_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sale_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('qty'); // in the unit it was sold in
            $table->unsignedInteger('conversion');
            $table->unsignedBigInteger('amount'); // refund for this line
            $table->decimal('unit_cost', 15, 4); // HPP per base unit of the original sale line
            $table->decimal('cogs', 17, 4);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_items');
        Schema::dropIfExists('sale_returns');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('pin_hash');
        });
    }
};
