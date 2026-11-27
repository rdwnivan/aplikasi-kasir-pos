<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pelunasan kasbon by QRIS (DESIGN.md §18). A QRIS payment waits as pending_payment with its
 * QR; the debt comes down only when Midtrans settles it. Expand only (§9.6): existing rows
 * and rows still written by the previous release default to a completed cash payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->after('method');
            $table->string('payment_reference', 64)->nullable()->after('status'); // Midtrans transaction_id
            $table->text('qr_string')->nullable()->after('payment_reference');
            $table->string('qr_url', 500)->nullable()->after('qr_string');
            $table->timestamp('payment_expires_at')->nullable()->after('qr_url');
            $table->timestamp('payment_checked_at')->nullable()->after('payment_expires_at');
            $table->timestamp('completed_at')->nullable()->after('payment_checked_at'); // null on cash rows before §18: read created_at

            $table->index(['customer_id', 'status']);
            $table->index(['shift_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('customer_payments', function (Blueprint $table) {
            $table->dropIndex(['customer_id', 'status']);
            $table->dropIndex(['shift_id', 'status']);
            $table->dropColumn(['status', 'payment_reference', 'qr_string', 'qr_url', 'payment_expires_at', 'payment_checked_at', 'completed_at']);
        });
    }
};
