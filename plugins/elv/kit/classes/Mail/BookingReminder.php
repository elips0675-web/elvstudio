<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Booking;
use October\Rain\Mail\Mailable;

/**
 * BookingReminder — напоминание клиенту о визите.
 */
class BookingReminder extends Mailable
{
    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        return $this->subject('Напоминание: ' . $this->booking->date . ' в ' . $this->booking->time)
            ->view('elv.kit::mail.booking-reminder')
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
