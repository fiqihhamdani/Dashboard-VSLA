<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['cash_entries', 'savings', 'loans', 'installments', 'payments'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->softDeletes());
        }
        Schema::table('cash_entries', fn (Blueprint $table) => $table->unsignedInteger('version')->default(0));
        Schema::create('financial_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_entry_id')->constrained()->restrictOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('action', 20);
            $table->text('reason');
            $table->json('before');
            $table->json('after');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (DB::table('financial_revisions')->exists()) {
            throw new RuntimeException('Rollback ditolak karena sudah ada koreksi keuangan. Gunakan migrasi perbaikan agar pembatalan tidak aktif kembali dan audit tidak hilang.');
        }
        Schema::dropIfExists('financial_revisions');
        Schema::table('cash_entries', fn (Blueprint $table) => $table->dropColumn('version'));
        foreach (['payments', 'installments', 'loans', 'savings', 'cash_entries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropSoftDeletes());
        }
    }
};
