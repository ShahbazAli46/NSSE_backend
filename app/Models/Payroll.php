<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Payroll extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'allowances' => 'array',
        'bonuses' => 'array',
        'custom_deductions' => 'array',
        'base_salary' => 'decimal:2',
        'total_allowances' => 'decimal:2',
        'total_bonuses' => 'decimal:2',
        'gross_salary' => 'decimal:2',
        'allowed_leaves' => 'integer',
        'deductible_days' => 'decimal:2',
        'daily_rate' => 'decimal:2',
        'absent_deduction' => 'decimal:2',
        'advance_deduction' => 'decimal:2',
        'other_deductions' => 'decimal:2',
        'net_salary' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getStaffAttribute()
    {
        if ($this->staff_type === 'teacher') {
            return Teacher::find($this->staff_id);
        }
        return StaffMember::find($this->staff_id);
    }
}
