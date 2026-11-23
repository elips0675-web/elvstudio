<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\OrderAdmin;
use Elv\Kit\Classes\Mail\OrderCustomer;
use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * OrderService — единственное место, где меняется статус заказа.
 *
 * Оплата отмечается только после подтверждения шлюза: webhook с валидной
 * подписью (Stripe) или повторный GET статуса (ЮKassa). Mock-режим
 * отключается в production и не выдаёт файл в обход оплаты.
 */
class OrderService
{
    public function create(array $data, Product $product): Order
    {
        $plan = (string) ($data['plan'] ?? '');
        $known = array_keys(Catalog::planLabels());

        if (!in_array($plan, $known, true)) {
            $plan = (string) ($known[0] ?? 'single');
        }

        $amount = Prices::resolve($product, $plan);

        if (!empty($data['bump'])) {
            $amount += 1900;
        }

        $order = new Order();
        $order->fill([
            'order_no' => Order::nextNumber(),
            'product_id' => $product->id,
            'product_name' => $product->name,
            'plan' => $plan,
            'qty' => 1,
            'amount_cents' => $amount,
            'currency' => Money::currency(),
            'status' => Order::STATUS_NEW,
            'customer_name' => AntiSpam::clean($data['customer_name'] ?? '', 160),
            'email' => mb_strtolower(AntiSpam::clean($data['email'] ?? '', 191)),
            'phone' => AntiSpam::clean($data['phone'] ?? '', 32),
            'company' => AntiSpam::clean($data['company'] ?? '', 191),
            'comment' => AntiSpam::clean($data['comment'] ?? '', 2000),
            'ip' => (string) ($data['ip'] ?? ''),
            'meta' => [
                'bump' => !empty($data['bump']),
                'plan_label' => $product->planLabel($plan),
                'product_slug' => $product->slug,
            ],
        ]);

        $order->save();

        $this->notifyAdmin($order);

        return $order;
    }

    public function setPending(Order $order, string $provider, ?string $paymentId = null): Order
    {
        $order->status = Order::STATUS_PENDING;
        $order->payment_provider = $provider;
        $order->payment_id = $paymentId;
        $order->save();

        return $order;
    }

    /**
     * markPaid — идемпотентная фиксация оплаты, выдача токена и лицензии.
     */
    public function markPaid(Order $order, string $provider, string $paymentId = '', array $meta = []): Order
    {
        return DB::transaction(function () use ($order, $provider, $paymentId, $meta) {
            $fresh = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if ($fresh->isPaid()) {
                return $fresh;
            }

            $fresh->status = Order::STATUS_PAID;
            $fresh->payment_provider = $provider ?: $fresh->payment_provider;
            $fresh->payment_id = $paymentId ?: $fresh->payment_id;
            $fresh->paid_at = now();
            $fresh->download_token = $fresh->download_token ?: bin2hex(random_bytes(24));
            $fresh->meta = array_merge((array) $fresh->meta, $meta, ['paid_by' => $provider]);
            $fresh->save();

            $license = $this->issueLicense($fresh);

            try {
                Mail::to($fresh->email)->queue(new OrderCustomer($fresh, $license));
            }
            catch (\Throwable $e) {
                Log::warning('Elv Kit: письмо покупателю не поставлено в очередь', ['order' => $fresh->order_no, 'error' => $e->getMessage()]);
            }

            return $fresh;
        });
    }

    public function issueLicense(Order $order): License
    {
        $slug = (string) (($order->meta['product_slug'] ?? '') ?: optional($order->product)->slug ?: 'elv-kit');

        $license = License::query()->where('order_id', $order->id)->first();

        if ($license) {
            return $license;
        }

        $license = new License();
        $license->license_key = License::generateKey();
        $license->product_slug = $slug;
        $license->email = $order->email;
        $license->order_id = $order->id;
        $license->domain_limit = Catalog::planDomainLimit((string) $order->plan);
        $license->status = License::STATUS_ACTIVE;
        $license->save();

        return $license;
    }

    public function cancel(Order $order): Order
    {
        if ($order->isPaid()) {
            return $order;
        }

        $order->status = Order::STATUS_CANCELLED;
        $order->save();

        return $order;
    }

    protected function notifyAdmin(Order $order): void
    {
        $email = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        if ($email === '') {
            return;
        }

        try {
            Mail::to($email)->queue(new OrderAdmin($order));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо администратору не поставлено в очередь', ['order' => $order->order_no, 'error' => $e->getMessage()]);
        }
    }
}
