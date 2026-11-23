<?php

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\BookingReminders;
use Elv\Kit\Classes\Mail\BookingReminder;
use Elv\Kit\Classes\Mail\BookingReminderAdmin;
use Elv\Kit\Classes\Slots;
use Elv\Kit\Models\Booking;
use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Slot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

$errors = [];

$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

$settings = Settings::instance();
$backup = $settings->getAttribute('booking_services');
$backupReminderEnabled = $settings->getAttribute('booking_reminder_enabled');
$backupReminderHours = $settings->getAttribute('booking_reminder_hours');
$backupReminderAdmin = $settings->getAttribute('booking_reminder_admin');

$settings->forceFill(['booking_services' => '']);
$check(Slots::services() === [], 'empty settings still return booking services');
$check(!str_contains(json_encode(Slots::services(), JSON_UNESCAPED_UNICODE) ?: '', '120 BYN'), 'empty settings leak the demo price');
$check(Slots::service('consult') === null, 'empty settings still resolve the demo service code');

$settings->forceFill([
    'booking_services' => "haircut | Стрижка | 60 | 40 BYN | Коротко о процедуре | scissors\n"
        . "massage | Массаж | 90 | 80 BYN",
]);
$services = Slots::services();
$check(count($services) === 2, 'booking services are not parsed from settings');
$check(($services[0]['code'] ?? '') === 'haircut', 'booking service code is not parsed');
$check(($services[0]['name'] ?? '') === 'Стрижка', 'booking service name is not parsed');
$check((int) ($services[0]['duration'] ?? 0) === 60, 'booking service duration is not parsed');
$check(($services[0]['price'] ?? '') === '40 BYN', 'booking service price is not parsed');
$check(($services[0]['description'] ?? '') === 'Коротко о процедуре', 'booking service description is missing');
$check(($services[0]['icon'] ?? '') === 'scissors', 'booking service icon is missing');
$check(($services[1]['icon'] ?? 'x') === '', 'missing icon must stay empty');
$check(($services[1]['description'] ?? 'x') === '', 'missing description must stay empty');
$check((Slots::service('massage')['name'] ?? '') === 'Массаж', 'service lookup does not use settings');

$settings->forceFill([
    'booking_services' => "мусор без полей\n"
        . "broken | Без часа и цены\n"
        . "bad code! | Услуга | 0 | 10 BYN | x\n"
        . "ok | Норм | 30 | 0 BYN",
]);
$check(count(Slots::services()) === 1, 'malformed booking lines are not skipped');

$settings->forceFill(['booking_services' => "ok | Норм | 5 | 0 BYN\nlong | Долго | 481 | 0 BYN\nzero | Ноль | 4 | 0 BYN"]);
$check(count(Slots::services()) === 1, 'duration bound is not enforced');

$settings->forceFill(['booking_services' => "# комментарий\nok | Норм | 30 | 0 BYN | Труба | pipe"]);
$check(count(Slots::services()) === 1, 'comment line is not skipped');

$settings->forceFill(['booking_services' => "ok | Норм | 30 | 0 BYN | Труба | pipe"]);
$lines = Slots::servicesToLines(Slots::services());
$check(str_contains($lines, ' | '), 'settings lines must keep the pipe separator');
$check((Slots::parseServices($lines) === Slots::services()), 'settings lines do not round-trip');

$config = Slots::configServices();
$check(count($config) > 0, 'demo booking dataset is missing');
$check(Slots::servicesToLines($config) !== '', 'demo booking dataset is not convertible to settings lines');

DB::beginTransaction();

try {
    $date = date('Y-m-d', strtotime('+2 days'));
    $slot = Slot::create([
        'service_code' => 'zz-check',
        'date' => $date,
        'time' => '9:05',
        'capacity' => 2,
        'taken' => 0,
    ]);
    $check($slot->time === '09:05', 'slot time is not normalized to HH:MM');
    $check($slot->date === $date, 'slot date is not normalized to Y-m-d');

    $duplicate = false;

    try {
        Slot::create([
            'service_code' => 'zz-check',
            'date' => $date,
            'time' => '09:05',
            'capacity' => 2,
            'taken' => 0,
        ]);
    }
    catch (ValidationException $e) {
        $duplicate = true;
    }

    $check($duplicate, 'duplicate slot is not rejected');

    $badTime = false;

    try {
        Slot::create([
            'service_code' => 'zz-check',
            'date' => $date,
            'time' => '25:00',
            'capacity' => 1,
            'taken' => 0,
        ]);
    }
    catch (ValidationException $e) {
        $badTime = true;
    }

    $check($badTime, 'invalid slot time is not rejected');

    $overbooked = false;

    try {
        Slot::create([
            'service_code' => 'zz-check',
            'date' => $date,
            'time' => '10:00',
            'capacity' => 1,
            'taken' => 2,
        ]);
    }
    catch (ValidationException $e) {
        $overbooked = true;
    }

    $check($overbooked, 'taken above capacity is not rejected');

    $slot->taken = 1;
    $slot->save();

    $deleted = true;

    try {
        $slot->delete();
        $deleted = false;
    }
    catch (ValidationException $e) {
        // Занятый слот удалять нельзя — это и проверяем.
    }

    $check($deleted, 'taken slot is deleted');

    $id = (int) $slot->id;
    $slot->taken = 0;
    $slot->save();
    $slot->delete();
    $check(Slot::query()->find($id) === null, 'free slot is not deleted');

    $range = Slots::generateRange('ok', '2030-01-07', '2030-01-08', '10:00', '11:00', 60, 2, false);
    $check($range['created'] === 4, 'slot range generation did not create four slots');
    $check($range['total'] === 4, 'slot range generation reports the wrong total');

    $repeat = Slots::generateRange('ok', '2030-01-07', '2030-01-08', '10:00', '11:00', 60, 2, false);
    $check($repeat['created'] === 0, 'repeated slot generation creates duplicates');
    $check($repeat['skipped'] === 4, 'repeated slot generation does not skip existing slots');

    $generated = Slot::query()
        ->where('service_code', 'ok')
        ->whereBetween('date', ['2030-01-07', '2030-01-08'])
        ->get();
    $check($generated->count() === 4, 'generated slot count does not match');
    $check((int) ($generated->first()->capacity ?? 0) === 2, 'generated slot capacity is not applied');

    $reversed = false;

    try {
        Slots::generateRange('ok', '2030-01-08', '2030-01-07', '10:00', '11:00');
    }
    catch (\InvalidArgumentException $e) {
        $reversed = true;
    }

    $check($reversed, 'reversed slot range is not rejected');

    $overLimit = false;

    try {
        Slots::generateRange('ok', '2030-01-01', '2030-12-31', '10:00', '18:00', 5);
    }
    catch (\InvalidArgumentException $e) {
        $overLimit = true;
    }

    $check($overLimit, 'slot range over 500 is not rejected');

    $unknownService = false;

    try {
        Slots::generateRange('zz-no-such', '2030-01-07', '2030-01-08', '10:00', '11:00');
    }
    catch (\InvalidArgumentException $e) {
        $unknownService = true;
    }

    $check($unknownService, 'slot range accepts a service missing from settings');

    $check(BookingReminders::normalizeHours(0) === BookingReminders::DEFAULT_HOURS, 'zero reminder hours are not defaulted');
    $check(BookingReminders::normalizeHours(500) === 72, 'reminder hours are not capped at 72');
    $check(BookingReminders::normalizeHours(1) === 1, 'one hour reminder is not allowed');

    $settings->forceFill(['booking_reminder_enabled' => true, 'booking_reminder_hours' => 12]);
    $check(BookingReminders::enabled() === true, 'reminder switch is not read from settings');
    $check(BookingReminders::hours() === 12, 'reminder hours are not read from settings');

    $settings->forceFill(['booking_reminder_hours' => 500]);
    $check(BookingReminders::hours() === 72, 'reminder hours from settings are not capped');

    $now = \Carbon\Carbon::parse('2026-12-01 10:00:00');
    $makeBooking = function (array $attributes = []) {
        return Booking::create(array_merge([
            'service_code' => 'zz-check',
            'service' => 'Проверка',
            'price' => '0 BYN',
            'date' => '2026-12-01',
            'time' => '18:00',
            'person' => 'Тест',
            'email' => 'zz-check@example.com',
            'status' => Booking::STATUS_NEW,
        ], $attributes));
    };

    $due = $makeBooking();
    $makeBooking(['date' => '2026-12-03']);
    $makeBooking(['time' => '12:00', 'status' => Booking::STATUS_CANCELLED]);
    $makeBooking(['time' => '12:30', 'email' => '']);
    $alreadyReminded = $makeBooking(['time' => '13:00']);
    $alreadyReminded->reminded_at = $now->copy()->subHour();
    $alreadyReminded->save();

    $pending = BookingReminders::pending(24, $now);
    $check($pending->count() === 1, 'reminder selection does not return exactly the due booking');
    $check((int) ($pending->first()->id ?? 0) === (int) $due->id, 'reminder selection picked the wrong booking');
    $check(BookingReminders::moment($due) !== null, 'reminder moment is not parsed from date and time');
    $check(BookingReminders::moment($makeBooking(['time' => 'nonsense'])) === null, 'broken booking time still produces a moment');

    Mail::fake();
    $sent = BookingReminders::send(24, $now);
    $check($sent === 1, 'reminder send does not report one letter');
    $check($due->fresh()->reminded_at !== null, 'reminder mark is not saved');
    Mail::assertQueued(BookingReminder::class);
    $check(BookingReminders::pending(24, $now)->count() === 0, 'reminded booking is selected twice');

    $settings->forceFill(['booking_reminder_admin' => true]);
    $check(BookingReminders::pendingAdmin(24, $now)->count() === 3, 'admin reminder selection is wrong');
    $adminSent = BookingReminders::sendAdmin(24, $now);
    $check($adminSent === 3, 'admin reminder send does not report three letters');
    $check($due->fresh()->admin_reminded_at !== null, 'admin reminder mark is not saved');
    Mail::assertQueued(BookingReminderAdmin::class);
    $check(BookingReminders::pendingAdmin(24, $now)->count() === 0, 'admin reminder is sent twice');
}
finally {
    DB::rollBack();
}

$settings->forceFill(['booking_services' => $backup]);
$settings->forceFill([
    'booking_reminder_enabled' => $backupReminderEnabled,
    'booking_reminder_hours' => $backupReminderHours,
    'booking_reminder_admin' => $backupReminderAdmin,
]);

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, 'FAIL: ' . $error . PHP_EOL);
    }

    exit(1);
}

echo 'OK: booking checks passed' . PHP_EOL;
