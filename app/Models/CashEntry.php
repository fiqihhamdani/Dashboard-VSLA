<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashEntry extends Model
{
    use HasFactory;

    protected $fillable = ['group_id', 'created_by', 'request_key', 'fingerprint', 'date', 'direction', 'category', 'amount', 'description'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'amount' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function savings(): HasMany
    {
        return $this->hasMany(Saving::class);
    }
}
