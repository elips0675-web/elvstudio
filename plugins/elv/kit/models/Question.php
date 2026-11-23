<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\Community;
use Model;

/**
 * Question — вопрос к продукту.
 *
 * Отправитель создаёт строку в статусе `new`. На витрину она попадает только
 * после ответа и модерации: `published` ставит админка, а `answer` не пустой.
 */
class Question extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_REJECTED = 'rejected';

    public $table = 'elv_kit_questions';

    public $fillable = [
        'product_id', 'customer_id', 'name', 'email', 'question', 'answer',
        'answered_by', 'status', 'is_pinned', 'sort_order', 'answered_at',
        'published_at', 'ip_hash',
    ];

    public $rules = [
        'product_id' => 'required|integer|min:1',
        'name' => 'required|max:160',
        'email' => 'required|email|max:191',
        'question' => 'required|max:3000',
        'status' => 'required|in:new,published,rejected',
    ];

    protected $casts = [
        'is_pinned' => 'boolean',
        'answered_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public $belongsTo = [
        'product' => Product::class,
        'customer' => Customer::class,
    ];

    public function scopePublished($query)
    {
        return $query
            ->where('status', self::STATUS_PUBLISHED)
            ->whereNotNull('answer')
            ->where('answer', '<>', '')
            ->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->orderByDesc('published_at');
    }

    public function getDisplayNameAttribute(): string
    {
        return Community::displayName((string) $this->name);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ((string) $this->status) {
            self::STATUS_PUBLISHED => 'Опубликован',
            self::STATUS_REJECTED => 'Отклонён',
            default => 'Новый',
        };
    }

    public function getIsPublishedAttribute(): bool
    {
        return (string) $this->status === self::STATUS_PUBLISHED && trim((string) $this->answer) !== '';
    }
}
