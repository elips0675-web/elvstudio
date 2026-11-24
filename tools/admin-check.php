<?php

/**
 * admin-check — списки и формы админки Elv Kit.
 *
 * Проверяет то, что видно только в браузере: тип колонки списка существует,
 * `select` без `relation` указывает на реальную колонку БД, `valueFrom` —
 * на аксессор модели, а файлы `columns.yaml` / `fields.yaml` из конфигов
 * контроллеров лежат на месте. Ошибка в конфиге списка October показывает
 * 500 на всей странице раздела, поэтому проверка дешёвая и статическая.
 *
 * Заодно сверяет `Install::TABLES` с миграциями: страница установки не должна
 * писать «Готово», если половины таблиц плагина в базе нет.
 *
 * Запуск: php tools/admin-check.php
 */

require __DIR__ . '/../bootstrap/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

$errors = [];
$check = function (bool $condition, string $message) use (&$errors) {
    if (!$condition) {
        $errors[] = $message;
    }
};

/**
 * Типы колонок списка — методы eval*TypeValue в
 * modules/backend/widgets/lists/HasValueProcessor.php.
 */
$types = [
    'text', 'number', 'image', 'file', 'switch', 'summary', 'datetime',
    'time', 'date', 'timesince', 'timetense', 'selectable', 'linkage',
    'partial', 'colorpicker',
];

$pluginPath = base_path('plugins/elv/kit');

$resolve = function (string $path) use ($pluginPath): string {
    if (str_starts_with($path, '$/')) {
        return base_path('plugins/' . substr($path, 2));
    }

    return $path;
};

$controllers = glob($pluginPath . '/controllers/*/config_list.yaml') ?: [];
sort($controllers);

foreach ($controllers as $configFile) {
    $config = Yaml::parseFile($configFile);
    $name = basename(dirname($configFile));
    $modelClass = (string) ($config['modelClass'] ?? '');

    $check($modelClass !== '' && class_exists($modelClass), $name . ': modelClass не найден (' . $modelClass . ')');

    if (!class_exists($modelClass)) {
        continue;
    }

    $model = new $modelClass;
    $table = $model->getTable();
    $listFile = $resolve((string) ($config['list'] ?? ''));
    $check(is_file($listFile), $name . ': файл списка не найден (' . $listFile . ')');

    if (($config['toolbar']['buttons'] ?? '') === 'list_toolbar') {
        $toolbarFile = dirname($configFile) . '/_list_toolbar.htm';
        $check(is_file($toolbarFile), $name . ': кнопки list_toolbar, но нет _list_toolbar.htm');
    }

    if (!is_file($listFile)) {
        continue;
    }

    $definitions = Yaml::parseFile($listFile)['columns'] ?? [];

    foreach ($definitions as $columnName => $column) {
        $column = is_array($column) ? $column : [];
        $type = $column['type'] ?? 'text';
        $label = $name . '.' . $columnName;

        if ($type !== 'text') {
            $check(in_array($type, $types, true), $label . ': неизвестный тип колонки "' . $type . '"');
        }

        $relation = (string) ($column['relation'] ?? '');

        if ($relation !== '') {
            $check($model->hasRelation($relation), $label . ': у модели нет связи "' . $relation . '"');
        }

        $relationCount = (bool) ($column['relationCount'] ?? $column['useRelationCount'] ?? false);
        $valueFrom = (string) ($column['valueFrom'] ?? '');

        if ($relation === '' && !$relationCount && $valueFrom === '') {
            $accessor = 'get' . Str::studly($columnName) . 'Attribute';
            $check(
                Schema::hasColumn($table, $columnName) || method_exists($model, $accessor),
                $label . ': нет ни колонки в таблице ' . $table . ', ни аксессора ' . $accessor
            );
        }

        $select = (string) ($column['select'] ?? '');

        if ($select !== '' && $relation === '') {
            $check(
                Schema::hasColumn($table, $select),
                $label . ': select "' . $select . '" — не колонка БД, для аксессора нужен valueFrom'
            );
        }

        $valueFrom = (string) ($column['valueFrom'] ?? '');

        if ($valueFrom !== '' && $relation === '') {
            $accessor = 'get' . Str::studly($valueFrom) . 'Attribute';
            $check(
                method_exists($model, $accessor) || Schema::hasColumn($table, $valueFrom),
                $label . ': valueFrom "' . $valueFrom . '" — нет ни аксессора, ни колонки'
            );
        }
    }
}

$forms = glob($pluginPath . '/controllers/*/config_form.yaml') ?: [];
sort($forms);

foreach ($forms as $configFile) {
    $config = Yaml::parseFile($configFile);
    $name = basename(dirname($configFile));
    $formFile = $resolve((string) ($config['form'] ?? ''));
    $check(is_file($formFile), $name . ': файл формы не найден (' . $formFile . ')');
}

/**
 * Страница установки: `Install::TABLES` — то, что она проверяет и считает.
 * Новая миграция без строки в списке оставляет страницу зелёной на неполной
 * базе, поэтому список сверяется с `Schema::create` из updates/*.php.
 */
$created = [];

foreach (glob($pluginPath . '/updates/*.php') ?: [] as $migration) {
    $code = (string) file_get_contents($migration);

    if (preg_match_all("~Schema::create\\(\\s*'([a-z0-9_]+)'~i", $code, $matches)) {
        foreach ($matches[1] as $table) {
            if (str_starts_with($table, 'elv_kit_')) {
                $created[$table] = true;
            }
        }
    }
}

$installReflection = new ReflectionClass(\Elv\Kit\Controllers\Install::class);
$listed = array_keys((array) $installReflection->getConstant('TABLES'));

$notListed = array_diff(array_keys($created), $listed);
$notCreated = array_diff($listed, array_keys($created));

$check($notListed === [], 'Install::TABLES: нет таблиц ' . implode(', ', $notListed));
$check($notCreated === [], 'Install::TABLES: таблицы без миграции ' . implode(', ', $notCreated));

if ($errors !== []) {
    echo 'FAIL:' . PHP_EOL;

    foreach ($errors as $error) {
        echo ' - ' . $error . PHP_EOL;
    }

    exit(1);
}

echo 'OK: admin list/form configs are consistent' . PHP_EOL;
