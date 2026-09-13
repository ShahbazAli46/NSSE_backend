<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class StaffMember extends Model
{
    use HasFactory;

    protected $table = 'staff_members';

    protected $fillable = [
        'user_id',
        'name',
        'designation',
        'father_name',
        'cnic',
        'contact_number',
        'emergency_number',
        'address',
        'city',
        'date_of_joining',
        'salary',
        'allowances',
        'payment_method',
        'bank_name',
        'account_title',
        'account_number',
        'status',
        'profile_picture',
        'created_by',
    ];

    protected $casts = [
        'salary' => 'decimal:2',
        'allowances' => 'array',
        'date_of_joining' => 'date',
    ];

    protected $appends = [
        'profile_picture_url',
        'total_allowances',
        'gross_salary',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function attendances()
    {
        return $this->hasMany(StaffAttendance::class, 'staff_id')
            ->where('staff_type', 'non_teaching');
    }

    public function getProfilePictureUrlAttribute()
    {
        if (!$this->profile_picture) {
            return null;
        }
        return Storage::disk('public')->url($this->profile_picture);
    }

    public function getTotalAllowancesAttribute()
    {
        if (empty($this->allowances) || !is_array($this->allowances)) {
            return 0;
        }

        return (float) array_reduce($this->allowances, function ($carry, $item) {
            return $carry + (float) ($item['amount'] ?? 0);
        }, 0);
    }

    public function getGrossSalaryAttribute()
    {
        return (float) ($this->salary ?? 0) + $this->total_allowances;
    }
}
