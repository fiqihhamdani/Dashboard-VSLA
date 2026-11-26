<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Saving extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['member_id', 'cash_entry_id', 'type', 'amount'];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function cashEntry(): BelongsTo
    {
        return $this->belongsTo(CashEntry::class);
    }
}
