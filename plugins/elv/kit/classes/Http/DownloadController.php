<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\DownloadService;
use Elv\Kit\Models\Order;
use Illuminate\Support\Facades\Log;

/**
 * DownloadController — выдача ZIP по токену.
 *
 * Отказ отвечает 404, а не 403: по коду ответа нельзя понять, существует
 * ли заказ. Токен одноразовым не делаем — покупатель вправе скачать архив
 * повторно, если потерял файл.
 */
class DownloadController
{
    public function __construct(protected DownloadService $archive = new DownloadService())
    {
    }

    public function show(string $token)
    {
        $order = Order::query()->where('download_token', $token)->first();

        if (!$order || !$order->isPaid()) {
            abort(404);
        }

        try {
            $path = $this->archive->forOrder($order);
        }
        catch (\Throwable $e) {
            Log::error('Elv Kit: архив не собран', ['order' => $order->order_no, 'error' => $e->getMessage()]);

            abort(503, 'Архив временно недоступен. Мы уже разбираемся — напишите в поддержку.');
        }

        if (!$order->delivered_at) {
            $order->delivered_at = now();
            $order->save();
        }

        $filename = 'elv-kit-' . DownloadService::VERSION . '-' . $order->order_no . '.zip';

        return response()->download($path, $filename, [
            'Content-Type' => 'application/zip',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
