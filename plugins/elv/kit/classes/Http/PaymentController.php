<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\Payment\PaymentManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * PaymentController — вебхуки шлюзов.
 *
 * Ответ 400 на невалидную подпись нужен, чтобы шлюз повторил доставку;
 * 200 на неизвестное событие — чтобы не копил ретраи. Оплата фиксируется
 * только в `OrderService::markPaid`, повторный вебхук идемпотентен.
 */
class PaymentController
{
    public function __construct(protected PaymentManager $payments = new PaymentManager())
    {
    }

    public function yookassa(Request $request)
    {
        try {
            $ok = $this->payments->handleYooKassa($request);
        }
        catch (\Throwable $e) {
            Log::error('Elv Kit: ошибка обработки вебхука ЮKassa', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => $ok], $ok ? 200 : 400);
    }

    public function stripe(Request $request)
    {
        try {
            $ok = $this->payments->handleStripe($request);
        }
        catch (\Throwable $e) {
            Log::error('Elv Kit: ошибка обработки вебхука Stripe', ['error' => $e->getMessage()]);

            return response()->json(['ok' => false], 500);
        }

        return response()->json(['ok' => $ok], $ok ? 200 : 400);
    }
}
