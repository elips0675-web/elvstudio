<?php namespace Elv\Kit\Classes\Payment;

use Elv\Kit\Models\Order;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Stripe — Checkout Session. Карты вводятся на стороне Stripe.
 */
class Stripe
{
    public function secret(): string
    {
        return (string) (env('STRIPE_SECRET') ?: Settings::get('stripe_secret'));
    }

    public function webhookSecret(): string
    {
        return (string) (env('STRIPE_WEBHOOK_SECRET') ?: Settings::get('stripe_webhook_secret'));
    }

    public function configured(): bool
    {
        return $this->secret() !== '';
    }

    /**
     * @return array{id: string, url: string}
     */
    public function create(Order $order): array
    {
        $currency = strtolower($order->currency === 'BYN' ? 'byn' : ($order->currency === 'RUB' ? 'rub' : 'usd'));

        $response = Http::withToken($this->secret())
            ->asForm()
            ->timeout(20)
            ->post('https://api.stripe.com/v1/checkout/sessions', [
                'mode' => 'payment',
                'success_url' => url('/order/success/' . $order->order_no . '/?session_id={CHECKOUT_SESSION_ID}'),
                'cancel_url' => url('/order/?cancelled=1'),
                'customer_email' => $order->email,
                'client_reference_id' => $order->order_no,
                'metadata[order_no]' => $order->order_no,
                'line_items[0][quantity]' => 1,
                'line_items[0][price_data][currency]' => $currency,
                'line_items[0][price_data][unit_amount]' => (int) $order->amount_cents,
                'line_items[0][price_data][product_data][name]' => 'Elv Kit: ' . $order->product_name,
                'line_items[0][price_data][product_data][description]' => $order->plan_label . ' · ' . $order->order_no,
            ]);

        if (!$response->successful()) {
            Log::error('Elv Kit: Stripe отказал', ['status' => $response->status(), 'body' => $response->body()]);
            throw new \RuntimeException('Stripe не создал сессию оплаты.');
        }

        $data = $response->json();

        return [
            'id' => (string) ($data['id'] ?? ''),
            'url' => (string) ($data['url'] ?? ''),
        ];
    }

    /**
     * verifySignature — Stripe-Signature: t=<unix>,v1=<hmac>.
     */
    public function verifySignature(string $payload, string $header): bool
    {
        $secret = $this->webhookSecret();

        if ($secret === '' || $header === '') {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);

            if (count($pair) !== 2) {
                continue;
            }

            if ($pair[0] === 't') {
                $timestamp = (int) $pair[1];
            }

            if ($pair[0] === 'v1') {
                $signatures[] = $pair[1];
            }
        }

        if ($timestamp === null || $signatures === []) {
            return false;
        }

        if (abs(time() - $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
