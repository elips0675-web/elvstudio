<?php

declare(strict_types=1);

use Elv\Kit\Models\Post;

require __DIR__ . '/../bootstrap/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function auditPlainText(?string $html): string
{
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return trim($text);
}

function auditWords(string $text): int
{
    preg_match_all('/[\p{L}\p{N}]+/u', $text, $matches);

    return count($matches[0]);
}

function auditHeadings(string $html): array
{
    preg_match_all('/<h([1-4])\b[^>]*>(.*?)<\/h\1>/is', $html, $matches, PREG_SET_ORDER);

    return array_map(static function (array $match): array {
        return [
            'level' => (int) $match[1],
            'text' => auditPlainText($match[2]),
        ];
    }, $matches);
}

function auditLinks(string $html): array
{
    preg_match_all('/<a\b[^>]*href=(["\'])(.*?)\1/is', $html, $matches);

    return array_values(array_unique(array_map('trim', $matches[2] ?? [])));
}

function auditStats(array $rows, string $key): array
{
    $values = array_map(static fn (array $row): int => (int) $row[$key], $rows);
    sort($values);

    if ($values === []) {
        return ['min' => 0, 'max' => 0, 'avg' => 0, 'median' => 0];
    }

    return [
        'min' => $values[0],
        'max' => $values[count($values) - 1],
        'avg' => (int) round(array_sum($values) / count($values)),
        'median' => $values[(int) floor(count($values) / 2)],
    ];
}

function auditCell(string $value): string
{
    return str_replace(['|', "\r", "\n"], ['\\|', ' ', ' '], $value);
}

$postFaqPath = __DIR__ . '/../plugins/elv/kit/config/post-faq.php';
$postFaq = is_file($postFaqPath) ? require $postFaqPath : [];
$posts = Post::with('category')->orderByDesc('published_at')->orderByDesc('id')->get();
$rows = [];

foreach ($posts as $post) {
    $body = (string) $post->body;
    $text = auditPlainText($body);
    $excerpt = auditPlainText($post->excerpt);
    $seoDescription = auditPlainText($post->seo_description);
    $headings = auditHeadings($body);
    $links = auditLinks($body);
    $ctaPaths = ['/order', '/booking', '/demo', '/pricing', '/kit', '/contacts', '/feedback', '/services/audit', '/services/seo'];
    $ctaLinks = array_values(array_filter($links, static function (string $link) use ($ctaPaths): bool {
        $path = parse_url($link, PHP_URL_PATH) ?: $link;

        foreach ($ctaPaths as $ctaPath) {
            if (str_starts_with($path, $ctaPath)) {
                return true;
            }
        }

        return false;
    }));

    $internalLinks = array_values(array_filter($links, static function (string $link): bool {
        return str_starts_with($link, '/')
            || str_starts_with($link, 'http://localhost:8090')
            || str_starts_with($link, 'https://elvstudio.vercel.app')
            || str_starts_with($link, 'https://elv-studio.by');
    }));

    preg_match_all('/<img\b[^>]*>/is', $body, $imageMatches);
    $images = $imageMatches[0] ?? [];
    $imagesWithoutAlt = array_values(array_filter($images, static function (string $image): bool {
        return !preg_match('/\balt=(["\']).+?\1/is', $image);
    }));

    $faq = false;

    foreach ($headings as $heading) {
        if (preg_match('/\b(вопрос|faq|часто задаваемые|частые вопросы)\b/iu', $heading['text'])) {
            $faq = true;
            break;
        }
    }

    if (str_contains($body, '<details')) {
        $faq = true;
    }

    if (isset($postFaq[$post->slug]) && is_array($postFaq[$post->slug]) && $postFaq[$post->slug] !== []) {
        $faq = true;
    }

    $ctaByText = (bool) preg_match('/\b(оставьте заявку|оставить заявку|заказать|записаться|запишитесь|попробовать|попробуйте|смотреть демо|подключить|консультац|напишите|позвоните|перейти в демо|выбрать тариф|скачать)\b/iu', $text);

    $rows[] = [
        'id' => $post->id,
        'audience' => $post->audience,
        'category' => $post->category?->name ?? '',
        'title' => $post->title,
        'slug' => $post->slug,
        'published_at' => $post->published_at?->format('Y-m-d') ?? '',
        'reading_time' => $post->reading_time,
        'body_chars' => mb_strlen($text),
        'body_words' => auditWords($text),
        'h2_count' => count(array_filter($headings, static fn (array $heading): bool => $heading['level'] === 2)),
        'h3_count' => count(array_filter($headings, static fn (array $heading): bool => $heading['level'] === 3)),
        'internal_links' => $internalLinks,
        'internal_link_count' => count($internalLinks),
        'cta_links' => $ctaLinks,
        'cta' => $ctaLinks !== [] || $ctaByText,
        'faq' => $faq,
        'images' => count($images),
        'images_without_alt' => count($imagesWithoutAlt),
        'tags' => $post->tag_list,
        'tag_count' => count($post->tag_list),
        'excerpt' => $excerpt,
        'seo_description' => $seoDescription,
        'seo_description_length' => mb_strlen($seoDescription),
        'seo_equals_excerpt' => $seoDescription !== '' && $seoDescription === $excerpt,
    ];
}

$outDir = __DIR__ . '/../exports';

if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$date = date('Y-m-d');
$path = $outDir . '/blog-content-audit-' . $date . '.md';
$business = array_values(array_filter($rows, static fn (array $row): bool => $row['audience'] === 'business'));
$tech = array_values(array_filter($rows, static fn (array $row): bool => $row['audience'] === 'tech'));
$categories = [];
$dates = [];
$tags = [];

foreach ($rows as $row) {
    $categories[$row['category']] = ($categories[$row['category']] ?? 0) + 1;
    $dates[$row['published_at']] = ($dates[$row['published_at']] ?? 0) + 1;

    foreach ($row['tags'] as $tag) {
        $tags[$tag] = ($tags[$tag] ?? 0) + 1;
    }
}

arsort($categories);
arsort($dates);
arsort($tags);

$short = array_values(array_filter($rows, static fn (array $row): bool => $row['body_chars'] < 1500));
$noLinks = array_values(array_filter($rows, static fn (array $row): bool => $row['internal_link_count'] === 0));
$noCta = array_values(array_filter($rows, static fn (array $row): bool => !$row['cta']));
$noFaq = array_values(array_filter($rows, static fn (array $row): bool => !$row['faq']));
$noImages = array_values(array_filter($rows, static fn (array $row): bool => $row['images'] === 0));
$duplicateMeta = array_values(array_filter($rows, static fn (array $row): bool => $row['seo_equals_excerpt']));

$md = [];
$md[] = '# Аудит контента блога Elv Studio';
$md[] = '';
$md[] = 'Дата среза: ' . date('d.m.Y H:i') . '.';
$md[] = 'Источник: `elv_studio`, таблица `elv_kit_posts`.';
$md[] = 'Всего статей: **' . count($rows) . '** (`business` ' . count($business) . ', `tech` ' . count($tech) . ').';
$md[] = '';
$md[] = '## Сводка';
$md[] = '';
$md[] = '| Показатель | business | tech |';
$md[] = '|---|---:|---:|';
$md[] = '| Статей | ' . count($business) . ' | ' . count($tech) . ' |';
$businessChars = auditStats($business, 'body_chars');
$techChars = auditStats($tech, 'body_chars');
$md[] = '| Символов: минимум / среднее / максимум | ' . $businessChars['min'] . ' / ' . $businessChars['avg'] . ' / ' . $businessChars['max'] . ' | ' . $techChars['min'] . ' / ' . $techChars['avg'] . ' / ' . $techChars['max'] . ' |';
$md[] = '| Слов: среднее | ' . auditStats($business, 'body_words')['avg'] . ' | ' . auditStats($tech, 'body_words')['avg'] . ' |';
$md[] = '| Внутренних ссылок: среднее | ' . auditStats($business, 'internal_link_count')['avg'] . ' | ' . auditStats($tech, 'internal_link_count')['avg'] . ' |';
$md[] = '| SEO-описание: среднее символов | ' . auditStats($business, 'seo_description_length')['avg'] . ' | ' . auditStats($tech, 'seo_description_length')['avg'] . ' |';
$md[] = '';
$md[] = '## Пробелы';
$md[] = '';
$md[] = '- Короче 1500 символов: **' . count($short) . '** — ' . implode(', ', array_map(static fn (array $row): string => 'ID ' . $row['id'], $short)) . '.';
$md[] = '- Без внутренних ссылок: **' . count($noLinks) . '** — ' . implode(', ', array_map(static fn (array $row): string => 'ID ' . $row['id'], $noLinks)) . '.';
$md[] = '- Без CTA в теле: **' . count($noCta) . '** — ' . implode(', ', array_map(static fn (array $row): string => 'ID ' . $row['id'], $noCta)) . '.';
$md[] = '- Без FAQ-блока: **' . count($noFaq) . '** из ' . count($rows) . '.';
$md[] = '- Без изображений в теле: **' . count($noImages) . '** из ' . count($rows) . '.';
$md[] = '- SEO-описание дословно совпадает с кратким: **' . count($duplicateMeta) . '** — ' . implode(', ', array_map(static fn (array $row): string => 'ID ' . $row['id'], $duplicateMeta)) . '.';
$md[] = '';
$md[] = '## Категории';
$md[] = '';
foreach ($categories as $category => $count) {
    $md[] = '- ' . ($category !== '' ? $category : '—') . ': ' . $count . '.';
}
$md[] = '';
$md[] = '## Даты публикации';
$md[] = '';
foreach ($dates as $publishedAt => $count) {
    $md[] = '- ' . ($publishedAt !== '' ? $publishedAt : '—') . ': ' . $count . '.';
}
$md[] = '';
$md[] = '## Частые теги';
$md[] = '';
foreach (array_slice($tags, 0, 30, true) as $tag => $count) {
    $md[] = '- ' . $tag . ': ' . $count . '.';
}
$md[] = '';
$md[] = '## Все статьи';
$md[] = '';
$md[] = '| ID | Раздел | Категория | Символов | Ссылок | CTA | FAQ | Теги | Заголовок |';
$md[] = '|---:|---|---|---:|---:|---|---|---|---|';

foreach ($rows as $row) {
    $md[] = sprintf(
        '| %d | %s | %s | %d | %d | %s | %s | %s | %s |',
        $row['id'],
        $row['audience'],
        auditCell($row['category']),
        $row['body_chars'],
        $row['internal_link_count'],
        $row['cta'] ? 'yes' : 'no',
        $row['faq'] ? 'yes' : 'no',
        auditCell(implode(', ', $row['tags'])),
        auditCell($row['title'])
    );
}

$md[] = '';
$md[] = '## Порог для будущего гейта';
$md[] = '';
$md[] = 'После правок можно включить в CI: у каждой статьи ≥ 1500 символов, ≥ 1 внутренняя ссылка, CTA, FAQ, уникальное SEO-описание (не равное краткому), ≥ 1 изображение или схема. Сейчас эти пороги не выполняются, поэтому скрипт только отчёт.';
$md[] = '';

file_put_contents($path, implode("\n", $md) . "\n");

echo 'MD: ' . $path . "\n";
echo 'Articles: ' . count($rows) . "\n";
echo 'Short: ' . count($short) . ', no links: ' . count($noLinks) . ', no CTA: ' . count($noCta) . ', no FAQ: ' . count($noFaq) . ', no images: ' . count($noImages) . ', meta=excerpt: ' . count($duplicateMeta) . "\n";
