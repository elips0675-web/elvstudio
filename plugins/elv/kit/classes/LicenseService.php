<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Install;
use Elv\Kit\Models\License;
use Illuminate\Support\Facades\Cache;

/**
 * LicenseService — активация ключа на домене и публичная статистика.
 *
 * Домен хранится хэшем: персональные данные не нужны, а лимит доменов
 * считается по уникальным хэшам. Повторная активация того же домена
 * не увеличивает счётчик.
 */
class LicenseService
{
    /**
     * @return array{ok: bool, message: string, code?: string, installs?: int, limit?: int}
     */
    public function activate(array $data): array
    {
        $key = strtoupper(trim((string) ($data['license_key'] ?? '')));
        $product = trim((string) ($data['product'] ?? ''));
        $domain = strtolower(trim((string) ($data['domain'] ?? '')));
        $version = mb_substr(trim((string) ($data['version'] ?? '')), 0, 32);

        if ($key === '' || $domain === '') {
            return ['ok' => false, 'code' => 'invalid_request', 'message' => 'Нужны license_key и domain.'];
        }

        $license = License::query()->where('license_key', $key)->first();

        if (!$license || !$license->isActive()) {
            return ['ok' => false, 'code' => 'invalid_key', 'message' => 'Ключ не найден или отозван.'];
        }

        if ($product !== '' && $license->product_slug !== $product && $license->product_slug !== 'elv-kit') {
            return ['ok' => false, 'code' => 'product_mismatch', 'message' => 'Ключ выдан для другого продукта.'];
        }

        $hash = hash('sha256', $domain);
        $existing = Install::query()->where('license_id', $license->id)->where('domain_hash', $hash)->first();
        $count = Install::query()->where('license_id', $license->id)->count();

        if (!$existing && $count >= (int) $license->domain_limit) {
            return [
                'ok' => false,
                'code' => 'domain_limit',
                'message' => 'Лимит доменов исчерпан: ' . $license->domain_limit . '.',
                'limit' => (int) $license->domain_limit,
                'installs' => $count,
            ];
        }

        if (!$existing) {
            Install::create([
                'license_id' => $license->id,
                'product_slug' => $license->product_slug,
                'domain_hash' => $hash,
                'domain' => mb_substr($domain, 0, 191),
                'app_version' => $version,
            ]);
            $count++;
        }
        elseif (!$existing->domain) {
            // Активация до 1.1.0 хранила только хэш: при повторном запросе
            // домен дописывается, чтобы владелец видел его в кабинете.
            $existing->domain = mb_substr($domain, 0, 191);
            $existing->save();
        }

        $license->activated_at = $license->activated_at ?: now();
        $license->last_seen_at = now();
        $license->save();

        Cache::forget('elv-kit-stats-' . $license->product_slug);

        return [
            'ok' => true,
            'message' => 'Лицензия активирована.',
            'installs' => $count,
            'limit' => (int) $license->domain_limit,
        ];
    }

    /**
     * stats — активные лицензии и домены, кэш 5 минут.
     *
     * @return array{licenses: int, installs: int}
     */
    public function stats(string $product = ''): array
    {
        $product = trim($product);
        $cacheKey = 'elv-kit-stats-' . ($product !== '' ? $product : 'all');

        return Cache::remember($cacheKey, 300, function () use ($product) {
            $licenses = License::query()->active();

            if ($product !== '') {
                $licenses->where('product_slug', $product);
            }

            $ids = $licenses->pluck('id');
            $installs = Install::query()->whereIn('license_id', $ids)->count();

            return ['licenses' => $ids->count(), 'installs' => $installs];
        });
    }
}
