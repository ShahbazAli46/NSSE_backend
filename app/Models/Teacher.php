<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Teacher extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'salary' => 'decimal:2',
            'allowances' => 'array',
            'date_of_joining' => 'date:Y-m-d',
        ];
    }

    protected $appends = ['profile_picture_url', 'total_allowances', 'gross_salary'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getProfilePictureUrlAttribute(): ?string
    {
        if (!$this->profile_picture) {
            return null;
        }

        if (str_starts_with($this->profile_picture, 'http://') || str_starts_with($this->profile_picture, 'https://')) {
            return $this->profile_picture;
        }

        return Storage::disk('public')->url($this->profile_picture);
    }

    public function getTotalAllowancesAttribute(): float
    {
        if (!is_array($this->allowances)) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($this->allowances as $item) {
            $total += (float) ($item['amount'] ?? 0);
        }

        return $total;
    }

    public function getGrossSalaryAttribute(): float
    {
        return (float) $this->salary + $this->total_allowances;
    }
}
