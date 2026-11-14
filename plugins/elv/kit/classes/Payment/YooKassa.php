<?php namespace Elv\Kit\Classes\Payment;

use Elv\Kit\Models\Order;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * YooKassa — платёж через API v3.
 *
 * Секреты берутся из .env, настройки — запасной вариант. Подпись вебхука
 * у ЮKassa не HMAC: безопасность держится на повторном GET платежа.
 */
class YooKassa
{
    public function shopId(): string
    {
        return (string) (env('YOOKASSA_SHOP_ID') ?: Settings::get('yookassa_shop_id'));
    }

    public function secret(): string
    {
        return (string) (env('YOOKASSA_SECRET') ?: Settings::get('yookassa_secret'));
    }

    public function configured(): bool
    {
        return $this->shopId() !== '' && $this->secret() !== '';
    }

    /**
     * @return array{id: string, url: string}
     */
    public function create(Order $order): array
    {
        $response = Http::withBasicAuth($this->shopId(), $this->secret())
            ->withHeaders(['Idempotence-Key' => $order->order_no . '-' . substr(md5((string) $order->id), 0, 8)])
            ->timeout(15)
            ->post('https://api.yookassa.ru/v3/payments', [
                'amount' => [
                    'value' => number_format($order->amount_cents / 100, 2, '.', ''),
                    'currency' => $order->currency === 'BYN' ? 'BYN' : ($order->currency === 'RUB' ? 'RUB' : 'USD'),
                ],
                'capture' => true,
                'confirmation' => [
                    'type' => 'redirect',
                    'return_url' => url('/order/success/' . $order->order_no . '/'),
                ],
                'description' => 'Elv Kit: ' . $order->product_name . ' (' . $order->order_no . ')',
                'metadata' => ['order_no' => $order->order_no],
            ]);

        if (!$response->successful()) {
            Log::error('Elv Kit: ЮKassa отказала', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('ЮKassa не создала платёж.');
        }

        $data = $response->json();

        return [
            'id' => (string) ($data['id'] ?? ''),
            'url' => (string) ($data['confirmation']['confirmation_url'] ?? ''),
        ];
    }

    public function fetch(string $paymentId): ?array
    {
        $response = Http::withBasicAuth($this->shopId(), $this->secret())
            ->timeout(15)
            ->get('https://api.yookassa.ru/v3/payments/' . urlencode($paymentId));

        return $response->successful() ? (array) $response->json() : null;
    }
}
