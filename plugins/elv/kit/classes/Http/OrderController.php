<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\OrderService;
use Elv\Kit\Classes\Payment\PaymentManager;
use Elv\Kit\Classes\PaymentUnavailableException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * OrderController — приём заказа и запуск оплаты.
 *
 * Заказ создаётся до обращения к шлюзу: если шлюз недоступен, заказ остаётся
 * в статусе `new` и его видно в админке. Файл при этом не выдаётся.
 */
class OrderController extends FormController
{
    public function __construct(
        protected OrderService $orders = new OrderService(),
        protected PaymentManager $payments = new PaymentManager()
    ) {
    }

    protected function rules(): array
    {
        return [
            'product' => 'required|string|max:160',
            'plan' => 'required|in:' . implode(',', array_keys(Catalog::planLabels())),
            'customer_name' => 'required|string|max:160',
            'email' => 'required|email|max:191',
            'phone' => 'nullable|string|max:32',
            'company' => 'nullable|string|max:191',
            'comment' => 'nullable|string|max:2000',
            'consent' => 'accepted',
            'bump' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return [
            'product.required' => 'Товар не выбран.',
            'plan.required' => 'Выберите тариф.',
            'plan.in' => 'Такого тарифа нет.',
            'customer_name.required' => 'Укажите имя.',
            'email.required' => 'Укажите e-mail — на него придёт доступ.',
            'email.email' => 'Проверьте адрес электронной почты.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }

    protected function handle(array $data, Request $request)
    {
        $product = Catalog::find((string) $data['product']);

        if (!$product) {
            return $this->invalid($request, ['product' => ['Товар не найден.']]);
        }

        if ($product->kind === 'kit' && !in_array((string) $data['plan'], Catalog::planKeys(), true)) {
            return $this->invalid($request, ['plan' => ['Этот тариф больше не продаётся. Выберите другой.']]);
        }

        $data['ip'] = AntiSpam::ip($request);

        $order = $this->orders->create($data, $product);

        try {
            $payment = $this->payments->start($order);
        }
        catch (PaymentUnavailableException $e) {
            Log::warning('Elv Kit: оплата недоступна', ['order' => $order->order_no]);

            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'PAYMENT_UNAVAILABLE',
                    'message' => 'Оплата временно недоступна. Мы сохранили заказ и свяжемся с вами.',
                    'order' => $order->order_no,
                ], 503);
            }

            return $this->back($request, ['error' => 'payment'])
                ->with('elv_error', 'Оплата временно недоступна. Заказ ' . $order->order_no . ' сохранён — мы свяжемся с вами.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'order' => $order->order_no,
                'redirect' => $payment['url'],
                'mock' => $payment['mock'],
            ], 201);
        }

        return redirect($payment['url']);
    }

    protected function fallbackPath(): string
    {
        return '/order/';
    }

    protected function anchor(): string
    {
        return 'order-form';
    }
}
