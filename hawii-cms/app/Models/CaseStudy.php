<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class CaseStudy extends Model
{
    use HasTranslations;

    protected $table = 'case_studies';

    protected $guarded = [];

    protected $casts = [
        'client' => 'array',
        'title' => 'array',
        'summary' => 'array',
        'body' => 'array',
        'industry' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
