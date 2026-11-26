<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who closed a shift, and why when it was not the cashier (DESIGN.md §13). Expand only:
 * both nullable, no backfill; shifts closed before this read as closed by their cashier.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->restrictOnDelete();
            $table->string('close_reason')->nullable()->after('closed_by');
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('close_reason');
        });
    }
};
