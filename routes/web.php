<?php

use App\Http\Controllers\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExcelController;
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
    Route::get('/excel/{module}', [ExcelController::class, 'form'])->name('excel.form');
    Route::get('/excel/{module}/template', [ExcelController::class, 'template'])->name('excel.template');
    Route::get('/excel/{module}/export', [ExcelController::class, 'export'])->name('excel.export');
    Route::post('/excel/{module}/preview', [ExcelController::class, 'preview'])->name('excel.preview');
    Route::post('/excel/{module}/commit', [ExcelController::class, 'commit'])->name('excel.commit');
    Route::get('/', [AdminController::class, 'index'])->name('index');
    Route::get('/kelompok/{group}', [AdminController::class, 'groupDetail'])->name('groups.show');
    Route::delete('/kelompok/{group}', [AdminController::class, 'deleteGroup'])->name('groups.destroy');
    Route::get('/anggota/{member}/detail', [AdminController::class, 'memberDetail'])->name('members.show');
    Route::delete('/anggota/{member}', [AdminController::class, 'deleteMember'])->name('members.destroy');
    Route::get('/riwayat/{kind}', [TransactionController::class, 'history'])->name('transactions.history');
    Route::put('/kas/{cash}/bukti', [TransactionController::class, 'proof'])->name('cash.proof');
    Route::get('/kelompok', [AdminController::class, 'groups'])->name('groups');
    Route::post('/kelompok', [AdminController::class, 'saveGroup'])->name('groups.store');
    Route::put('/kelompok/{group}', [AdminController::class, 'saveGroup'])->name('groups.update');
    Route::get('/anggota', [AdminController::class, 'members'])->name('members');
    Route::get('/anggota/tambah', [AdminController::class, 'memberForm'])->name('members.create');
    Route::get('/anggota/{member}/edit', [AdminController::class, 'memberForm'])->name('members.edit');
    Route::get('/pencarian-anggota', [TransactionController::class, 'members'])->name('members.search');
    Route::post('/anggota/banyak', [AdminController::class, 'storeMembers'])->name('members.bulk');
    Route::post('/anggota', [AdminController::class, 'saveMember'])->name('members.store');
    Route::put('/anggota/{member}', [AdminController::class, 'saveMember'])->name('members.update');
    Route::get('/transaksi/{kind}', [TransactionController::class, 'form'])->name('transactions.create');
    Route::post('/transaksi', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/kas/{cash}/edit', [TransactionController::class, 'edit'])->name('cash.edit');
    Route::put('/kas/{cash}', [TransactionController::class, 'update'])->name('cash.update');
    Route::delete('/kas/{cash}', [TransactionController::class, 'cancel'])->withTrashed()->name('cash.cancel');
    Route::get('/kas/{cash}', [TransactionController::class, 'show'])->withTrashed()->name('cash.show');
    Route::get('/kas/{cash}/setoran', [TransactionController::class, 'deposits'])->name('cash.deposits');
    Route::get('/pinjaman', [AdminController::class, 'loans'])->name('loans');
    Route::get('/buku-kas', [ReportController::class, 'ledger'])->name('ledger');
    Route::get('/laporan.xlsx', [ReportController::class, 'download'])->name('report');
});
