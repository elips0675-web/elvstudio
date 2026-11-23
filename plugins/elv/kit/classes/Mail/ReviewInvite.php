<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Order;
use Elv\Kit\Models\Product;
use October\Rain\Mail\Mailable;

/**
 * ReviewInvite — приглашение оставить отзыв по одноразовой ссылке.
 */
class ReviewInvite extends Mailable
{
    public function __construct(public Order $order, public Product $product, public string $token)
    {
    }

    public function build()
    {
        return $this->subject('Как вам ' . $this->product->name . '?')
            ->view('elv.kit::mail.review-invite')
            ->with([
                'review' => [
                    'product' => (string) $this->product->name,
                    'order' => (string) $this->order->order_no,
                    'url' => url('/review/' . $this->token) . '/',
                ],
            ]);
    }
}
