<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * QRIS through Midtrans (Core API). A QRIS sale waits as pending_payment with the QR on it;
 * stock moves only when Midtrans reports settlement. Every notification is kept, verified or
 * not, as an audit trail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            // 'pending_payment' does not fit the original varchar(12).
            $table->string('status', 20)->change();
            $table->string('payment_reference', 64)->nullable()->after('change_amount'); // Midtrans transaction_id
            $table->text('qr_string')->nullable()->after('payment_reference');
            $table->string('qr_url', 500)->nullable()->after('qr_string');
            $table->timestamp('payment_expires_at')->nullable()->after('qr_url');
            $table->timestamp('payment_checked_at')->nullable()->after('payment_expires_at');
        });

        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 20)->default('midtrans');
            $table->string('order_id', 64)->nullable()->index();
            $table->string('transaction_id', 64)->nullable();
            $table->string('transaction_status', 30)->nullable();
            $table->string('status_code', 10)->nullable();
            $table->string('gross_amount', 30)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('result', 40); // what we did with it
            $table->json('payload');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
        Schema::table('sales', function (Blueprint $table) {
            $table->dropColumn(['payment_reference', 'qr_string', 'qr_url', 'payment_expires_at', 'payment_checked_at']);
            $table->string('status', 12)->change();
        });
    }
};
