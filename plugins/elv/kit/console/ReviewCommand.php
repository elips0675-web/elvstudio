<?php namespace Elv\Kit\Console;

use Elv\Kit\Classes\Reviews;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Review;
use Illuminate\Console\Command;

/**
 * ReviewCommand — приглашения оставить отзыв по оплаченным заказам.
 *
 * Письмо уходит один раз: отметка `review_invited_at` закрывает повтор.
 * Ссылка живёт 30 дней и гасится после отправки отзыва. Если приглашения
 * выключены в настройках, команда ничего не делает; `--force` нужен для
 * ручной проверки на стенде.
 */
class ReviewCommand extends Command
{
    protected $signature = 'elv:kit:review
        {--force : отправить даже если приглашения выключены}
        {--limit=50 : сколько писем отправить за прогон}';

    protected $description = 'Приглашает покупателей оставить отзыв по оплаченным заказам.';

    public function handle()
    {
        if (!Reviews::enabled()) {
            $this->line('Отзывы выключены в настройках.');

            return 0;
        }

        if (!Reviews::inviteEnabled() && !$this->option('force')) {
            $this->line('Приглашения выключены в настройках.');

            return 0;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));
        $service = new Reviews();
        $before = now()->subDays(Reviews::delayDays());

        $orders = Order::query()
            ->where('status', Order::STATUS_PAID)
            ->whereNotNull('product_id')
            ->whereNotNull('paid_at')
            ->where('paid_at', '<=', $before)
            ->whereNull('review_invited_at')
            ->orderBy('paid_at')
            ->limit($limit)
            ->get();

        $sent = 0;
        $skipped = 0;

        foreach ($orders as $order) {
            if ($service->existsFor($order) || trim((string) $order->email) === '') {
                $skipped++;
                continue;
            }

            if ($service->sendInvitation($order)) {
                $sent++;
            }
            else {
                $skipped++;
            }
        }

        $this->info('Отправлено приглашений: ' . $sent . ', пропущено: ' . $skipped . '.');

        return 0;
    }
}
