<?php

declare(strict_types=1);

use Elv\Kit\Models\Post;

require __DIR__ . '/../bootstrap/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function plainText(?string $html): string
{
    $text = html_entity_decode(strip_tags((string) $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/u', ' ', $text) ?? '';

    return trim($text);
}

function mdCell(string $value): string
{
    return str_replace(["|", "\r", "\n"], ['\\|', ' ', ' '], $value);
}

$outDir = __DIR__ . '/../exports';
$baseUrl = 'https://elvstudio.vercel.app';

foreach ($argv as $argument) {
    if (str_starts_with($argument, '--base=')) {
        $baseUrl = rtrim(substr($argument, 7), '/');
    }
}

if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$date = date('Y-m-d');
$csvPath = $outDir . '/blog-articles-' . $date . '.csv';
$mdPath = $outDir . '/blog-articles-' . $date . '.md';

$posts = Post::with('category')
    ->orderBy('audience')
    ->orderByDesc('published_at')
    ->orderByDesc('id')
    ->get();

$rows = [];

foreach ($posts as $post) {
    $excerpt = plainText($post->excerpt);
    $seoDescription = plainText($post->seo_description);
    $description = $seoDescription !== '' ? $seoDescription : $excerpt;

    if ($description === '') {
        $description = mb_substr(plainText($post->body), 0, 240);
    }

    $bodyText = plainText($post->body);
    $status = ! $post->is_published
        ? 'draft'
        : ($post->published_at && $post->published_at->isFuture() ? 'scheduled' : 'published');

    $rows[] = [
        'id' => $post->id,
        'audience' => $post->audience,
        'audience_label' => $post->audience_label,
        'category' => $post->category?->name ?? '',
        'title' => $post->title,
        'slug' => $post->slug,
        'url' => $baseUrl . '/blog/' . $post->slug,
        'keywords' => implode(', ', $post->tag_list),
        'excerpt' => $excerpt,
        'seo_description' => $seoDescription,
        'description' => $description,
        'author' => $post->author ?? '',
        'published_at' => $post->display_date,
        'reading_time' => $post->reading_time,
        'body_chars' => mb_strlen($bodyText),
        'status' => $status,
    ];
}

$csv = fopen($csvPath, 'wb');

if ($csv === false) {
    fwrite(STDERR, "Cannot open $csvPath\n");
    exit(1);
}

fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, [
    'ID',
    'audience',
    'Раздел',
    'Категория',
    'Заголовок',
    'Slug',
    'URL',
    'Ключевые слова',
    'Краткое описание',
    'SEO-описание',
    'Автор',
    'Дата',
    'Время чтения, мин',
    'Символов',
    'Статус',
], ';', '"', '');

foreach ($rows as $row) {
    fputcsv($csv, [
        $row['id'],
        $row['audience'],
        $row['audience_label'],
        $row['category'],
        $row['title'],
        $row['slug'],
        $row['url'],
        $row['keywords'],
        $row['excerpt'],
        $row['seo_description'],
        $row['author'],
        $row['published_at'],
        $row['reading_time'],
        $row['body_chars'],
        $row['status'],
    ], ';', '"', '');
}

fclose($csv);

$markdown = [];
$markdown[] = '# Статьи блога Elv Studio';
$markdown[] = '';
$markdown[] = 'Дата выгрузки: ' . date('d.m.Y H:i') . '.';
$markdown[] = 'Источник: `elv_studio`, таблица `elv_kit_posts`.';
$markdown[] = 'Всего статей: **' . count($rows) . '**.';
$markdown[] = '';
$markdown[] = '## Сводная таблица';
$markdown[] = '';
$markdown[] = '| ID | Раздел | Категория | Заголовок | Ключевые слова | Описание |';
$markdown[] = '|---:|---|---|---|---|---|';

foreach ($rows as $row) {
    $markdown[] = sprintf(
        '| %d | %s | %s | [%s](%s) | %s | %s |',
        $row['id'],
        mdCell($row['audience_label']),
        mdCell($row['category']),
        mdCell($row['title']),
        $row['url'],
        mdCell($row['keywords']),
        mdCell($row['description'])
    );
}

$markdown[] = '';
$markdown[] = '## Статьи подробно';

foreach ($rows as $row) {
    $markdown[] = '';
    $markdown[] = '### ' . $row['title'];
    $markdown[] = '';
    $markdown[] = '- ID: ' . $row['id'];
    $markdown[] = '- Раздел: ' . $row['audience_label'] . ' (`' . $row['audience'] . '`)';
    $markdown[] = '- Категория: ' . ($row['category'] !== '' ? $row['category'] : '—');
    $markdown[] = '- URL: ' . $row['url'];
    $markdown[] = '- Slug: `' . $row['slug'] . '`';
    $markdown[] = '- Ключевые слова: ' . ($row['keywords'] !== '' ? $row['keywords'] : '—');
    $markdown[] = '- Автор: ' . ($row['author'] !== '' ? $row['author'] : '—');
    $markdown[] = '- Дата: ' . ($row['published_at'] !== '' ? $row['published_at'] : '—');
    $markdown[] = '- Время чтения: ' . $row['reading_time'] . ' мин';
    $markdown[] = '- Символов в тексте: ' . $row['body_chars'];
    $markdown[] = '- Статус: ' . $row['status'];
    $markdown[] = '';
    $markdown[] = '**Краткое описание:** ' . ($row['excerpt'] !== '' ? $row['excerpt'] : '—');
    $markdown[] = '';
    $markdown[] = '**SEO-описание:** ' . ($row['seo_description'] !== '' ? $row['seo_description'] : '—');
}

file_put_contents($mdPath, implode("\n", $markdown) . "\n");

echo "CSV: $csvPath\n";
echo "MD:  $mdPath\n";
echo 'Articles: ' . count($rows) . "\n";
