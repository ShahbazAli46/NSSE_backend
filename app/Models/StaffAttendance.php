<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StaffAttendance extends Model
{
    use HasFactory;

    protected $table = 'staff_attendances';

    protected $fillable = [
        'staff_type',
        'staff_id',
        'user_id',
        'date',
        'status',
        'check_in_time',
        'check_out_time',
        'remarks',
        'marked_by',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
    ];

    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function marker()
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function teacher()
    {
        return $this->belongsTo(Teacher::class, 'staff_id');
    }

    public function staffMember()
    {
        return $this->belongsTo(StaffMember::class, 'staff_id');
    }
}
