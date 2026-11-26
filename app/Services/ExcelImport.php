<?php

namespace App\Services;

use App\Http\Requests\GroupRequest;
use App\Http\Requests\MemberRequest;
use App\Http\Requests\TransactionRequest;
use App\Models\CashEntry;
use App\Models\Group;
use App\Models\Member;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ExcelImport
{
    public const HEADERS = [
        'groups' => ['kode_kelompok', 'nama', 'lokasi', 'aktif', 'link_legalitas'],
        'members' => ['kelompok_id', 'nama', 'aktif'],
        'saving' => ['kode_transaksi', 'kelompok_id', 'tanggal', 'anggota_id', 'jenis', 'arah', 'nominal'],
        'loan' => ['kode_transaksi', 'kelompok_id', 'tanggal', 'anggota_id', 'pokok', 'bunga_bulanan', 'tenor', 'link_bukti'],
        'payment' => ['kode_transaksi', 'kelompok_id', 'tanggal', 'anggota_id', 'tagihan_id', 'nominal'],
        'other' => ['kode_transaksi', 'kelompok_id', 'tanggal', 'arah', 'nominal', 'uraian'],
    ];

    public function preview(string $module, array $rows, User $admin): array
    {
        $errors = [];
        DB::beginTransaction();
        try {
            foreach ($rows as $row) {
                try {
                    $this->store($module, $row['data'], $admin);
                } catch (ValidationException $exception) {
                    $errors[$row['number']] = array_merge(...array_values($exception->errors()));
                }
            }
        } finally {
            DB::rollBack();
        }

        return $errors;
    }

    public function commit(string $module, array $rows, User $admin): void
    {
        DB::transaction(function () use ($module, $rows, $admin): void {
            foreach ($rows as $row) {
                try {
                    $this->store($module, $row['data'], $admin);
                } catch (ValidationException $exception) {
                    throw ValidationException::withMessages(['file' => 'Baris '.$row['number'].': '.implode(' ', array_merge(...array_values($exception->errors())))]);
                }
            }
        }, 3);
    }

    private function store(string $module, array $row, User $admin): void
    {
        if ($module === 'groups') {
            $data = ['code' => $row['kode_kelompok'], 'name' => $row['nama'], 'location' => $row['lokasi'], 'is_active' => $row['aktif'], 'legality_url' => $row['link_legalitas'] ?: null];
            Group::create(Validator::make($data, (new GroupRequest)->rules())->validate());

            return;
        }
        if ($module === 'members') {
            $data = Validator::make(['group_id' => $row['kelompok_id'], 'name' => $row['nama'], 'is_active' => $row['aktif']], (new MemberRequest)->rules())->validate();
            Group::whereKey($data['group_id'])->lockForUpdate()->firstOrFail();
            if (Member::where('group_id', $data['group_id'])->whereRaw('LOWER(name) = ?', [mb_strtolower($data['name'])])->exists()) {
                throw ValidationException::withMessages(['name' => 'Nama anggota sudah ada dalam kelompok yang sama. Import tidak menimpa data lama.']);
            }
            Member::create($data);

            return;
        }
        $data = ['kind' => $module, 'request_key' => $row['kode_transaksi'], 'group_id' => $row['kelompok_id'], 'date' => $row['tanggal']];
        $data += match ($module) {
            'saving' => ['type' => $row['jenis'], 'direction' => $row['arah'], 'rows' => [['member_id' => $row['anggota_id'], 'amount' => $row['nominal']]]],
            'loan' => ['member_id' => $row['anggota_id'], 'principal' => $row['pokok'], 'rate' => $row['bunga_bulanan'], 'months' => $row['tenor'], 'proof_url' => $row['link_bukti'] ?: null],
            'payment' => ['member_id' => $row['anggota_id'], 'installment_id' => $row['tagihan_id'], 'amount' => $row['nominal']],
            'other' => ['direction' => $row['arah'], 'amount' => $row['nominal'], 'description' => $row['uraian']],
        };
        $request = new TransactionRequest($data);
        $validated = Validator::make($data, $request->rules(), [], $request->attributes())->validate();
        User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
        if (CashEntry::withTrashed()->where('created_by', $admin->id)->where('request_key', $validated['request_key'])->exists()) {
            throw ValidationException::withMessages(['request_key' => 'Kode transaksi sudah digunakan oleh admin ini. Import ulang ditolak agar tidak mencatat kas ganda.']);
        }
        app(TransactionService::class)->store($admin, $validated);
    }
}
