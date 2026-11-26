<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Loan extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['member_id', 'cash_entry_id', 'disbursed_at', 'principal', 'rate_bps', 'months', 'interest', 'total'];

    protected function casts(): array
    {
        return ['disbursed_at' => 'immutable_date', 'principal' => 'integer', 'rate_bps' => 'integer', 'months' => 'integer', 'interest' => 'integer', 'total' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }

    public function installments(): HasMany
    {
        return $this->hasMany(Installment::class);
    }
}
