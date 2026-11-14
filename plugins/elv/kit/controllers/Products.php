<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;

/**
 * Товары — раздел админки Elv Kit.
 */
class Products extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.catalog'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'products');
    }
}