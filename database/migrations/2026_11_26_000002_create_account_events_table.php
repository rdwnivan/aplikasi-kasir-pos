<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account audit log (DESIGN.md §15). Expand only: a new table that old code never reads.
 * Rows are never updated or deleted by the app, so there is no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            // null = the system (e.g. a demo account released at login).
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('type', 40);
            $table->json('details')->nullable();
            $table->timestamp('created_at');

            $table->index(['user_id', 'id']);
            $table->index(['type', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_events');
    }
};
