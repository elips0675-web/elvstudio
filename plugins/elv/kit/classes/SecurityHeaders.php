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
        $ancestors = static::frameAncestors();

        $cspParts = [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors " . $ancestors,
            "frame-src 'self' https:" . (app()->environment('production') ? '' : ' http:'),
            "img-src 'self' data:",
            "font-src 'self'",
            "style-src 'self' 'nonce-" . $nonce . "'",
            "script-src 'self' 'nonce-" . $nonce . "'" . $analytics,
            "connect-src 'self'" . $analytics,
            "object-src 'none'",
        ];

        if ($request->isSecure() || app()->environment('production')) {
            $cspParts[] = 'upgrade-insecure-requests';
        }

        $csp = implode('; ', $cspParts);

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        // X-Frame-Options не умеет список источников: при встраивании на чужой
        // домене он бы всё заблокировал, поэтому остаётся только для 'self'.
        if ($ancestors === "'self'") {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }
        else {
            $response->headers->remove('X-Frame-Options');
        }
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'geolocation=(), microphone=(), camera=(), payment=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        header_remove('X-Powered-By');
        $response->headers->remove('X-Powered-By');

        // Живой предпросмотр и кабинет не индексируются и не кэшируются.
        if ($request->is('preview', 'preview/*', 'account', 'account/*', 'tickets', 'tickets/*')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
            $response->headers->set('Cache-Control', 'no-store, max-age=0, private');
        }

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    /**
     * frameAncestors — источники, которым разрешено встраивать сайт в iframe.
     *
     * По умолчанию только свой домен (`'self'`). Нишевые демо-стенды живут на
     * отдельных поддоменах и показываются внутри `/preview/` на домене витрины,
     * поэтому родительские источники перечисляются в `ELV_FRAME_ANCESTORS`
     * через пробел: `ELV_FRAME_ANCESTORS="https://example.com"`.
     *
     * Принимаются только origin'ы вида `http(s)://host[:port]`: строка вида
     * `*` или мусор не должна открывать сайт для встраивания куда угодно.
     */
    public static function frameAncestors(): string
    {
        $raw = trim((string) (env('ELV_FRAME_ANCESTORS') ?: ''));
        $sources = [];

        foreach (preg_split('~\s+~', $raw) ?: [] as $source) {
            $source = rtrim(trim($source), '/');

            if ($source === '' || !preg_match('~^https?://[a-z0-9.\-:\[\]]+$~i', $source)) {
                continue;
            }

            $sources[] = strtolower($source);
        }

        if ($sources === []) {
            return "'self'";
        }

        return implode(' ', array_merge(["'self'"], array_unique($sources)));
    }

    protected function analyticsSources(): string    {
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
