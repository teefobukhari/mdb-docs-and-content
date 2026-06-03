<?php

namespace App\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

class Industry extends Model
{
    use HasTranslations;

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'description' => 'array',
        'is_published' => 'boolean',
    ];

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
