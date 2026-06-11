<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Share locale + global brand/contact settings with every public view.
        View::composer('layouts.public', function ($view) {
            $locale = app()->getLocale();

            $view->with([
                'locale' => $locale,
                'dir' => $locale === 'ar' ? 'rtl' : 'ltr',
                'altLocale' => $locale === 'ar' ? 'en' : 'ar',
                'altLabel' => $locale === 'ar' ? 'English' : 'العربية',
                'brandColor' => Setting::get('brand_color', '#2563EB'),
                'accentColor' => Setting::get('accent_color', '#F97316'),
                'siteName' => Setting::trans('site_name', $locale, 'Hawii.tech'),
                'footerNote' => Setting::trans('footer_note', $locale, ''),
                'contactEmail' => Setting::get('contact_email'),
                'contactPhone' => Setting::get('contact_phone'),
                'social' => [
                    'linkedin' => Setting::get('social_linkedin'),
                    'x' => Setting::get('social_x'),
                    'github' => Setting::get('social_github'),
                ],
            ]);
        });
    }
}
