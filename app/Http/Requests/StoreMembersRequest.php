<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMembersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return [
            'group_id' => ['required', 'integer', 'exists:groups,id'],
            'is_active' => ['required', 'boolean'],
            'members' => ['required', 'array', 'min:1', 'max:100'],
            'members.*' => ['required', 'array:name'],
            'members.*.name' => ['required', 'string', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['group_id' => 'kelompok', 'is_active' => 'status semua anggota', 'members' => 'daftar anggota', 'members.*.name' => 'nama anggota'];
    }

    public function messages(): array
    {
        return [
            'members.max' => 'Maksimal 100 anggota dalam satu kali penyimpanan.',
            'members.*.name.required' => 'Nama anggota pada baris :position wajib diisi.',
            'members.*.name.string' => 'Nama anggota pada baris :position harus berupa teks.',
            'members.*.name.max' => 'Nama anggota pada baris :position maksimal 255 karakter.',
        ];
    }
}
