<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Post;

/**
 * Search — единый поиск по страницам сайта и статьям блога.
 *
 * Индекс собирается из проверенного списка страниц и опубликованных статей.
 * Тело статьи в индекс попадает только началом: этого хватает для поиска
 * по смыслу, а страница поиска остаётся лёгкой.
 */
class Search
{
    public const TYPE_ALL = 'all';
    public const TYPE_PAGE = 'page';
    public const TYPE_BLOG = 'blog';

    /**
     * index — все записи, по которым ищет страница /search/ и Консультант.
     */
    public static function index(): array
    {
        return array_merge(static::pages(), static::posts());
    }

    /**
     * json — индекс для клиентского поиска на статической демо-копии.
     */
    public static function json(?array $items = null): string
    {
        $json = json_encode(
            $items ?? static::index(),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json === false ? '[]' : $json;
    }

    /**
     * query — ранжированная выдача для страницы поиска и ответов Консультанта.
     */
    public static function query(string $query, string $type = self::TYPE_ALL, int $limit = 20): array
    {
        $query = static::normalize($query);
        $words = static::words($query);

        if ($query === '' || !$words) {
            return [];
        }

        $results = [];

        foreach (static::index() as $item) {
            if ($type !== '' && $type !== self::TYPE_ALL && ($item['type'] ?? '') !== $type) {
                continue;
            }

            $score = static::score($item, $query, $words);

            if ($score <= 0) {
                continue;
            }

            $item['score'] = $score;
            $results[] = $item;
        }

        usort($results, function (array $a, array $b) {
            if ($a['score'] === $b['score']) {
                return strcmp((string) $a['title'], (string) $b['title']);
            }

            return $b['score'] <=> $a['score'];
        });

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * pages — статические страницы и карточки разделов.
     */
    public static function pages(): array
    {
        $pages = [
            ['title' => 'Главная', 'url' => '/', 'description' => 'Готовый сайт для бизнеса с онлайн-записью, заказами и оплатой.', 'keywords' => 'главная elv kit сайт для бизнеса запись оплата'],
            ['title' => 'Возможности Elv Kit', 'url' => '/kit/', 'description' => 'Что входит в готовый сайт: страницы, запись, заказы, блог и админка.', 'keywords' => 'возможности kit функции состав сайта'],
            ['title' => 'Модули Elv Kit', 'url' => '/modules/', 'description' => 'Онлайн-запись, заказы и оплата, обратная связь и блог.', 'keywords' => 'разделы модули плагины booking shop feedback blog'],
            ['title' => 'Услуги Elv Studio', 'url' => '/services/', 'description' => 'Хостинг, поддержка, аудит и SEO-продвижение.', 'keywords' => 'услуги хостинг поддержка аудит seo продвижение'],
            ['title' => 'Хостинг и запуск', 'url' => '/services/hosting/', 'description' => 'Поможем выбрать хостинг, проверить требования и запустить сайт.', 'keywords' => 'хостинг запуск домен ssl почта требования october cms'],
            ['title' => 'Поддержка сайта', 'url' => '/services/support/', 'description' => 'Обновления, резервные копии и помощь после запуска.', 'keywords' => 'поддержка обновления резервные копии сопровождение'],
            ['title' => 'Модули и плагины', 'url' => '/services/plugins/', 'description' => 'Установка и доработка модулей October CMS.', 'keywords' => 'модули плагины установка доработка october cms'],
            ['title' => 'Онлайн-запись', 'url' => '/services/booking/', 'description' => 'Настройка записи без двойных броней.', 'keywords' => 'онлайн запись бронирование слоты услуги'],
            ['title' => 'Бесплатный аудит', 'url' => '/services/audit/', 'description' => 'Проверим скорость, SEO и безопасность сайта.', 'keywords' => 'аудит бесплатно скорость seo безопасность проверка'],
            ['title' => 'SEO-продвижение', 'url' => '/services/seo/', 'description' => 'Структура, тексты и техническая база для поиска.', 'keywords' => 'seo продвижение поиск google яндекс метрика'],
            ['title' => 'Калькулятор стоимости', 'url' => '/calculator/', 'description' => 'Соберите смету: запуск, годовая поддержка и работы.', 'keywords' => 'калькулятор стоимость смета цена сайта'],
            ['title' => 'Цены и тарифы', 'url' => '/pricing/', 'description' => 'Тарифы Elv Kit и что входит в каждый.', 'keywords' => 'цены тарифы стоимость оплата купить'],
            ['title' => 'Демо', 'url' => '/demo/', 'description' => 'Посмотреть сайт и админку в работе.', 'keywords' => 'демо стенд посмотреть админка пример'],
            ['title' => 'Инструкция по установке Elv Kit', 'url' => '/docs/', 'description' => 'Как установить, настроить и запустить сайт.', 'keywords' => 'инструкция установка настройка запуск документация'],
            ['title' => 'Заказать сайт', 'url' => '/order/', 'description' => 'Оставить заявку на готовый сайт или доработку.', 'keywords' => 'заказать заказ заявка купить сайт'],
            ['title' => 'Записаться', 'url' => '/booking/', 'description' => 'Выбрать услугу, дату и свободное время.', 'keywords' => 'записаться запись услуга время'],
            ['title' => 'Обратная связь', 'url' => '/feedback/', 'description' => 'Задать вопрос или описать задачу.', 'keywords' => 'обратная связь вопрос сообщение написать'],
            ['title' => 'Контакты', 'url' => '/contacts/', 'description' => 'E-mail, Telegram и другие способы связи.', 'keywords' => 'контакты почта email telegram whatsapp телефон'],
            ['title' => 'Блог', 'url' => '/blog/', 'description' => 'Статьи о сайтах, записи, оплате и продвижении.', 'keywords' => 'блог статьи новости журнал'],
            ['title' => 'Технический раздел', 'url' => '/blog/tech/', 'description' => 'Разборы для разработчиков: скорость, миграции, оплата.', 'keywords' => 'технический раздел разработчикам october cms скорость миграции'],
            ['title' => 'Карта сайта', 'url' => '/sitemap/', 'description' => 'Все страницы и статьи в одном месте.', 'keywords' => 'карта сайта навигация все страницы'],
            ['title' => 'Оферта', 'url' => '/terms/', 'description' => 'Условия покупки и использования Elv Kit.', 'keywords' => 'оферта условия договор покупка'],
            ['title' => 'Политика конфиденциальности', 'url' => '/privacy/', 'description' => 'Как сайт обращается с персональными данными.', 'keywords' => 'политика конфиденциальность персональные данные'],
        ];

        $items = [];

        foreach ($pages as $page) {
            $items[] = $page + [
                'type' => self::TYPE_PAGE,
                'group' => 'Страницы',
                'meta' => 'Страница сайта',
            ];
        }

        foreach (Catalog::modules() as $module) {
            $items[] = [
                'type' => self::TYPE_PAGE,
                'group' => 'Модули',
                'title' => (string) $module->name,
                'url' => '/modules/' . $module->slug . '/',
                'description' => trim((string) $module->tagline),
                'keywords' => trim($module->name . ' ' . $module->slug),
                'meta' => 'Модуль Elv Kit',
            ];
        }

        return $items;
    }

    /**
     * posts — опубликованные статьи обеих аудиторий.
     */
    public static function posts(): array
    {
        $items = [];

        foreach (Post::query()->published()->with('category')->get() as $post) {
            $category = $post->category ? (string) $post->category->name : '';
            $tags = implode(' ', $post->tag_list);

            $items[] = [
                'type' => self::TYPE_BLOG,
                'group' => 'Блог',
                'title' => (string) $post->title,
                'url' => '/blog/' . $post->slug . '/',
                'description' => static::limit((string) $post->excerpt, 180),
                'text' => static::limit(static::plain((string) $post->body), 320),
                'keywords' => trim(implode(' ', array_filter([$category, $tags, (string) $post->audience_label]))),
                'meta' => trim(implode(' · ', array_filter([$category, (string) $post->display_date]))),
            ];
        }

        return $items;
    }

    protected static function score(array $item, string $query, array $words): int
    {
        $title = static::normalize((string) ($item['title'] ?? ''));
        $description = static::normalize((string) ($item['description'] ?? ''));
        $text = static::normalize((string) ($item['text'] ?? ''));
        $keywords = static::normalize((string) ($item['keywords'] ?? ''));
        $meta = static::normalize((string) ($item['meta'] ?? ''));

        $score = $query !== '' && str_contains($title, $query) ? 14 : 0;
        $hits = 0;

        foreach ($words as $word) {
            $hit = false;

            if (str_contains($title, $word)) {
                $score += 7;
                $hit = true;
            }

            if (str_contains($keywords, $word)) {
                $score += 5;
                $hit = true;
            }

            if (str_contains($description, $word)) {
                $score += 3;
                $hit = true;
            }

            if (str_contains($text, $word)) {
                $score += 2;
                $hit = true;
            }

            if (str_contains($meta, $word)) {
                $score += 1;
                $hit = true;
            }

            if ($hit) {
                $hits++;
            }
        }

        if ($hits === 0) {
            return 0;
        }

        if (count($words) > 1) {
            $score += $hits * 2;
            $score -= (count($words) - $hits) * 2;
        }

        return $score;
    }

    protected static function normalize(string $text): string
    {
        $text = mb_strtolower(strip_tags($text));
        $text = str_replace('ё', 'е', $text);
        $text = (string) preg_replace('/[^\p{L}\p{Nd}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @return string[]
     */
    protected static function words(string $text): array
    {
        $stop = [
            'и', 'в', 'во', 'на', 'для', 'с', 'со', 'по', 'как', 'что', 'это', 'или', 'не',
            'из', 'у', 'о', 'об', 'от', 'до', 'за', 'под', 'над', 'при', 'про', 'без',
            'же', 'бы', 'ли', 'то', 'все', 'мы', 'вы', 'он', 'она', 'они', 'мне', 'нам',
        ];

        $words = array_filter(
            explode(' ', $text),
            function (string $word) use ($stop) {
                return mb_strlen($word) >= 2 && !in_array($word, $stop, true);
            }
        );

        return array_values(array_unique($words));
    }

    protected static function plain(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    protected static function limit(string $text, int $length): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length) . '…' : $text;
    }
}
