<?php namespace Elv\Kit\Models;

use System\Models\SettingModel;

/**
 * Settings — настройки лендинга и модулей (Settings → Elv Kit).
 */
class Settings extends SettingModel
{
    public $settingsCode = 'elv_kit_settings';

    public $settingsFields = 'fields.yaml';
}
