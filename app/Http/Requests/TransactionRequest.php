<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        $base = ['kind' => ['required', Rule::in(['saving', 'loan', 'payment', 'other'])], 'request_key' => 'required|uuid', 'group_id' => 'required|integer|exists:groups,id', 'date' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01|before_or_equal:2100-12-31'];

        return $base + match ($this->input('kind')) {
            'saving' => ['direction' => ['required', Rule::in(['in', 'out'])], 'type' => ['required', Rule::in(['pokok', 'wajib', 'sukarela'])], 'rows' => 'required|array|min:1|max:100', 'rows.*.member_id' => 'required|integer|distinct|exists:members,id', 'rows.*.amount' => 'required|integer|min:1|max:1000000000000'],
            'loan' => ['member_id' => 'required|integer|exists:members,id', 'principal' => 'required|integer|min:1|max:1000000000000', 'rate' => 'required|regex:/^\d{1,3}(\.\d{1,2})?$/|numeric|min:0|max:100', 'months' => 'required|integer|min:1|max:120'],
            'payment' => ['member_id' => 'required|integer|exists:members,id', 'installment_id' => 'required|integer|exists:installments,id', 'amount' => 'required|integer|min:1|max:1000000000000000'],
            'other' => ['direction' => ['required', Rule::in(['in', 'out'])], 'amount' => 'required|integer|min:1|max:1000000000000', 'description' => 'required|string|max:500'],
            default => [],
        };
    }

    public function attributes(): array
    {
        return ['kind' => 'jenis transaksi', 'group_id' => 'kelompok', 'date' => 'tanggal', 'member_id' => 'anggota', 'rows' => 'rincian simpanan', 'rows.*.member_id' => 'anggota pada baris simpanan', 'rows.*.amount' => 'nominal pada baris simpanan', 'amount' => 'nominal', 'principal' => 'pokok pinjaman', 'rate' => 'bunga bulanan', 'months' => 'tenor', 'installment_id' => 'tagihan', 'request_key' => 'kode formulir', 'description' => 'uraian', 'type' => 'jenis simpanan', 'direction' => 'arah kas'];
    }
}
