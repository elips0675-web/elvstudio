<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\Rss;
use Elv\Kit\Classes\Seo;
use Elv\Kit\Classes\Sitemap;
use Illuminate\Support\Facades\Cache;

/**
 * SeoController — sitemap, robots, llms.txt и RSS.
 *
 * Файлы собираются из базы и темы, поэтому кэш короткий: новая статья
 * должна появляться в карте сайта в тот же день, а не «после деплоя».
 */
class SeoController
{
    public function sitemap()
    {
        return $this->text($this->cached('elv-kit-sitemap', fn () => Sitemap::xml()), 'application/xml');
    }

    public function robots()
    {
        return $this->text($this->cached('elv-kit-robots', fn () => Seo::robots()), 'text/plain');
    }

    public function llms()
    {
        return $this->text($this->cached('elv-kit-llms', fn () => Seo::llms()), 'text/plain');
    }

    public function rss()
    {
        return $this->text($this->cached('elv-kit-rss', fn () => Rss::xml()), 'application/rss+xml');
    }

    protected function cached(string $key, callable $callback): string
    {
        if (app()->environment('local') && !app()->environment('production')) {
            return $callback();
        }

        return (string) Cache::remember($key, 1800, $callback);
    }

    protected function text(string $body, string $type)
    {
        return response($body, 200, [
            'Content-Type' => $type . '; charset=UTF-8',
            'Cache-Control' => 'public, max-age=1800',
        ]);
    }
}
