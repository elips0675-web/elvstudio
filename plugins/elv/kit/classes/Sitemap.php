<?php namespace Elv\Kit\Classes;

use Cms\Classes\Page;
use Cms\Classes\Theme;
use Elv\Kit\Models\Post;
use Elv\Kit\Models\Product;

/**
 * Sitemap — карта сайта из живых страниц темы и записей БД.
 */
class Sitemap
{
    public static function xml(): string
    {
        $urls = [];
        $base = Seo::base();

        foreach (static::pages() as $page) {
            $urls[rtrim((string) $page['loc'], '/')] = $page['lastmod'];
        }

        foreach (Product::query()->active()->get() as $product) {
            $urls[rtrim((string) $product->url, '/')] = optional($product->updated_at)->format('c') ?: now()->format('c');
        }

        foreach (Post::query()->published()->get() as $post) {
            $urls[rtrim((string) $post->url, '/')] = optional($post->updated_at)->format('c') ?: now()->format('c');
        }

        ksort($urls);

        $lines = [
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
        ];

        foreach ($urls as $loc => $lastmod) {
            if (!str_starts_with((string) $loc, 'http')) {
                $loc = $base . '/' . ltrim((string) $loc, '/');
            }

            $lines[] = '  <url>';
            $lines[] = '    <loc>' . htmlspecialchars((string) $loc, ENT_XML1) . '</loc>';
            $lines[] = '    <lastmod>' . htmlspecialchars((string) $lastmod, ENT_XML1) . '</lastmod>';
            $lines[] = '  </url>';
        }

        $lines[] = '</urlset>';

        return implode("\n", $lines);
    }

    /**
     * pages — статические страницы темы. Шаблоны с параметрами (/blog/:slug)
     * в карту не попадают: их адреса приходят из БД.
     */
    public static function pages(): array
    {
        $result = [];

        try {
            $theme = Theme::getActiveTheme();
            $pages = Page::listInTheme($theme, true);
        }
        catch (\Throwable $e) {
            return $result;
        }

        foreach ($pages as $page) {
            $url = trim((string) ($page->url ?? ''));

            if ($url === '' || str_contains($url, ':') || str_contains($url, '404')) {
                continue;
            }

            if (!empty($page->hidden)) {
                continue;
            }

            if (str_contains(strtolower((string) ($page->meta_robots ?? '')), 'noindex')) {
                continue;
            }

            $file = method_exists($page, 'getFilePath') ? $page->getFilePath() : null;
            $mtime = $file && is_file($file) ? filemtime($file) : time();

            $result[] = [
                'loc' => Seo::base() . '/' . ltrim($url, '/'),
                'lastmod' => date('c', $mtime),
            ];
        }

        return $result;
    }
}
