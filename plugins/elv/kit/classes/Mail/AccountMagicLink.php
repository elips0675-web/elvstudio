<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Customer;
use Elv\Kit\Models\Settings;
use October\Rain\Mail\Mailable;

/**
 * AccountMagicLink — одноразовая ссылка в личный кабинет.
 *
 * Пароля нет, поэтому письмо и есть ключ. Ссылка живёт 30 минут и работает
 * один раз; в письме нет данных заказов — только адрес входа.
 */
class AccountMagicLink extends Mailable
{
    public function __construct(public Customer $customer)
    {
    }

    public function build()
    {
        $brand = (string) Settings::get('brand_name', 'Elv Kit');

        return $this->subject('Вход в личный кабинет ' . $brand)
            ->view('elv.kit::mail.account-magic-link')
            ->with([
                'link' => $this->customer->magicLink(),
                'minutes' => Customer::TOKEN_TTL_MINUTES,
                'brand' => $brand,
            ]);
    }
}
