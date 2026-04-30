<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetRequestLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolveLocale($request));

        return $next($request);
    }

    protected function resolveLocale(Request $request): string
    {
        $supportedLocales = ['es_CO', 'en'];
        $defaultLocale = (string) config('app.locale', 'es_CO');

        if (! $request->headers->has('Accept-Language')) {
            return in_array($defaultLocale, $supportedLocales, true)
                ? $defaultLocale
                : 'es_CO';
        }

        foreach ($request->getLanguages() as $language) {
            $normalized = str_replace('-', '_', strtolower($language));

            if (str_starts_with($normalized, 'es')) {
                return 'es_CO';
            }

            if (str_starts_with($normalized, 'en')) {
                return 'en';
            }
        }

        return in_array($defaultLocale, $supportedLocales, true)
            ? $defaultLocale
            : 'es_CO';
    }
}
