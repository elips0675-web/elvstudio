<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use Elv\Kit\Classes\BookingService;
use Elv\Kit\Models\Booking;
use Flash;

/**
 * Брони — раздел админки Elv Kit.
 */
class Bookings extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.bookings'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'bookings');
    }

    /**
     * onCancelBooking освобождает слот, а не просто меняет подпись статуса.
     */
    public function onCancelBooking()
    {
        $booking = Booking::query()->find((int) post('id'));

        if (!$booking) {
            throw new \ApplicationException('Бронь не найдена.');
        }

        (new BookingService())->cancel($booking);

        Flash::success('Бронь отменена, слот освобождён.');

        return $this->listRefresh();
    }
}