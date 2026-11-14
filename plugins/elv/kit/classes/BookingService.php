<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\BookingAdmin;
use Elv\Kit\Classes\Mail\BookingCustomer;
use Elv\Kit\Models\Booking;
use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Slot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * BookingService — единственное место, где создаётся бронь.
 *
 * Гонку закрывают два независимых условия: UNIQUE (service_code, date, time)
 * и условный `UPDATE … WHERE taken < capacity`. Если второй запрос задел
 * 0 строк, бронь не создаётся — «записались втроём на одно место» невозможно.
 */
class BookingService
{
    public function book(array $data): Booking
    {
        $code = (string) ($data['service_code'] ?? '');
        $date = (string) ($data['date'] ?? '');
        $time = (string) ($data['time'] ?? '');

        return DB::transaction(function () use ($data, $code, $date, $time) {
            $slot = Slot::query()
                ->where('service_code', $code)
                ->where('date', $date)
                ->where('time', $time)
                ->lockForUpdate()
                ->first();

            if (!$slot || !$slot->isAvailable()) {
                throw new SlotUnavailableException('Выбранное время уже занято.');
            }

            $affected = Slot::query()
                ->whereKey($slot->getKey())
                ->whereColumn('taken', '<', 'capacity')
                ->increment('taken');

            if ($affected === 0) {
                throw new SlotUnavailableException('Выбранное время уже занято.');
            }

            $service = Slots::service($code);

            $booking = new Booking();
            $booking->fill([
                'service_code' => $code,
                'service' => AntiSpam::clean($service['name'] ?? ($data['service'] ?? ''), 160),
                'price' => AntiSpam::clean($service['price'] ?? ($data['price'] ?? ''), 64),
                'date' => $date,
                'time' => $time,
                'person' => AntiSpam::clean($data['person'] ?? '', 160),
                'phone' => AntiSpam::clean($data['phone'] ?? '', 32),
                'email' => mb_strtolower(AntiSpam::clean($data['email'] ?? '', 191)),
                'comment' => AntiSpam::clean($data['comment'] ?? '', 2000),
                'status' => Booking::STATUS_NEW,
                'source' => AntiSpam::clean($data['source'] ?? 'site', 64),
                'ip' => (string) ($data['ip'] ?? ''),
            ]);

            $booking->save();

            $this->notify($booking);

            return $booking;
        });
    }

    public function cancel(Booking $booking): Booking
    {
        if ($booking->isCancelled()) {
            return $booking;
        }

        DB::transaction(function () use ($booking) {
            $booking->status = Booking::STATUS_CANCELLED;
            $booking->save();

            Slot::query()
                ->where('service_code', $booking->service_code)
                ->where('date', $booking->date)
                ->where('time', $booking->time)
                ->where('taken', '>', 0)
                ->decrement('taken');
        });

        return $booking;
    }

    protected function notify(Booking $booking): void
    {
        $admin = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        try {
            if ($admin !== '') {
                Mail::to($admin)->queue(new BookingAdmin($booking));
            }

            if ($booking->email) {
                Mail::to($booking->email)->queue(new BookingCustomer($booking));
            }
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо о брони не отправлено', [
                'booking' => $booking->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
