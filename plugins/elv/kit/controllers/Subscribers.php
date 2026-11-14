<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;

/**
 * Подписчики — раздел админки Elv Kit.
 */
class Subscribers extends Controller
{
    public $implement = [
        ListController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $requiredPermissions = ['elv.kit.subscribers'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'subscribers');
    }
}