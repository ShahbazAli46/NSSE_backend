<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cheque extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'issue_date' => 'date:Y-m-d',
            'cashed_date' => 'date:Y-m-d',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * Compute tier index (1 to 4) and color for any amount.
     * 
     * Tier 1 (Green):  < 5,000 PKR
     * Tier 2 (Blue):   5,000 to 25,000 PKR
     * Tier 3 (Orange): 25,000 to 50,000 PKR
     * Tier 4 (Red):    > 50,000 PKR
     */
    public static function resolveTier(float $amount): array
    {
        if ($amount < 5000) {
            return [
                'tier' => 1,
                'color' => 'green',
                'label' => 'Micro / Petty (< 5k)',
                'hex' => '#10B981', // emerald-500
            ];
        } elseif ($amount <= 25000) {
            return [
                'tier' => 2,
                'color' => 'blue',
                'label' => 'Operational (5k - 25k)',
                'hex' => '#0284C7', // sky-600
            ];
        } elseif ($amount <= 50000) {
            return [
                'tier' => 3,
                'color' => 'orange',
                'label' => 'Moderate (25k - 50k)',
                'hex' => '#F59E0B', // amber-500
            ];
        } else {
            return [
                'tier' => 4,
                'color' => 'red',
                'label' => 'High Value (> 50k)',
                'hex' => '#EF4444', // red-500
            ];
        }
    }
}
