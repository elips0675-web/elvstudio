<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;

/**
 * Релизы — опубликованные сборки архива Elv Kit.
 *
 * Записи создаёт команда `elv:kit:package`; в админке владелец видит версии,
 * размер, контрольную сумму и решает, какая сборка действующая.
 */
class Releases extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.releases'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'releases');
    }
}
