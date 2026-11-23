<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accounts are deactivated, never deleted (they carry sales, shifts and stock history), and a
 * password an owner set must be replaced by its user. Additive only: existing accounts stay active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deactivated_at')->nullable()->after('pin_hash');
            $table->boolean('must_change_password')->default(false)->after('deactivated_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deactivated_at', 'must_change_password']);
        });
    }
};
