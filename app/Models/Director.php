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
     */
    public function getLiveBalanceAttribute(): float
    {
        $injected = (float) $this->transactions()->where('type', 'capital_injection')->sum('amount');
        $profit = (float) $this->transactions()->where('type', 'profit_credit')->sum('amount');
        $consumed = (float) $this->transactions()->where('type', 'capital_expense')->sum('amount');
        $withdrawn = (float) $this->transactions()->where('type', 'withdrawal')->sum('amount');
        
        return max(0, ($injected + $profit) - ($consumed + $withdrawn));
    }
}
