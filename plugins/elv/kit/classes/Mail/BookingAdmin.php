<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Booking;
use October\Rain\Mail\Mailable;

/**
 * BookingAdmin — заявка на бронь для администратора.
 */
class BookingAdmin extends Mailable
{
    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        return $this->subject('Бронь: ' . ($this->booking->service ?: $this->booking->service_code))
            ->view('elv.kit::mail.booking-admin')
            ->with([
                'booking' => [
                    'id' => (int) $this->booking->id,
                    'service' => (string) $this->booking->service,
                    'date' => (string) $this->booking->date,
                    'time' => (string) $this->booking->time,
                    'person' => (string) $this->booking->person,
                    'phone' => (string) $this->booking->phone,
                    'email' => (string) $this->booking->email,
                    'comment' => (string) $this->booking->comment,
                ],
                'backend' => \Backend::url('elv/kit/bookings/update/' . $this->booking->id),
            ]);
    }
}
