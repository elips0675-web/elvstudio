<?php namespace Elv\Kit\Classes;

use Closure;
use Elv\Kit\Models\Settings;

/**
 * SecurityHeaders — CSP с nonce и базовый периметр для публичных страниц.
 *
 * Бэкенд October не трогаем: у него своя CSP-политика и свои inline-сценарии.
 * Заголовки статики на cPanel отдаёт Apache — дублируются в .htaccess темы.
 */
class SecurityHeaders
{
    protected static $nonce = null;

    public static function nonce(): string
    {
        if (static::$nonce === null) {
            static::$nonce = rtrim(strtr(base64_encode(random_bytes(18)), '+/', '-_'), '=');
        }

        return static::$nonce;
    }

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ($request->is(
            'admin', 'admin/*',
            'modules/system', 'modules/system/*',
            'modules/backend', 'modules/backend/*',
            'modules/cms', 'modules/cms/*',
            'modules/editor', 'modules/editor/*',
            'modules/media', 'modules/media/*',
            'modules/tailor', 'modules/tailor/*',
            'modules/dashboard', 'modules/dashboard/*',
            'system/*', 'combine/*'
        )) {
            return $response;
        }

        $nonce = static::nonce();
        $analytics = $this->analyticsSources();

        $csp = implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
            "img-src 'self' data:",
            "font-src 'self'",
            "style-src 'self' 'nonce-" . $nonce . "'",
            "script-src 'self' 'nonce-" . $nonce . "'" . $analytics,
            "connect-src 'self'" . $analytics,
            "object-src 'none'",
            'upgrade-insecure-requests',
        ]);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    protected function analyticsSources(): string
    {
        $sources = '';

        if (Settings::get('analytics_google')) {
            $sources .= ' https://www.googletagmanager.com https://www.google-analytics.com';
        }

        if (Settings::get('analytics_yandex')) {
            $sources .= ' https://mc.yandex.ru https://mc.yandex.com';
        }

        return $sources;
    }
}
