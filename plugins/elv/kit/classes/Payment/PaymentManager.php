<?php namespace Elv\Kit\Classes\Payment;

use Elv\Kit\Classes\Money;
use Elv\Kit\Classes\OrderService;
use Elv\Kit\Classes\PaymentUnavailableException;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PaymentManager — выбор шлюза и безопасный mock.
 *
 * Правила:
 *  - карты не проходят через сервер: только redirect на страницу шлюза;
 *  - mock разрешён только вне production и только при отсутствии ключей
 *    (или явном режиме mock), иначе заказ можно «оплатить» бесплатно;
 *  - вебхук меняет статус только после проверки подписи/статуса.
 */
class PaymentManager
{
    public function __construct(protected OrderService $orders = new OrderService())
    {
    }

    public function provider(): ?string
    {
        if ($this->yookassa()->configured()) {
            return 'yookassa';
        }

        if ($this->stripe()->configured()) {
            return 'stripe';
        }

        return null;
    }

    public function mode(): string
    {
        return (string) (Settings::get('payments_mode') ?: 'auto');
    }

    public function isProduction(): bool
    {
        return app()->environment('production');
    }

    public function mockAllowed(): bool
    {
        if ($this->isProduction()) {
            return false;
        }

        $mode = $this->mode();

        if ($mode === 'live') {
            return false;
        }

        if ($mode === 'mock') {
            return true;
        }

        return $this->provider() === null;
    }

    /**
     * start — создаёт платёж и возвращает адрес, куда редиректить покупателя.
     *
     * @return array{url: string, provider: string, mock: bool}
     */
    public function start(Order $order): array
    {
        $provider = $this->provider();

        if ($provider === 'yookassa') {
            $payment = $this->yookassa()->create($order);
            $this->orders->setPending($order, 'yookassa', $payment['id']);

            return ['url' => $payment['url'], 'provider' => 'yookassa', 'mock' => false];
        }

        if ($provider === 'stripe') {
            $session = $this->stripe()->create($order);
            $this->orders->setPending($order, 'stripe', $session['id']);

            return ['url' => $session['url'], 'provider' => 'stripe', 'mock' => false];
        }

        if ($this->mockAllowed()) {
            $this->orders->markPaid($order, 'mock', 'mock-' . $order->order_no, ['mock' => true]);

            return [
                'url' => url('/order/success/' . $order->order_no . '/?mock=1'),
                'provider' => 'mock',
                'mock' => true,
            ];
        }

        throw new PaymentUnavailableException('Оплата временно недоступна: не настроены ключи шлюза.');
    }

    /**
     * handleYooKassa — вебхук: телу не верим, статус перепроверяем запросом к API.
     */
    public function handleYooKassa(Request $request): bool
    {
        $id = (string) ($request->input('object.id') ?? $request->input('object')['id'] ?? '');

        if ($id === '') {
            return false;
        }

        $payment = $this->yookassa()->fetch($id);

        if (!$payment || ($payment['status'] ?? '') !== 'succeeded') {
            return false;
        }

        $orderNo = (string) ($payment['metadata']['order_no'] ?? '');
        $order = Order::query()->where('order_no', $orderNo)->first();

        if (!$order) {
            Log::warning('Elv Kit: вебхук ЮKassa без заказа', ['payment' => $id, 'order' => $orderNo]);
            return false;
        }

        $expected = number_format($order->amount_cents / 100, 2, '.', '');

        if ((string) ($payment['amount']['value'] ?? '') !== $expected) {
            Log::warning('Elv Kit: сумма платежа не совпала', ['order' => $orderNo, 'expected' => $expected, 'got' => $payment['amount']['value'] ?? '']);
            return false;
        }

        $this->orders->markPaid($order, 'yookassa', $id);

        return true;
    }

    /**
     * handleStripe — проверка подписи и события checkout.session.completed.
     */
    public function handleStripe(Request $request): bool
    {
        $payload = (string) $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        if (!$this->stripe()->verifySignature($payload, $signature)) {
            Log::warning('Elv Kit: подпись Stripe не прошла проверку');
            return false;
        }

        $event = json_decode($payload, true);
        $type = (string) ($event['type'] ?? '');

        if (!in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded'], true)) {
            return true;
        }

        $session = $event['data']['object'] ?? [];
        $orderNo = (string) ($session['metadata']['order_no'] ?? '');
        $order = Order::query()->where('order_no', $orderNo)->first();

        if (!$order) {
            Log::warning('Elv Kit: вебхук Stripe без заказа', ['order' => $orderNo]);
            return false;
        }

        $this->orders->markPaid($order, 'stripe', (string) ($session['id'] ?? ''));

        return true;
    }

    public function yookassa(): YooKassa
    {
        return new YooKassa();
    }

    public function stripe(): Stripe
    {
        return new Stripe();
    }
}
