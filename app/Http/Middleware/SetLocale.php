<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the locale on every request (web, Inertia and API).
 *
 * Signed-in users: their saved preferred_locale always wins, and the session copy is
 * brought in line with it, so a stale session/guest choice (e.g. 'en' picked on the
 * login page) can never render the app in the wrong language after login.
 * Guests: session choice, then config('app.locale') (APP_LOCALE), then Accept-Language.
 * The deployment default deliberately beats the browser header, so a fresh guest sees /login in
 * the organisation's language; Accept-Language only applies if APP_LOCALE is unset/unsupported.
 */
class SetLocale
{
    /** @var list<string> */
    public const ALLOWED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());
        // The language depends on Accept-Language (guests) and the session cookie (users),
        // so shared caches must key on both. Merge with any Vary already set.
        $response->setVary(['Accept-Language', 'Cookie'], false);

        return $response;
    }

    protected function resolve(Request $request): string
    {
        $session = $request->hasSession() ? $request->session() : null;
        $userLocale = $this->userLocale($request);

        if ($userLocale !== null) {
            if ($session && $session->get('locale') !== $userLocale) {
                $session->put('locale', $userLocale);
            }

            return $userLocale;
        }

        $candidates = [
            $session?->get('locale'),
            config('app.locale'),
            $request->hasHeader('Accept-Language') ? $request->getPreferredLanguage(self::ALLOWED) : null,
        ];

        foreach ($candidates as $candidate) {
            if (self::allowed($candidate)) {
                return $candidate;
            }
        }

        return 'en';
    }

    /**
     * The session (web) user, or a Sanctum bearer-token user on API routes. The token user is
     * looked up here because route-level auth:sanctum runs after this group middleware.
     */
    protected function userLocale(Request $request): ?string
    {
        $user = $request->user();
        if (! $user && config('auth.guards.sanctum')) {
            try {
                $user = $request->user('sanctum');
            } catch (\Throwable) {
                $user = null;
            }
        }

        $locale = $user?->preferred_locale;

        return self::allowed($locale) ? $locale : null;
    }

    public static function allowed(mixed $locale): bool
    {
        return is_string($locale) && in_array($locale, self::ALLOWED, true);
    }
}
