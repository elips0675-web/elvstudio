<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Classes\Money;
use Elv\Kit\Models\License;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Settings;
use October\Rain\Mail\Mailable;

/**
 * OrderCustomer — письмо покупателю: состав заказа и ссылка на скачивание.
 */
class OrderCustomer extends Mailable
{
    public function __construct(public Order $order, public License $license)
    {
    }

    public function build()
    {
        $brand = (string) Settings::get('brand_name', 'Elv Kit');

        return $this->subject($brand . ': заказ ' . $this->order->order_no . ' оплачен')
            ->view('elv.kit::mail.order-customer')
            ->with([
                'order' => [
                    'number' => $this->order->order_no,
                    'product' => $this->order->product_name,
                    'plan' => $this->order->plan_label,
                    'amount' => Money::format((int) $this->order->amount_cents, (string) $this->order->currency),
                    'name' => (string) $this->order->customer_name,
                ],
                'license' => [
                    'key' => (string) $this->license->license_key,
                    'limit' => (int) $this->license->domain_limit,
                ],
                'download' => (string) $this->order->download_url,
            ]);
    }
}
