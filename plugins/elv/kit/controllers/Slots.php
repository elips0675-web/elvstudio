<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use Elv\Kit\Classes\Slots as SlotGrid;
use Flash;
use Illuminate\Support\Facades\Redirect;
use October\Rain\Exception\ValidationException;

/**
 * Слоты записи — раздел админки Elv Kit.
 *
 * Сетку для сайта строит `Classes\Slots` из этих строк: свободное время,
 * которого нет в таблице, на странице записи не появляется.
 */
class Slots extends Controller
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

        BackendMenu::setContext('Elv.Kit', 'kit', 'slots');
    }

    public function generate()
    {
        $this->pageTitle = 'Генерация слотов';
        $this->vars['services'] = SlotGrid::services();
        $this->vars['serviceCode'] = (string) (SlotGrid::services()[0]['code'] ?? '');
        $this->vars['dateFrom'] = now()->addDay()->toDateString();
        $this->vars['dateTo'] = now()->addDays(14)->toDateString();
        $this->vars['timeFrom'] = '10:00';
        $this->vars['timeTo'] = '18:00';
        $this->vars['step'] = 60;
        $this->vars['capacity'] = 1;
    }

    public function onGenerate()
    {
        $data = post();

        $serviceCode = trim((string) ($data['service_code'] ?? ''));
        $dateFrom = trim((string) ($data['date_from'] ?? ''));
        $dateTo = trim((string) ($data['date_to'] ?? ''));
        $timeFrom = trim((string) ($data['time_from'] ?? ''));
        $timeTo = trim((string) ($data['time_to'] ?? ''));
        $step = (int) ($data['step'] ?? 60);
        $capacity = (int) ($data['capacity'] ?? 1);
        $skipWeekends = (bool) ($data['skip_weekends'] ?? false);

        try {
            $result = SlotGrid::generateRange(
                $serviceCode,
                $dateFrom,
                $dateTo,
                $timeFrom,
                $timeTo,
                $step,
                $capacity,
                $skipWeekends
            );
        }
        catch (\InvalidArgumentException $e) {
            throw new ValidationException(['service_code' => $e->getMessage()]);
        }

        Flash::success(
            'Создано слотов: ' . $result['created']
            . ', уже было: ' . $result['skipped']
            . ' (всего ' . $result['total'] . ').'
        );

        return Redirect::to(\Backend::url('elv/kit/slots'));
    }
}
