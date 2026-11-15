<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Installment extends Model
{
    use HasFactory;

    protected $fillable = ['loan_id', 'sequence', 'due_date', 'amount'];

    protected function casts(): array
    {
        return ['due_date' => 'immutable_date', 'amount' => 'integer'];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function remaining(): int
    {
        return $this->amount - (int) $this->payments()->sum('amount');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('installments.amount', '>', Payment::query()
            ->selectRaw('COALESCE(SUM(payments.amount), 0)')
            ->whereColumn('payments.installment_id', 'installments.id'));
    }
}
