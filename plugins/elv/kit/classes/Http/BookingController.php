<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\BookingService;
use Elv\Kit\Classes\SlotUnavailableException;
use Elv\Kit\Classes\Slots;
use Illuminate\Http\Request;

/**
 * BookingController — запись на демо-стенд и консультацию.
 *
 * Проверка «услуга существует» идёт до записи: слот с чужим `service_code`
 * иначе создал бы бронь-сироту, которую никто не увидит в расписании.
 */
class BookingController extends FormController
{
    public function __construct(protected BookingService $bookings = new BookingService())
    {
    }

    protected function rules(): array
    {
        return [
            'service_code' => 'required|string|max:64',
            'date' => 'required|date_format:Y-m-d|after_or_equal:today',
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'person' => 'required|string|max:160',
            'phone' => 'required|string|max:32',
            'email' => 'nullable|email|max:191',
            'comment' => 'nullable|string|max:2000',
            'consent' => 'accepted',
        ];
    }

    protected function messages(): array
    {
        return [
            'service_code.required' => 'Выберите услугу.',
            'date.required' => 'Выберите дату.',
            'date.date_format' => 'Дата должна быть в формате ГГГГ-ММ-ДД.',
            'date.after_or_equal' => 'Дата уже прошла — выберите другую.',
            'time.required' => 'Выберите время.',
            'time.regex' => 'Время должно быть в формате ЧЧ:ММ.',
            'person.required' => 'Укажите имя.',
            'phone.required' => 'Укажите телефон.',
            'email.email' => 'Проверьте адрес электронной почты.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }

    protected function handle(array $data, Request $request)
    {
        $service = Slots::service((string) $data['service_code']);

        if (!$service) {
            return $this->invalid($request, ['service_code' => ['Такой услуги нет.']]);
        }

        $data['ip'] = AntiSpam::ip($request);
        $data['source'] = 'booking';

        try {
            $booking = $this->bookings->book($data);
        }
        catch (SlotUnavailableException $e) {
            return $this->conflict($request, 'Это время уже занято — выберите другое.');
        }

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'booking' => $booking->id,
                'date' => $booking->date,
                'time' => $booking->time,
            ], 201);
        }

        return $this->back($request, ['sent' => '1'])
            ->with('elv_success', 'Записали: ' . $booking->date . ' в ' . $booking->time . '. Подтверждение отправили на e-mail.');
    }

    protected function fallbackPath(): string
    {
        return '/booking/';
    }

    protected function anchor(): string
    {
        return 'booking-form';
    }
}
