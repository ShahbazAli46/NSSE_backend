<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentAttendance extends Model
{
    use HasFactory;

    protected $table = 'student_attendances';

    protected $fillable = [
        'student_id',
        'date',
        'session',
        'class',
        'section',
        'status',
        'teacher_name',
        'teacher_id',
        'remarks',
        'follow_up_status',
        'follow_up_reason',
        'follow_up_notes',
        'followed_up_by',
        'followed_up_at',
        'whatsapp_status',
        'whatsapp_msg_id',
        'whatsapp_sent_at',
        'whatsapp_error',
        'marked_by',
        'marked_at',
    ];

    protected $casts = [
        'date' => 'date:Y-m-d',
        'marked_at' => 'datetime',
        'followed_up_at' => 'datetime',
        'whatsapp_sent_at' => 'datetime',
    ];

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'student_id');
    }

    public function marker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    public function followedUpByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'followed_up_by');
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class, 'teacher_id');
    }

    public function scopeForDate($query, $date)
    {
        return $query->where('date', $date);
    }

    public function scopeForClass($query, $class)
    {
        if ($class && $class !== 'all') {
            return $query->where('class', $class);
        }
        return $query;
    }

    public function scopeForSection($query, $section)
    {
        if ($section && $section !== 'all') {
            return $query->where('section', $section);
        }
        return $query;
    }

    public function scopeForSession($query, $session)
    {
        if ($session && $session !== 'all') {
            return $query->where('session', $session);
        }
        return $query;
    }
}
