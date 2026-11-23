<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\ReviewAdmin;
use Elv\Kit\Classes\Mail\ReviewInvite;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Review;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Reviews — отзывы покупателей, приглашения и модерация.
 *
 * Отзыв нельзя оставить с улицы: ссылка `review_token` выдаётся оплаченному
 * заказу и гасится после отправки. На витрину и в `AggregateRating` попадают
 * только одобренные отзывы.
 */
class Reviews
{
    public const INVITE_DAYS = 7;

    public static function enabled(): bool
    {
        return filter_var(Settings::get('reviews_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    public static function inviteEnabled(): bool
    {
        return filter_var(Settings::get('review_invite_enabled', false), FILTER_VALIDATE_BOOLEAN);
    }

    public static function delayDays(): int
    {
        $days = (int) Settings::get('review_delay_days', self::INVITE_DAYS);

        return max(1, min(90, $days ?: self::INVITE_DAYS));
    }

    /**
     * approved — опубликованные отзывы продукта.
     *
     * @return \October\Rain\Database\Collection<int, Review>
     */
    public static function approved(Product $product, int $limit = 0)
    {
        $query = Review::query()
            ->approved()
            ->where('product_id', (int) $product->id);

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * summary — количество и средняя оценка только по одобренным отзывам.
     *
     * @return array{count: int, average: float}
     */
    public static function summary(Product $product): array
    {
        $ratings = Review::query()
            ->where('product_id', (int) $product->id)
            ->where('status', Review::STATUS_APPROVED)
            ->pluck('rating')
            ->all();

        return [
            'count' => count($ratings),
            'average' => Community::average($ratings),
        ];
    }

    /**
     * aggregate — узел `AggregateRating` или null, если отзывов нет.
     *
     * @return array<string, mixed>|null
     */
    public static function aggregate(Product $product): ?array
    {
        $summary = static::summary($product);

        if ($summary['count'] < 1) {
            return null;
        }

        return [
            '@type' => 'AggregateRating',
            'ratingValue' => (string) $summary['average'],
            'reviewCount' => (string) $summary['count'],
            'bestRating' => '5',
            'worstRating' => '1',
        ];
    }

    /**
     * createInvitation — одноразовая ссылка для оплаченного заказа.
     */
    public function createInvitation(Order $order): string
    {
        $token = bin2hex(random_bytes(24));

        $order->forceFill([
            'review_token' => $token,
            'review_token_expires_at' => now()->addDays(30),
            'review_invited_at' => now(),
        ])->save();

        return $token;
    }

    public function invitationUrl(Order $order): string
    {
        return $order->review_token
            ? url('/review/' . $order->review_token) . '/'
            : '';
    }

    public function sendInvitation(Order $order): bool
    {
        $product = $order->product;

        if (!$product || trim((string) $order->email) === '') {
            return false;
        }

        $token = $this->createInvitation($order);

        try {
            Mail::to($order->email)->queue(new ReviewInvite($order, $product, $token));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: приглашение к отзыву не отправлено', [
                'order' => $order->order_no,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * orderByToken — оплаченный заказ по действующей ссылке.
     */
    public function orderByToken(string $token): ?Order
    {
        $token = trim($token);

        if (preg_match('~^[a-f0-9]{48}$~', $token) !== 1) {
            return null;
        }

        $order = Order::query()
            ->where('review_token', $token)
            ->where('status', Order::STATUS_PAID)
            ->first();

        if (!$order || !$order->review_token_expires_at || $order->review_token_expires_at->isPast()) {
            return null;
        }

        return $order;
    }

    public function existsFor(Order $order): bool
    {
        if (!$order->product_id) {
            return false;
        }

        return Review::query()
            ->where('order_id', (int) $order->id)
            ->where('product_id', (int) $order->product_id)
            ->exists();
    }

    /**
     * submit — отзыв по ссылке из письма. Ждёт модерации.
     *
     * @param  array{author_name: string, rating: int, title?: string, text: string, pros?: string, cons?: string}  $data
     */
    public function submit(Order $order, array $data, string $ip = ''): ?Review
    {
        if (!$order->product_id || $this->existsFor($order)) {
            return null;
        }

        $review = new Review();
        $review->fill([
            'product_id' => (int) $order->product_id,
            'order_id' => (int) $order->id,
            'author_name' => (string) $data['author_name'],
            'email_hash' => Community::emailHash((string) $order->email),
            'rating' => max(1, min(5, (int) $data['rating'])),
            'title' => (string) ($data['title'] ?? ''),
            'text' => (string) $data['text'],
            'pros' => (string) ($data['pros'] ?? ''),
            'cons' => (string) ($data['cons'] ?? ''),
            'status' => Review::STATUS_PENDING,
            'is_verified' => true,
            'ip_hash' => Community::ipHash($ip),
        ]);
        $review->save();

        $order->forceFill([
            'review_token' => null,
            'review_token_expires_at' => null,
        ])->save();

        $this->notifyAdmin($review);

        return $review;
    }

    public function approve(Review $review): Review
    {
        $review->status = Review::STATUS_APPROVED;
        $review->published_at = $review->published_at ?: now();
        $review->save();

        return $review->fresh();
    }

    public function reject(Review $review): Review
    {
        $review->status = Review::STATUS_REJECTED;
        $review->published_at = null;
        $review->save();

        return $review->fresh();
    }

    protected function notifyAdmin(Review $review): void
    {
        $admin = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        if ($admin === '') {
            Log::warning('Elv Kit: некуда отправить письмо о новом отзыве', ['review' => $review->id]);

            return;
        }

        try {
            Mail::to($admin)->queue(new ReviewAdmin($review));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо о новом отзыве не отправлено', [
                'review' => $review->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
