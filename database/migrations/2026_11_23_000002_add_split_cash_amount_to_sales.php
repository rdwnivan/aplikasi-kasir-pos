<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mixed cash + QRIS payments (DESIGN.md §11): the cash part of a `split` sale. The QRIS part
 * is total − split_cash_amount. Null for every other payment method, so existing rows need
 * no backfill and code that predates `split` keeps working against this schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->unsignedBigInteger('split_cash_amount')->nullable()->after('payment_method');
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn('split_cash_amount');
        });
    }
};
