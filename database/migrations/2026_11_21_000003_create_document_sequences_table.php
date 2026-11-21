<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gap-free, race-safe running numbers per document prefix and day (GR-20261121-0001).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->string('key', 40)->primary(); // e.g. GR-20261121
            $table->unsignedInteger('last_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
    }
};
