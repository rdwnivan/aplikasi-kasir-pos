<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer credit, kasbon (DESIGN.md §17). Expand only: new tables, and nullable or
 * defaulted columns on sales and sale_returns that code predating kasbon never writes.
 * With sale_returns.credit_amount = 0 the new drawer formula equals the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('phone', 20)->nullable()->unique(); // digits only
            $table->string('address')->nullable();
            $table->string('note')->nullable();
            $table->unsignedBigInteger('credit_limit')->default(0);
            // Cache of Σ customer_ledger_entries.amount; written only by CustomerLedger.
            $table->unsignedBigInteger('balance')->default(0);
            $table->timestamp('deactivated_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index('name');
        });

        Schema::create('customer_payments', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique(); // PAY-20261127-0001
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('shift_id')->constrained()->restrictOnDelete(); // drawer the cash went into
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->string('method', 10)->default('cash');
            $table->string('note')->nullable();
            $table->timestamps();

            $table->index('shift_id');
        });

        Schema::create('customer_write_offs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete(); // the owner
            $table->unsignedBigInteger('amount');
            $table->string('reason');
            $table->timestamps();
        });

        Schema::create('customer_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->constrained()->restrictOnDelete(); // the kasbon it belongs to
            $table->string('type', 20); // App\Enums\LedgerEntryType
            $table->bigInteger('amount'); // + more debt, − less
            $table->unsignedBigInteger('balance_after');
            $table->nullableMorphs('reference');
            $table->foreignId('user_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at');

            $table->index(['customer_id', 'id']);
            $table->index(['sale_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('user_id')->constrained()->restrictOnDelete();
            // The part of a `credit` sale left owing; null for every other payment method.
            $table->unsignedBigInteger('credit_amount')->nullable()->after('split_cash_amount');
        });

        Schema::table('sale_returns', function (Blueprint $table) {
            // The part of the return taken off the customer's debt instead of paid out in cash.
            $table->unsignedBigInteger('credit_amount')->default(0)->after('total');
        });
    }

    public function down(): void
    {
        Schema::table('sale_returns', function (Blueprint $table) {
            $table->dropColumn('credit_amount');
        });
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn('credit_amount');
        });
        Schema::dropIfExists('customer_ledger_entries');
        Schema::dropIfExists('customer_write_offs');
        Schema::dropIfExists('customer_payments');
        Schema::dropIfExists('customers');
    }
};
