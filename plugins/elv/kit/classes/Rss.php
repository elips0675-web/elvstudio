<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Post;

/**
 * Rss — лента блога, 20 последних опубликованных статей.
 */
class Rss
{
    public static function xml(): string
    {
        $base = Seo::base();
        $items = [];

        foreach (Post::query()->published()->limit(20)->get() as $post) {
            $items[] = '    <item>';
            $items[] = '      <title>' . htmlspecialchars((string) $post->title, ENT_XML1) . '</title>';
            $items[] = '      <link>' . htmlspecialchars((string) $post->url, ENT_XML1) . '</link>';
            $items[] = '      <guid isPermaLink="true">' . htmlspecialchars((string) $post->url, ENT_XML1) . '</guid>';
            $items[] = '      <description>' . htmlspecialchars(trim(strip_tags((string) $post->excerpt)), ENT_XML1) . '</description>';
            $items[] = '      <pubDate>' . ($post->published_at ? $post->published_at->toRssString() : now()->toRssString()) . '</pubDate>';
            $items[] = '    </item>';
        }

        return implode("\n", array_merge([
            '<?xml version="1.0" encoding="UTF-8"?>',
            '<rss version="2.0"><channel>',
            '    <title>' . htmlspecialchars(Seo::siteName(), ENT_XML1) . '</title>',
            '    <link>' . $base . '/blog/</link>',
            '    <description>' . htmlspecialchars(Seo::tagline(), ENT_XML1) . '</description>',
            '    <language>ru</language>',
        ], $items, ['</channel></rss>']));
    }
}
