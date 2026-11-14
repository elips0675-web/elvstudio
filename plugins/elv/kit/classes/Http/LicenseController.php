<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\LicenseService;
use Elv\Kit\Models\Settings;
use Illuminate\Http\Request;

/**
 * LicenseController — API активации ключа.
 *
 * Общий токен не обязателен: ключ и домен уже секрет покупателя. Если токен
 * задан в настройках, запрос без него отклоняется — это защита от перебора
 * ключей через публичный эндпоинт.
 */
class LicenseController
{
    public function __construct(protected LicenseService $licenses = new LicenseService())
    {
    }

    public function activate(Request $request)
    {
        $token = (string) (Settings::get('license_api_token') ?: '');
        $given = (string) ($request->header('X-License-Token', '') ?: $request->input('token', ''));

        if ($token !== '' && !hash_equals($token, $given)) {
            return response()->json(['ok' => false, 'code' => 'forbidden', 'message' => 'Неверный токен API.'], 403);
        }

        $result = $this->licenses->activate([
            'license_key' => $request->input('license_key'),
            'product' => $request->input('product'),
            'domain' => $request->input('domain'),
            'version' => $request->input('version'),
        ]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function stats(Request $request)
    {
        if (!Settings::get('license_stats_public')) {
            return response()->json(['ok' => false, 'code' => 'disabled'], 404);
        }

        $stats = $this->licenses->stats((string) $request->query('product', ''));

        return response()->json(['ok' => true] + $stats);
    }
}
