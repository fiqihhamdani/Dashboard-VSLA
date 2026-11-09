<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Middleware\EnsureAdmin;
use Illuminate\Support\Facades\Route;

Route::get('/', DashboardController::class)->name('dashboard');
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'form'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login')->name('login.store');
});
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');
Route::prefix('admin')->name('admin.')->middleware(['auth', EnsureAdmin::class])->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/kelompok', [AdminController::class, 'groups'])->name('groups');
    Route::post('/kelompok', [AdminController::class, 'saveGroup'])->name('groups.store');
    Route::put('/kelompok/{group}', [AdminController::class, 'saveGroup'])->name('groups.update');
    Route::get('/anggota', [AdminController::class, 'members'])->name('members');
    Route::post('/anggota', [AdminController::class, 'saveMember'])->name('members.store');
    Route::put('/anggota/{member}', [AdminController::class, 'saveMember'])->name('members.update');
    Route::get('/transaksi/{kind}', [TransactionController::class, 'form'])->name('transactions.create');
    Route::post('/transaksi', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/kas/{cash}', [TransactionController::class, 'show'])->name('cash.show');
    Route::get('/pinjaman', [AdminController::class, 'loans'])->name('loans');
    Route::get('/buku-kas', [ReportController::class, 'ledger'])->name('ledger');
    Route::get('/laporan.xlsx', [ReportController::class, 'download'])->name('report');
});
