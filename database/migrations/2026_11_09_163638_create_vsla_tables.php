<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->boolean('is_admin')->default(false);
        });
        Schema::create('groups', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('code', 40)->unique();
            $t->string('location');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        Schema::create('members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained()->restrictOnDelete();
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['group_id', 'is_active']);
        });
        Schema::create('cash_entries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('group_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $t->uuid('request_key');
            $t->string('fingerprint', 64);
            $t->date('date');
            $t->string('direction', 3);
            $t->string('category', 20);
            $t->unsignedBigInteger('amount');
            $t->string('description', 500);
            $t->timestamps();
            $t->unique(['created_by', 'request_key']);
            $t->index(['group_id', 'date']);
        });
        Schema::create('savings', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_entry_id')->constrained()->restrictOnDelete();
            $t->string('type', 20);
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });
        Schema::create('loans', function (Blueprint $t) {
            $t->id();
            $t->foreignId('member_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->date('disbursed_at');
            $t->unsignedBigInteger('principal');
            $t->unsignedInteger('rate_bps');
            $t->unsignedSmallInteger('months');
            $t->unsignedBigInteger('interest');
            $t->unsignedBigInteger('total');
            $t->timestamps();
        });
        Schema::create('installments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('loan_id')->constrained()->restrictOnDelete();
            $t->unsignedSmallInteger('sequence');
            $t->date('due_date');
            $t->unsignedBigInteger('amount');
            $t->timestamps();
            $t->unique(['loan_id', 'sequence']);
            $t->index('due_date');
        });
        Schema::create('payments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('installment_id')->constrained()->restrictOnDelete();
            $t->foreignId('cash_entry_id')->unique()->constrained()->restrictOnDelete();
            $t->date('paid_at');
            $t->unsignedBigInteger('amount');
            $t->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['payments', 'installments', 'loans', 'savings', 'cash_entries', 'members', 'groups'] as $table) {
            Schema::dropIfExists($table);
        } Schema::table('users', fn (Blueprint $t) => $t->dropColumn('is_admin'));
    }
};
