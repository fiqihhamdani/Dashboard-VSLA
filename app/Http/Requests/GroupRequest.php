<?php

namespace App\Http\Requests;

use App\Rules\GoogleDriveUrl;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->is_admin ?? false;
    }

    public function rules(): array
    {
        return ['name' => 'required|string|max:255', 'code' => ['required', 'string', 'max:40', Rule::unique('groups', 'code')->ignore($this->route('group')?->id)], 'location' => 'required|string|max:255', 'is_active' => 'required|boolean', 'legality_url' => ['nullable', 'string', 'max:2048', new GoogleDriveUrl]];
    }

    public function attributes(): array
    {
        return ['name' => 'nama kelompok', 'code' => 'kode kelompok', 'location' => 'lokasi', 'is_active' => 'status', 'legality_url' => 'link legalitas Google Drive'];
    }
}
