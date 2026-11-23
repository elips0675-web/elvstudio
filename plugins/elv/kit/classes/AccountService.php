<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\AccountMagicLink;
use Elv\Kit\Models\Customer;
use Elv\Kit\Models\Install;
use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * AccountService — вход в кабинет и данные покупателя.
 *
 * Один и тот же e-mail связывает заказы, лицензии и домены. Пароля нет:
 * `requestLink()` выдаёт одноразовый токен, `verify()` гасит его и открывает
 * сессию. Домен отвязывается не чаще раза в 30 дней на лицензию — иначе
 * лимит доменов теряет смысл.
 */
class AccountService
{
    public const SESSION_KEY = 'elv_kit_account_id';
    public const UNBIND_DAYS = 30;

    protected ?Customer $customer = null;

    protected bool $loaded = false;

    /** @var array<int, \Illuminate\Support\Collection> */
    protected array $cache = [];

    protected function remember(string $key, Customer $customer, callable $callback)
    {
        $id = (int) $customer->id;
        $cacheKey = $key . ':' . $id;

        if (!isset($this->cache[$cacheKey])) {
            $this->cache[$cacheKey] = $callback();
        }

        return $this->cache[$cacheKey];
    }

    public function user(): ?Customer
    {
        if ($this->loaded) {
            return $this->customer;
        }

        $this->loaded = true;
        $id = (int) session()->get(self::SESSION_KEY);

        if ($id > 0) {
            $this->customer = Customer::query()->find($id);
        }

        return $this->customer;
    }

    public function login(Customer $customer): void
    {
        session()->put(self::SESSION_KEY, (int) $customer->id);
        session()->regenerate();

        $this->customer = $customer;
        $this->loaded = true;
    }

    public function logout(): void
    {
        session()->forget(self::SESSION_KEY);

        $this->customer = null;
        $this->loaded = true;
    }

    /**
     * requestLink — находит или создаёт покупателя и отправляет ссылку.
     *
     * Ответ формы одинаковый для известного и неизвестного адреса: иначе по
     * тексту можно перебрать базу покупателей.
     */
    public function requestLink(string $email, string $name = ''): Customer
    {
        $email = mb_strtolower(trim($email));

        $customer = Customer::query()->where('email', $email)->first();

        if (!$customer) {
            $customer = new Customer();
            $customer->email = $email;
        }

        if ($name !== '' && trim((string) $customer->name) === '') {
            $customer->name = mb_substr($name, 0, 160);
        }

        $customer->save();
        $customer->issueLoginToken();

        try {
            Mail::to($customer->email)->queue(new AccountMagicLink($customer));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: ссылка в кабинет не отправлена', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }

        return $customer;
    }

    /**
     * verify — одноразовый переход по ссылке из письма.
     */
    public function verify(string $token): ?Customer
    {
        $token = trim($token);

        if ($token === '') {
            return null;
        }

        $customer = Customer::query()->where('login_token', $token)->first();

        if (!$customer || !$customer->hasValidLoginToken()) {
            return null;
        }

        $customer->clearLoginToken();
        $customer->last_login_at = now();
        $customer->save();

        $this->login($customer);

        return $customer;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Order>
     */
    public function orders(Customer $customer)
    {
        return $this->remember('orders', $customer, function () use ($customer) {
            return Order::query()
                ->where('email', $customer->email)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, License>
     */
    public function licenses(Customer $customer)
    {
        return $this->remember('licenses', $customer, function () use ($customer) {
            $orderIds = $this->orders($customer)->pluck('id')->all();

            return License::query()
                ->where(function ($query) use ($customer, $orderIds) {
                    $query->where('email', $customer->email);

                    if ($orderIds !== []) {
                        $query->orWhereIn('order_id', $orderIds);
                    }
                })
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int, Install>
     */
    public function installs(Customer $customer)
    {
        return $this->remember('installs', $customer, function () use ($customer) {
            $licenseIds = $this->licenses($customer)->pluck('id')->all();

            if ($licenseIds === []) {
                return collect();
            }

            return Install::query()
                ->with('license')
                ->whereIn('license_id', $licenseIds)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->get();
        });
    }

    public function ownsInstall(Customer $customer, Install $install): bool
    {
        $license = $install->license;

        if (!$license) {
            return false;
        }

        if (mb_strtolower((string) $license->email) === $customer->email) {
            return true;
        }

        return $license->order_id
            && Order::query()
                ->where('id', (int) $license->order_id)
                ->where('email', $customer->email)
                ->exists();
    }

    /**
     * canUnbind — домен можно снять, если он принадлежит покупателю и с
     * прошлой отвязки этой лицензии прошло 30 дней.
     *
     * @return array{ok: bool, message: string, next_at?: \Carbon\Carbon|null}
     */
    public function canUnbind(Customer $customer, Install $install): array
    {
        if (!$this->ownsInstall($customer, $install)) {
            return ['ok' => false, 'message' => 'Домен не найден в ваших лицензиях.'];
        }

        $license = $install->license;
        $changed = $license->domain_changed_at;

        if ($changed instanceof \Carbon\Carbon) {
            $next = $changed->copy()->addDays(self::UNBIND_DAYS);

            if ($next->isFuture()) {
                return [
                    'ok' => false,
                    'message' => 'Домен можно отвязать раз в 30 дней. Следующая попытка — '
                        . $next->format('d.m.Y') . '.',
                    'next_at' => $next,
                ];
            }
        }

        return ['ok' => true, 'message' => ''];
    }

    /**
     * unbind — снимает домен и ставит отметку лицензии.
     *
     * @return array{ok: bool, message: string}
     */
    public function unbind(Customer $customer, Install $install): array
    {
        $check = $this->canUnbind($customer, $install);

        if (!$check['ok']) {
            return ['ok' => false, 'message' => $check['message']];
        }

        $license = $install->license;
        $domain = (string) ($install->domain ?: 'домен');

        $install->delete();

        $license->domain_changed_at = now();
        $license->save();

        return ['ok' => true, 'message' => 'Домен ' . $domain . ' отвязан. Его можно активировать на другом сайте.'];
    }

    public function nextUnbindAt(License $license): ?\Carbon\Carbon
    {
        if (!$license->domain_changed_at instanceof \Carbon\Carbon) {
            return null;
        }

        return $license->domain_changed_at->copy()->addDays(self::UNBIND_DAYS);
    }
}
