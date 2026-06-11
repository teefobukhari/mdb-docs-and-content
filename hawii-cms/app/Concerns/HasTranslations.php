<?php

namespace App\Concerns;

/**
 * Lightweight translation helper for JSON {en, ar} columns.
 *
 * Each translatable attribute is cast to an array (see each model's $casts).
 * Call $model->t('title') to get the value for the active app locale, with a
 * graceful fallback to the configured fallback locale and then any value.
 */
trait HasTranslations
{
    public function t(string $attribute, ?string $locale = null): ?string
    {
        $value = $this->getAttribute($attribute);

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        $locale ??= app()->getLocale();
        $fallback = config('app.fallback_locale', 'en');

        return $value[$locale]
            ?? $value[$fallback]
            ?? (count($value) ? reset($value) : null);
    }
}
