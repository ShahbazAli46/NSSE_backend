<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SchoolSection extends Model
{
    use HasFactory;

    protected $table = 'school_sections';

    protected $fillable = [
        'name',
        'class',
        'session',
        'capacity',
        'created_by',
    ];

    /**
     * Students enrolled in this section.
     */
    public function students(): HasMany
    {
        return $this->hasMany(Student::class, 'section', 'name')
            ->where('class', $this->class)
            ->where('session', $this->session);
    }

    /**
     * User who created this section.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
