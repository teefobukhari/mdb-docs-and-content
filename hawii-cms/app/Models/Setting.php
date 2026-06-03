<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Setting extends Model
{
    protected $guarded = [];

    protected $casts = [
        'value' => 'array',
    ];

    public $timestamps = true;

    /** Get a setting value by key (cached). */
    public static function get(string $key, mixed $default = null): mixed
    {
        $all = Cache::rememberForever('settings.all', function () {
            return static::pluck('value', 'key')->toArray();
        });

        return $all[$key] ?? $default;
    }

    /** Get a translatable setting value for the active locale. */
    public static function trans(string $key, ?string $locale = null, mixed $default = null): mixed
    {
        $value = static::get($key);

        if (is_array($value)) {
            $locale ??= app()->getLocale();
            $fallback = config('app.fallback_locale', 'en');

            return $value[$locale] ?? $value[$fallback] ?? $default;
        }

        return $value ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        static::updateOrCreate(['key' => $key], ['value' => $value]);
        Cache::forget('settings.all');
    }

    protected static function booted(): void
    {
        static::saved(fn () => Cache::forget('settings.all'));
        static::deleted(fn () => Cache::forget('settings.all'));
    }
}
