<?php namespace Elv\Kit\Controllers;

use Backend\Classes\Controller;
use BackendMenu;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Install — установка без терминала: проверка требований, миграции и демо-данные
 * запускаются кнопками из админки.
 */
class Install extends Controller
{
    public $requiredPermissions = ['elv.kit.install'];

    /**
     * Таблицы плагина: имя → подпись на странице установки.
     *
     * Один список и для проверки «таблицы созданы», и для счётчиков: раньше
     * подписи жили в партиале, и новая миграция оставляла страницу зелёной
     * при неполной базе. Сверка с миграциями — `tools/admin-check.php`.
     */
    protected const TABLES = [
        'elv_kit_categories' => 'Категории статей',
        'elv_kit_products' => 'Товары и разделы',
        'elv_kit_posts' => 'Статьи блога',
        'elv_kit_orders' => 'Заказы',
        'elv_kit_bookings' => 'Записи',
        'elv_kit_slots' => 'Свободное время',
        'elv_kit_messages' => 'Сообщения',
        'elv_kit_subscribers' => 'Подписчики',
        'elv_kit_licenses' => 'Ключи',
        'elv_kit_installs' => 'Активации',
        'elv_kit_customers' => 'Клиенты кабинета',
        'elv_kit_price_versions' => 'Версии цен',
        'elv_kit_releases' => 'Сборки архива',
        'elv_kit_questions' => 'Вопросы покупателей',
        'elv_kit_reviews' => 'Отзывы',
        'elv_kit_tickets' => 'Обращения',
        'elv_kit_ticket_messages' => 'Сообщения обращений',
    ];

    protected const EXTENSIONS = [
        'pdo_mysql' => 'Драйвер MySQL',
        'mbstring' => 'Кодировки UTF-8',
        'json' => 'JSON и настройки',
        'curl' => 'Запросы к платёжным шлюзам',
        'zip' => 'Сборка архива поставки',
        'gd' => 'Обложки и превью',
        'openssl' => 'HTTPS и подписи',
    ];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'install');
    }

    public function index()
    {
        $this->pageTitle = 'Установка Elv Kit';
        $this->vars['checks'] = $this->checks();
        $this->vars['counts'] = $this->counts();
        $this->vars['tableLabels'] = self::TABLES;
        $this->vars['cron'] = $this->cronLine();
        $this->vars['output'] = '';
    }

    public function onCheck()
    {
        return $this->refresh();
    }

    public function onMigrate()
    {
        $this->vars['output'] = $this->runArtisan('october:migrate', ['--force' => true, '--no-ansi' => true]);

        return $this->refresh();
    }

    public function onDemo()
    {
        $this->vars['output'] = $this->runArtisan('elv:kit:demo', ['--no-ansi' => true]);

        return $this->refresh();
    }

    public function onQueue()
    {
        $this->vars['output'] = $this->runArtisan('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => 50,
            '--no-ansi' => true,
        ]);

        return $this->refresh();
    }

    public function onSlots()
    {
        $this->vars['output'] = $this->runArtisan('elv:kit:demo', [
            '--refresh-slots' => true,
            '--no-ansi' => true,
        ]);

        return $this->refresh();
    }

    public function onClearCache()
    {
        $this->vars['output'] = $this->runArtisan('cache:clear', ['--no-ansi' => true]);

        return $this->refresh();
    }

    protected function refresh(): array
    {
        $this->vars['checks'] = $this->checks();
        $this->vars['counts'] = $this->counts();
        $this->vars['tableLabels'] = self::TABLES;
        $this->vars['cron'] = $this->cronLine();

        return [
            '#elv-install-checks' => $this->makePartial('checks'),
            '#elv-install-counts' => $this->makePartial('counts'),
            '#elv-install-output' => $this->makePartial('output'),
        ];
    }

    protected function runArtisan(string $command, array $parameters = []): string
    {
        try {
            // Свой буфер: сохранение настроек внутри команды вызывает
            // queue:restart, и Artisan::output() вернул бы только его строку.
            $buffer = new BufferedOutput();
            Artisan::call($command, $parameters, $buffer);
            $output = trim($buffer->fetch());

            return $output !== '' ? $output : 'Команда выполнена, сообщений нет.';
        } catch (\Throwable $exception) {
            return 'Ошибка: ' . $exception->getMessage();
        }
    }

    /**
     * checks — требования, которые видно до запуска миграций.
     *
     * @return array<int, array{label: string, ok: bool, hint: string}>
     */
    protected function checks(): array
    {
        $checks = [];

        $checks[] = [
            'label' => 'PHP 8.3 или новее',
            'ok' => version_compare(PHP_VERSION, '8.3.0', '>='),
            'hint' => 'Сейчас ' . PHP_VERSION . '. Версия меняется в панели хостинга.',
        ];

        foreach (self::EXTENSIONS as $extension => $purpose) {
            $checks[] = [
                'label' => 'Расширение ' . $extension,
                'ok' => extension_loaded($extension),
                'hint' => $purpose,
            ];
        }

        $checks[] = [
            'label' => 'Каталог storage доступен на запись',
            'ok' => is_writable(storage_path()),
            'hint' => 'Кэш, сессии, письма и загруженные файлы.',
        ];

        $checks[] = [
            'label' => 'Каталог bootstrap/cache доступен на запись',
            'ok' => is_writable(base_path('bootstrap/cache')),
            'hint' => 'Скомпилированные настройки и маршруты.',
        ];

        $database = $this->databaseStatus();
        $checks[] = $database;
        $checks[] = $this->tablesStatus();

        $checks[] = [
            'label' => 'Тема elv-studio активна',
            'ok' => \Cms\Classes\Theme::getActiveThemeCode() === 'elv-studio',
            'hint' => 'Front-end → Темы → Elv Studio → Активировать.',
        ];

        return $checks;
    }

    protected function databaseStatus(): array
    {
        try {
            DB::connection()->getPdo();

            return [
                'label' => 'Подключение к базе данных',
                'ok' => true,
                'hint' => 'База отвечает, доступ есть.',
            ];
        } catch (\Throwable $exception) {
            return [
                'label' => 'Подключение к базе данных',
                'ok' => false,
                'hint' => 'Проверьте базу и доступы в файле .env: ' . $exception->getMessage(),
            ];
        }
    }

    protected function tablesStatus(): array
    {
        try {
            $missing = [];

            foreach (array_keys(self::TABLES) as $table) {
                if (!Schema::hasTable($table)) {
                    $missing[] = $table;
                }
            }

            return [
                'label' => 'Таблицы Elv Kit созданы',
                'ok' => $missing === [],
                'hint' => $missing === []
                    ? 'Все ' . count(self::TABLES) . ' таблиц на месте.'
                    : 'Не хватает: ' . implode(', ', $missing) . '. Нажмите «Применить миграции».',
            ];
        } catch (\Throwable $exception) {
            return [
                'label' => 'Таблицы Elv Kit созданы',
                'ok' => false,
                'hint' => 'Не удалось проверить: ' . $exception->getMessage(),
            ];
        }
    }

    /**
     * counts — сколько записей уже есть в каждой таблице.
     *
     * @return array<string, int|null>
     */
    protected function counts(): array
    {
        $counts = [];

        foreach (array_keys(self::TABLES) as $table) {
            try {
                $counts[$table] = Schema::hasTable($table) ? (int) DB::table($table)->count() : null;
            } catch (\Throwable $exception) {
                $counts[$table] = null;
            }
        }

        return $counts;
    }

    /**
     * cronLine — готовая строка задания планировщика для панели хостинга.
     */
    protected function cronLine(): string
    {
        $php = PHP_BINARY !== '' ? PHP_BINARY : 'php';

        return '* * * * * cd ' . base_path() . ' && ' . $php . ' artisan schedule:run >> /dev/null 2>&1';
    }
}
