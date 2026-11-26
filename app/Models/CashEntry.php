<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CashEntry extends Model
{
    use HasFactory, SoftDeletes;

    protected $attributes = ['version' => 0];

    protected $fillable = ['group_id', 'created_by', 'request_key', 'fingerprint', 'date', 'direction', 'category', 'amount', 'description', 'proof_url'];

    protected function casts(): array
    {
        return ['date' => 'immutable_date', 'amount' => 'integer', 'version' => 'integer'];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(FinancialRevision::class);
    }

    public function savings(): HasMany
    {
        return $this->hasMany(Saving::class);
    }
}
