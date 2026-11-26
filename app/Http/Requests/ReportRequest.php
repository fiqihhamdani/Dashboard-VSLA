<?php

namespace App\Http\Requests;

use App\Models\CashEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->routeIs('admin.report') && $this->input('scope') === 'all') {
            $this->merge(['group_id' => null, 'start' => CashEntry::min('date') ?? now()->startOfYear()->toDateString(), 'end' => CashEntry::max('date') ?? now()->toDateString()]);
        }
        $this->merge(['start' => $this->input('start', now()->startOfYear()->toDateString()), 'end' => $this->input('end', now()->toDateString())]);
    }

    public function rules(): array
    {
        return ['group_id' => 'nullable|integer|exists:groups,id', 'start' => 'required|date_format:Y-m-d|after_or_equal:1900-01-01', 'end' => 'required|date_format:Y-m-d|after_or_equal:start|before_or_equal:2100-12-31'];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->any() && CarbonImmutable::parse($this->input('start'))->diffInMonths(CarbonImmutable::parse($this->input('end'))) > 120) {
                $validator->errors()->add('end', 'Pilih periode maksimal 10 tahun.');
            }
        }];
    }

    public function attributes(): array
    {
        return ['start' => 'tanggal mulai', 'end' => 'tanggal akhir', 'group_id' => 'kelompok'];
    }
}
