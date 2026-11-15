<?php namespace Elv\Kit\Classes;

use Cms\Classes\Page;
use Cms\Classes\Theme;
use Elv\Kit\Models\Post;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;

/**
 * Seo — мета, canonical, микроразметка и директивы обхода.
 *
 * Числа и ссылки собираются из БД и файлов, а не из констант: карта сайта
 * обязана совпадать с тем, что реально отвечает 200.
 */
class Seo
{
    public const SECTIONS = [
        'kit' => 'Возможности',
        'modules' => 'Разделы',
        'pricing' => 'Цены',
        'demo' => 'Демо',
        'blog' => 'Блог',
        'docs' => 'Инструкция',
        'order' => 'Заказать',
        'booking' => 'Запись на показ',
        'feedback' => 'Обратная связь',
        'contacts' => 'Контакты',
        'terms' => 'Оферта',
        'privacy' => 'Политика конфиденциальности',
        'sitemap' => 'Карта сайта',
    ];

    protected static $post = null;

    protected static $product = null;

    public static function siteName(): string
    {
        return (string) (Settings::get('brand_name') ?: 'Elv Kit');
    }

    public static function tagline(): string
    {
        return (string) (Settings::get('brand_tagline') ?: 'Готовый сайт для бизнеса с онлайн-записью и оплатой');
    }

    public static function base(): string
    {
        return rtrim(url('/'), '/');
    }

    public static function canonical(): string
    {
        $path = '/' . ltrim((string) request()->path(), '/');

        if ($path !== '/') {
            $path = rtrim($path, '/') . '/';
        }

        return static::base() . $path;
    }

    public static function setPost(?Post $post): void
    {
        static::$post = $post;
    }

    public static function post(): ?Post
    {
        return static::$post;
    }

    public static function setProduct(?Product $product): void
    {
        static::$product = $product;
    }

    public static function product(): ?Product
    {
        return static::$product;
    }

    /**
     * title — заголовок страницы с суффиксом бренда.
     */
    public static function title($page): string
    {
        if (static::$post) {
            return ($page->meta_title ?: static::$post->seo_title ?: static::$post->title) . ' — ' . static::siteName();
        }

        $raw = trim((string) ($page->meta_title ?? $page->title ?? ''));
        $raw = $raw !== '' ? $raw : static::siteName();

        return str_contains($raw, static::siteName()) ? $raw : $raw . ' — ' . static::siteName();
    }

    public static function description($page): string
    {
        $value = trim((string) ($page->meta_description ?? ''));

        if ($value === '' && static::$post) {
            $value = trim((string) (static::$post->seo_description ?: strip_tags((string) static::$post->excerpt)));
        }

        if ($value === '') {
            $value = (string) (Settings::get('brand_description') ?: static::tagline());
        }

        return mb_substr($value, 0, 300);
    }

    public static function image($page = null): string
    {
        return static::base() . '/themes/elv-studio/assets/img/og-default.png';
    }

    /**
     * breadcrumbs — видимые крошки и BreadcrumbList из одного источника.
     *
     * @return array<int, array{name: string, url: string}>
     */
    public static function breadcrumbs(): array
    {
        $segments = array_values(array_filter(explode('/', trim((string) request()->path(), '/'))));

        if ($segments === []) {
            return [];
        }

        $crumbs = [['name' => 'Главная', 'url' => static::base() . '/']];

        if (!isset(static::SECTIONS[$segments[0]])) {
            return $crumbs;
        }

        $crumbs[] = [
            'name' => static::SECTIONS[$segments[0]],
            'url' => static::base() . '/' . $segments[0] . '/',
        ];

        if (count($segments) > 1) {
            if ($segments[1] === 'tech' && !static::$post) {
                $crumbs[] = [
                    'name' => 'Технический раздел',
                    'url' => static::base() . '/blog/tech/',
                ];

                return $crumbs;
            }

            if (static::$post && static::$post->audience === Post::AUDIENCE_TECH) {
                $crumbs[] = [
                    'name' => 'Технический раздел',
                    'url' => static::base() . '/blog/tech/',
                ];
            }

            $name = static::slugTitle($segments[1]);
            $crumbs[] = ['name' => $name, 'url' => static::canonical()];
        }

        return $crumbs;
    }

    /**
     * slugTitle — подпись последней крошки по данным, а не по адресу.
     */
    protected static function slugTitle(string $slug): string
    {
        if (static::$post && static::$post->slug === $slug) {
            return (string) static::$post->title;
        }

        $product = Product::query()->where('slug', $slug)->first();

        if ($product) {
            return (string) $product->name;
        }

        return ucfirst(str_replace('-', ' ', $slug));
    }

    /**
     * jsonld — @graph с организацией, сайтом и узлом страницы.
     */
    public static function jsonld($page): string
    {
        $base = static::base();

        $graph = [
            [
                '@type' => 'Organization',
                '@id' => $base . '/#organization',
                'name' => static::siteName(),
                'url' => $base . '/',
                'description' => (string) (Settings::get('brand_description') ?: static::tagline()),
                'email' => (string) Settings::get('email'),
                'sameAs' => array_values(array_filter([
                    (string) Settings::get('telegram'),
                    (string) Settings::get('github_url'),
                ])),
            ],
            [
                '@type' => 'WebSite',
                '@id' => $base . '/#website',
                'url' => $base . '/',
                'name' => static::siteName(),
                'inLanguage' => 'ru',
                'publisher' => ['@id' => $base . '/#organization'],
                'potentialAction' => [
                    '@type' => 'SearchAction',
                    'target' => [
                        '@type' => 'EntryPoint',
                        'urlTemplate' => $base . '/blog/?q={search_term_string}',
                    ],
                    'query-input' => 'required name=search_term_string',
                ],
            ],
        ];

        $pageId = (string) ($page->id ?? $page->getBaseFileName() ?? '');

        if ($pageId === 'index') {
            $graph[] = static::softwareNode();
            $graph[] = static::faqNode();
        }

        if ($pageId === 'kit') {
            $graph[] = static::softwareNode();
        }

        if (static::$post) {
            $graph[] = static::articleNode(static::$post);
        }

        if (static::$product) {
            $graph[] = static::productNode(static::$product);
        }

        $crumbs = static::breadcrumbs();

        if (count($crumbs) > 1) {
            $items = [];

            foreach ($crumbs as $index => $crumb) {
                $items[] = [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $crumb['name'],
                    'item' => $crumb['url'],
                ];
            }

            $graph[] = [
                '@type' => 'BreadcrumbList',
                '@id' => static::canonical() . '#breadcrumb',
                'itemListElement' => $items,
            ];
        }

        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        return $json === false ? '' : str_replace('</', '<\/', $json);
    }

    protected static function softwareNode(): array
    {
        $kit = Product::kit();
        $price = $kit ? $kit->price_cents / 100 : 79;

        return static::withoutEmpty([
            '@type' => 'SoftwareApplication',
            '@id' => static::base() . '/kit/#software',
            'name' => static::siteName(),
            'applicationCategory' => 'BusinessApplication',
            'operatingSystem' => 'Web',
            'description' => (string) (Settings::get('brand_description') ?: static::tagline()),
            'url' => static::base() . '/kit/',
            'offers' => [
                '@type' => 'Offer',
                'price' => (string) $price,
                'priceCurrency' => Money::currency(),
                'url' => static::base() . '/pricing/',
                'availability' => 'https://schema.org/InStock',
            ],
            'featureList' => $kit && is_array($kit->features) ? array_values($kit->features) : [],
        ]);
    }

    protected static function faqNode(): array
    {
        $items = [];

        foreach (Faq::items() as $item) {
            $items[] = [
                '@type' => 'Question',
                'name' => $item['q'],
                'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['a']],
            ];
        }

        return ['@type' => 'FAQPage', '@id' => static::base() . '/#faq', 'mainEntity' => $items];
    }

    protected static function productNode(Product $product): array
    {
        $node = static::withoutEmpty([
            '@type' => 'Product',
            '@id' => static::canonical() . '#product',
            'name' => (string) $product->name,
            'description' => (string) ($product->description ?: $product->tagline),
            'sku' => (string) $product->slug,
            'brand' => ['@type' => 'Brand', 'name' => static::siteName()],
            'url' => static::canonical(),
        ]);

        if ((int) $product->price_cents > 0) {
            $node['offers'] = [
                '@type' => 'Offer',
                'price' => (string) ($product->price_cents / 100),
                'priceCurrency' => Money::currency(),
                'url' => static::canonical(),
                'availability' => 'https://schema.org/InStock',
            ];
        }

        return $node;
    }

    protected static function articleNode(Post $post): array
    {
        return static::withoutEmpty([
            '@type' => 'BlogPosting',
            '@id' => static::canonical() . '#article',
            'headline' => (string) $post->title,
            'description' => (string) ($post->seo_description ?: strip_tags((string) $post->excerpt)),
            'datePublished' => $post->iso_date,
            'dateModified' => $post->updated_at ? $post->updated_at->format('c') : null,
            'author' => ['@type' => 'Person', 'name' => (string) ($post->author ?: static::siteName())],
            'publisher' => ['@id' => static::base() . '/#organization'],
            'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => static::canonical()],
            'url' => static::canonical(),
        ]);
    }

    /**
     * robots — Google + Яндекс: служебные разделы закрыты, фильтры не плодят страницы.
     */
    public static function robots(): string
    {
        return implode("\n", [
            '# robots.txt — ' . static::siteName(),
            '',
            'User-agent: *',
            'Allow: /',
            'Disallow: /admin/',
            'Disallow: /api/',
            'Disallow: /order/success/',
            'Disallow: /download/',
            'Disallow: /*?q=',
            'Disallow: /*?category=',
            'Disallow: /*?tag=',
            '',
            '# Яндекс: параметры фильтров не создают новых страниц',
            'Clean-param: q&category&tag /blog/',
            '',
            'User-agent: Yandex',
            'Allow: /',
            'Disallow: /admin/',
            '',
            'Sitemap: ' . static::base() . '/sitemap.xml',
            '',
        ]);
    }

    /**
     * llms — карта сайта для ИИ-краулеров и AI-режима Google.
     */
    public static function llms(): string
    {
        $kit = Product::kit();

        $lines = [
            '# ' . static::siteName(),
            '',
            '> ' . static::tagline(),
            '',
            (string) (Settings::get('brand_description') ?: ''),
            '',
            '## Что это',
            '- [Elv Kit](' . static::base() . '/kit/): готовый сайт для бизнеса — услуги, онлайн-запись, заказы и оплата, блог и админка; цена от ' . Money::format($kit ? $kit->price_cents : 7900) . '.',
            '- [Цены](' . static::base() . '/pricing/): один сайт, до 5 сайтов, без ограничений. Разделы можно купить отдельно.',
            '- [Разделы](' . static::base() . '/modules/): онлайн-запись, заказы и оплата, обратная связь, блог.',
            '',
            '## Помощь',
            '- [Инструкция](' . static::base() . '/docs/): запуск на своём домене по шагам, без команд.',
            '- [Демо](' . static::base() . '/demo/): как выглядит сайт и админка.',
            '',
            '## Блог для бизнеса',
        ];

        foreach (Post::query()->published()->audience(Post::AUDIENCE_BUSINESS)->limit(10)->get() as $post) {
            $lines[] = '- [' . $post->title . '](' . $post->url . '): ' . trim(strip_tags((string) $post->excerpt));
        }

        $lines[] = '';
        $lines[] = '## Технический раздел';
        $lines[] = '- [Разборы для разработчиков](' . static::base() . '/blog/tech/): скорость, миграции, оплата, бронирование и SEO.';

        foreach (Post::query()->published()->audience(Post::AUDIENCE_TECH)->limit(5)->get() as $post) {
            $lines[] = '- [' . $post->title . '](' . $post->url . '): ' . trim(strip_tags((string) $post->excerpt));
        }

        $lines[] = '';
        $lines[] = '## Контакты';
        $lines[] = '- E-mail: ' . (string) Settings::get('email');
        $lines[] = '- Telegram: ' . ((string) Settings::get('telegram') ?: '—');
        $lines[] = '';

        return implode("\n", $lines);
    }

    protected static function withoutEmpty(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $value = static::withoutEmpty($value);
            }

            if ($value === '' || $value === null || $value === []) {
                continue;
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
