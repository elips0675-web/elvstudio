<?php namespace Elv\Kit\Models;

use Model;

/**
 * Post — статья блога. Тело хранится как HTML из richeditor,
 * выводится только через {!! !!} в доверенном шаблоне.
 */
class Post extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'elv_kit_posts';

    public $rules = [
        'title' => 'required|max:200',
        'slug' => 'required|max:200|unique:elv_kit_posts,slug',
    ];

    protected $casts = [
        'tags' => 'array',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public $belongsTo = [
        'category' => [Category::class, 'key' => 'category_id'],
    ];

    public function scopePublished($query)
    {
        return $query
            ->where('is_published', true)
            ->where(function ($q) {
                $q->whereNull('published_at')->orWhere('published_at', '<=', now());
            })
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function getUrlAttribute(): string
    {
        return url('/blog/' . $this->slug . '/');
    }

    public function getIsoDateAttribute(): string
    {
        return $this->published_at ? $this->published_at->format('c') : '';
    }

    public function getDisplayDateAttribute(): string
    {
        return $this->published_at ? $this->published_at->format('d.m.Y') : '';
    }

    public function getTagListAttribute(): array
    {
        return is_array($this->tags) ? array_values(array_filter($this->tags)) : [];
    }

    public function getReadingTimeAttribute($value): int
    {
        if ((int) $value > 0) {
            return (int) $value;
        }

        $words = str_word_count(strip_tags((string) $this->body), 0, 'абвгдеёжзийклмнопрстуфхцчшщъыьэюяАБВГДЕЁЖЗИЙКЛМНОПРСТУФХЦЧШЩЪЫЬЭЮЯ');
        $minutes = (int) ceil(max(1, $words) / 180);

        return max(1, $minutes);
    }
}
