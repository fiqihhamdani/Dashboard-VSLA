<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Payment extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['installment_id', 'cash_entry_id', 'paid_at', 'amount'];

    protected function casts(): array
    {
        return ['paid_at' => 'immutable_date', 'amount' => 'integer'];
    }

    public function installment(): BelongsTo
    {
        return $this->belongsTo(Installment::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }
}
