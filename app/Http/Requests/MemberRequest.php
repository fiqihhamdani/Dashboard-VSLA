<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:255', 'group_id' => 'required|integer|exists:groups,id', 'is_active' => 'required|boolean'];
    }

    public function attributes(): array
    {
        return ['name' => 'nama anggota', 'group_id' => 'kelompok', 'is_active' => 'status'];
    }
}
