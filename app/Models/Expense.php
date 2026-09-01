<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_tier' => 'integer',
            'disbursed_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function cheque(): BelongsTo
    {
        return $this->belongsTo(Cheque::class, 'cheque_id');
    }

    public function director(): BelongsTo
    {
        return $this->belongsTo(Director::class, 'director_id');
    }

    /**
     * Compute tier index (1 to 4), label, and color for an expense.
     */
    public static function resolveTier(float $amount): array
    {
        if ($amount < 5000) {
            return [
                'tier' => 1,
                'color' => 'green',
                'label' => 'Micro / Petty (< 5k)',
                'hex' => '#10B981',
            ];
        } elseif ($amount <= 25000) {
            return [
                'tier' => 2,
                'color' => 'blue',
                'label' => 'Operational (5k - 25k)',
                'hex' => '#0284C7',
            ];
        } elseif ($amount <= 50000) {
            return [
                'tier' => 3,
                'color' => 'orange',
                'label' => 'Moderate (25k - 50k)',
                'hex' => '#F59E0B',
            ];
        } else {
            return [
                'tier' => 4,
                'color' => 'red',
                'label' => 'High Value (> 50k)',
                'hex' => '#EF4444',
            ];
        }
    }
}
