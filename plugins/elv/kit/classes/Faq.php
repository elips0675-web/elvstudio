<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;

/**
 * Faq — пары «вопрос | ответ» из настроек владельца сайта.
 */
class Faq
{
    /**
     * Сколько вопросов показываем на главной: остальные — на /pricing/#pricing-faq.
     */
    public const HOME_LIMIT = 4;

    public static function items(string $name = 'faq', ?int $limit = null): array
    {
        $items = static::parse((string) Settings::get(static::settingKey($name)));

        return $limit === null ? $items : array_slice($items, 0, $limit);
    }

    public static function post(string $slug): array
    {
        $slug = preg_replace('/[^a-z0-9-]/', '', strtolower($slug)) ?: '';
        $all = static::postItems();

        return $slug !== '' && isset($all[$slug]) && is_array($all[$slug]) ? $all[$slug] : [];
    }

    public static function settingKey(string $name): string
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: 'faq';

        return $name === 'faq' ? 'faq_items' : 'faq_' . str_replace('-', '_', $name) . '_items';
    }

    public static function parse(string $raw): array
    {
        $items = [];

        foreach (static::lines($raw) as $line) {
            $parts = explode('|', $line, 2);

            if (count($parts) !== 2) {
                continue;
            }

            $question = trim($parts[0]);
            $answer = trim($parts[1]);

            if ($question !== '' && $answer !== '') {
                $items[] = ['q' => $question, 'a' => $answer];
            }
        }

        return $items;
    }

    public static function postItems(): array
    {
        $items = [];

        foreach (static::lines((string) Settings::get('faq_post_items')) as $line) {
            $parts = explode('|', $line, 3);

            if (count($parts) !== 3) {
                continue;
            }

            $slug = preg_replace('/[^a-z0-9-]/', '', strtolower(trim($parts[0])));
            $question = trim($parts[1]);
            $answer = trim($parts[2]);

            if ($slug !== '' && $question !== '' && $answer !== '') {
                $items[$slug][] = ['q' => $question, 'a' => $answer];
            }
        }

        return $items;
    }

    public static function configItems(string $name): array
    {
        $name = preg_replace('/[^a-z0-9-]/', '', strtolower($name)) ?: '';

        if ($name === '') {
            return [];
        }

        $path = base_path('plugins/elv/kit/config/' . $name . '.php');

        if (!is_file($path)) {
            return [];
        }

        $items = require $path;

        return is_array($items) ? $items : [];
    }

    public static function configPostItems(): array
    {
        return static::configItems('post-faq');
    }

    public static function lines(string $raw): array
    {
        $lines = [];

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $line = trim($line);

            if ($line !== '' && !str_starts_with($line, '#')) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    public static function toLines(array $items): string
    {
        $lines = [];

        foreach ($items as $item) {
            $question = trim((string) ($item['q'] ?? ''));
            $answer = trim((string) ($item['a'] ?? ''));

            if ($question !== '' && $answer !== '') {
                $lines[] = $question . ' | ' . $answer;
            }
        }

        return implode("\n", $lines);
    }

    public static function postToLines(array $all): string
    {
        $lines = [];

        foreach ($all as $slug => $items) {
            if (!is_array($items)) {
                continue;
            }

            foreach ($items as $item) {
                $question = trim((string) ($item['q'] ?? ''));
                $answer = trim((string) ($item['a'] ?? ''));

                if ($question !== '' && $answer !== '') {
                    $lines[] = $slug . ' | ' . $question . ' | ' . $answer;
                }
            }
        }

        return implode("\n", $lines);
    }
}
