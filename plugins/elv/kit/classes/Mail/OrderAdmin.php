<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Classes\Money;
use Elv\Kit\Models\Order;
use October\Rain\Mail\Mailable;

/**
 * OrderAdmin — уведомление администратору о новом заказе.
 */
class OrderAdmin extends Mailable
{
    public function __construct(public Order $order)
    {
    }

    public function build()
    {
        return $this->subject('Новый заказ ' . $this->order->order_no)
            ->view('elv.kit::mail.order-admin')
            ->with([
                'order' => [
                    'number' => $this->order->order_no,
                    'product' => $this->order->product_name,
                    'plan' => $this->order->plan_label,
                    'amount' => Money::format((int) $this->order->amount_cents, (string) $this->order->currency),
                    'customer' => (string) $this->order->customer_name,
                    'email' => (string) $this->order->email,
                    'phone' => (string) $this->order->phone,
                    'company' => (string) $this->order->company,
                    'comment' => (string) $this->order->comment,
                ],
                'backend' => \Backend::url('elv/kit/orders/update/' . $this->order->id),
            ]);
    }
}
