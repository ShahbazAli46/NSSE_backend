<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Student extends Model
{
    use HasFactory;

    protected $fillable = [
        'registration_no',
        'student_name',
        'father_name',
        'b_form_no',
        'class',
        'session',
        'section',
        'gender',
        'admission_date',
        'mobile_no',
        'photo_path',
        'status',
        'notes',
        'created_by',
    ];

    protected $casts = [
        'admission_date' => 'date:Y-m-d',
    ];

    protected $appends = [
        'photo_url',
    ];

    /**
     * Get full public URL for the student photograph.
     */
    public function getPhotoUrlAttribute(): ?string
    {
        if (!$this->photo_path) {
            return null;
        }

        return asset('storage/' . $this->photo_path);
    }

    /**
     * User who imported or registered this student.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Scope to filter by academic session.
     */
    public function scopeBySession($query, ?string $session)
    {
        if ($session && $session !== 'all') {
            return $query->where('session', $session);
        }
        return $query;
    }

    /**
     * Scope to filter by class name.
     */
    public function scopeByClass($query, ?string $class)
    {
        if ($class && $class !== 'all') {
            return $query->where('class', $class);
        }
        return $query;
    }

    /**
     * Scope to filter by gender.
     */
    public function scopeByGender($query, ?string $gender)
    {
        if ($gender && $gender !== 'all') {
            return $query->where('gender', $gender);
        }
        return $query;
    }

    /**
     * Scope to filter by section.
     */
    public function scopeBySection($query, ?string $section)
    {
        if ($section && $section !== 'all') {
            return $query->where('section', $section);
        }
        return $query;
    }

    /**
     * Search across name, registration_no, b_form_no, or father_name (prefix matching).
     */
    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        $term = trim($search);

        return $query->where(function ($q) use ($term) {
            $q->where('student_name', 'like', "{$term}%")
              ->orWhere('student_name', 'like', "% {$term}%")
              ->orWhere('father_name', 'like', "{$term}%")
              ->orWhere('father_name', 'like', "% {$term}%")
              ->orWhere('registration_no', 'like', "{$term}%")
              ->orWhere('b_form_no', 'like', "{$term}%")
              ->orWhere('mobile_no', 'like', "{$term}%");
        });
    }
}
