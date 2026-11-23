<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\Community;
use Model;

/**
 * Review — отзыв покупателя с оценкой 1–5.
 *
 * Отзыв приходит по одноразовой ссылке из письма и ждёт модерации. На витрину
 * и в `AggregateRating` попадают только `approved`.
 */
class Review extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public $table = 'elv_kit_reviews';

    public $fillable = [
        'product_id', 'order_id', 'customer_id', 'author_name', 'email_hash',
        'rating', 'title', 'text', 'pros', 'cons', 'status', 'is_verified',
        'reply', 'replied_at', 'published_at', 'ip_hash',
    ];

    public $rules = [
        'product_id' => 'required|integer|min:1',
        'author_name' => 'required|max:160',
        'rating' => 'required|integer|min:1|max:5',
        'text' => 'required|max:4000',
        'status' => 'required|in:pending,approved,rejected',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'replied_at' => 'datetime',
        'published_at' => 'datetime',
    ];

    public $belongsTo = [
        'product' => Product::class,
        'order' => Order::class,
        'customer' => Customer::class,
    ];

    public function scopeApproved($query)
    {
        return $query
            ->where('status', self::STATUS_APPROVED)
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    public function getDisplayNameAttribute(): string
    {
        return Community::displayName((string) $this->author_name);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ((string) $this->status) {
            self::STATUS_APPROVED => 'Одобрен',
            self::STATUS_REJECTED => 'Отклонён',
            default => 'На модерации',
        };
    }

    public function getIsApprovedAttribute(): bool
    {
        return (string) $this->status === self::STATUS_APPROVED;
    }

    /**
     * stars — пять флагов для шаблона: `true` для заполненной звезды.
     *
     * @return array<int, bool>
     */
    public function getStarsAttribute(): array
    {
        $rating = max(0, min(5, (int) $this->rating));

        return array_map(fn (int $star) => $star <= $rating, range(1, 5));
    }
}
