<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Booking;
use October\Rain\Mail\Mailable;

/**
 * BookingReminderAdmin — напоминание мастеру о записи.
 */
class BookingReminderAdmin extends Mailable
{
    public function __construct(public Booking $booking)
    {
    }

    public function build()
    {
        return $this->subject('Напоминание о записи: ' . $this->booking->date . ' в ' . $this->booking->time)
            ->view('elv.kit::mail.booking-reminder-admin')
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
            ]);
    }
}
