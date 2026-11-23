<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Subscriber;
use October\Rain\Mail\Mailable;

/**
 * SubscribeConfirm — double opt-in: подписка включается только по ссылке.
 */
class SubscribeConfirm extends Mailable
{
    public function __construct(public Subscriber $subscriber)
    {
    }

    public function build()
    {
        $brand = (string) Settings::get('brand_name', 'Elv Kit');

        return $this->subject('Подтвердите подписку на блог ' . $brand)
            ->view('elv.kit::mail.subscribe-confirm')
            ->with([
                'confirm' => url('/subscribe/confirm/' . $this->subscriber->token),
                'unsubscribe' => url('/unsubscribe/' . $this->subscriber->token),
            ]);
    }
}
