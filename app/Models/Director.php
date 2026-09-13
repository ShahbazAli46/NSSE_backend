<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Director extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'share_percentage' => 'decimal:2',
            'expected_monthly_contribution' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(DirectorTransaction::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class, 'director_id');
    }

    /**
     * Compute current net running available capital balance for this director.
     * Capital additions include direct cash injections and expenses paid by the director out-of-pocket.
     */
    public function getLiveBalanceAttribute(): float
    {
        $capitalInjected = (float) $this->transactions()->whereIn('type', ['capital_injection', 'capital_expense'])->sum('amount');
        $profit = (float) $this->transactions()->where('type', 'profit_credit')->sum('amount');
        $withdrawn = (float) $this->transactions()->where('type', 'withdrawal')->sum('amount');
        
        return max(0, ($capitalInjected + $profit) - $withdrawn);
    }
}
