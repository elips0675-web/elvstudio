<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Booking;
use October\Rain\Mail\Mailable;

/**
 * BookingCustomer — подтверждение брони клиенту.
 */
class BookingCustomer extends Mailable
{
    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        return $this->subject('Бронь принята: ' . $this->booking->date . ' в ' . $this->booking->time)
            ->view('elv.kit::mail.booking-customer')
            ->with([
                'booking' => [
                    'service' => (string) $this->booking->service,
                    'date' => (string) $this->booking->date,
                    'time' => (string) $this->booking->time,
                    'person' => (string) $this->booking->person,
                    'price' => (string) $this->booking->price,
                ],
            ]);
    }
}
