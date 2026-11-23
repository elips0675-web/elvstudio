<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;

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
}
