<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        // ?lang=ar switches and persists; otherwise use the stored choice.
        if ($request->has('lang') && in_array($request->query('lang'), self::SUPPORTED, true)) {
            session(['locale' => $request->query('lang')]);
        }

        $locale = session('locale', config('app.locale'));
        if (! in_array($locale, self::SUPPORTED, true)) {
            $locale = 'en';
        }

        app()->setLocale($locale);

        return $next($request);
    }
}
