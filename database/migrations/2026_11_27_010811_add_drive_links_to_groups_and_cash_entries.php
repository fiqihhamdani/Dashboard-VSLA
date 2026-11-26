<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', fn (Blueprint $table) => $table->string('legality_url', 2048)->nullable());
        Schema::table('cash_entries', fn (Blueprint $table) => $table->string('proof_url', 2048)->nullable());
    }

    public function down(): void
    {
        Schema::table('groups', fn (Blueprint $table) => $table->dropColumn('legality_url'));
        Schema::table('cash_entries', fn (Blueprint $table) => $table->dropColumn('proof_url'));
    }
};
