<?php namespace Elv\Kit\Classes;

use Closure;

/**
 * CompressResponse — gzip для публичных HTML-ответов.
 *
 * Работает только на фронтенде: бэкенд October и его ассеты отдаются как есть,
 * статику локально сжимает `tools/serve-static.php`, а на cPanel — nginx/Apache
 * (`deflate` в .htaccess). Здесь закрыт единственный случай, который не покрыт
 * ни тем, ни другим: HTML, собранный October.
 */
class CompressResponse
{
    protected $skip = [
        'admin',
        'admin/*',
        'modules/system/*',
        'modules/backend/*',
        'modules/cms/*',
        'modules/editor/*',
        'modules/media/*',
        'modules/tailor/*',
        'modules/dashboard/*',
        'combine/*',
    ];

    public function handle($request, Closure $next)
    {
        $response = $next($request);

        if ($request->is($this->skip) || !method_exists($response, 'getContent')) {
            return $response;
        }

        if ($response->getStatusCode() !== 200 || $request->isMethod('HEAD')) {
            return $response;
        }

        if ($response->headers->has('Content-Encoding')) {
            return $response;
        }

        if (stripos((string) $request->headers->get('Accept-Encoding', ''), 'gzip') === false) {
            return $response;
        }

        $content = $response->getContent();

        if (!is_string($content) || strlen($content) < 1024) {
            return $response;
        }

        $compressed = gzencode($content, 6);

        if ($compressed === false || strlen($compressed) >= strlen($content)) {
            return $response;
        }

        $response->setContent($compressed);
        $response->headers->set('Content-Encoding', 'gzip');
        $response->headers->set('Vary', 'Accept-Encoding');
        $response->headers->remove('Content-Length');

        return $response;
    }
}
