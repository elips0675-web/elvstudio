<?php namespace Elv\Kit;

use Elv\Kit\Classes\Blog;
use Elv\Kit\Classes\Assets;
use Elv\Kit\Classes\AccountService;
use Elv\Kit\Classes\Badges;
use Elv\Kit\Classes\Calculator;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\DemoLinks;
use Elv\Kit\Classes\Faq;
use Elv\Kit\Classes\Icons;
use Elv\Kit\Classes\LicenseService;
use Elv\Kit\Classes\Menu;
use Elv\Kit\Classes\Money;
use Elv\Kit\Classes\CompressResponse;
use Elv\Kit\Classes\Prices;
use Elv\Kit\Classes\SecurityHeaders;
use Elv\Kit\Classes\Search;
use Elv\Kit\Classes\SearchConfig;
use Elv\Kit\Classes\Seo;
use Elv\Kit\Classes\Slots;
use Elv\Kit\Models\Post;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Settings;
use System\Classes\PluginBase;

/**
 * Plugin — точка входа Elv Kit.
 *
 * Плагин не подменяет CMS-компоненты: страницы и партиалы темы вызывают
 * markup-функции, поэтому HTML остаётся статическим и кэшируемым.
 */
class Plugin extends PluginBase
{
    public function pluginDetails()
    {
        return [
            'name' => 'Elv Kit',
            'description' => 'Готовый сайт для бизнеса: онлайн-запись, заказы и оплата, блог и обратная связь.',
            'author' => 'Elv Studio',
            'icon' => 'icon-cubes',
        ];
    }

    public function register()
    {
        $this->registerConsoleCommand('elv.kit.demo', \Elv\Kit\Console\DemoCommand::class);
        $this->registerConsoleCommand('elv.kit.reset', \Elv\Kit\Console\ResetCommand::class);
        $this->registerConsoleCommand('elv.kit.package', \Elv\Kit\Console\PackageCommand::class);
        $this->registerConsoleCommand('elv.kit.remind', \Elv\Kit\Console\RemindCommand::class);
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Elv Kit',
                'description' => 'Магазин, платежи, лицензии, почта и SEO.',
                'category' => 'Elv Kit',
                'icon' => 'icon-cogs',
                'class' => Settings::class,
                'order' => 500,
                'keywords' => 'elv kit shop payments seo',
                'permissions' => ['elv.kit.settings'],
            ],
        ];
    }

    public function registerPermissions()
    {
        return [
            'elv.kit.settings' => [
                'label' => 'Настройки Elv Kit',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.install' => [
                'label' => 'Установка Elv Kit',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.orders' => [
                'label' => 'Заказы',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.bookings' => [
                'label' => 'Брони',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.messages' => [
                'label' => 'Сообщения обратной связи',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.subscribers' => [
                'label' => 'Подписчики',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.catalog' => [
                'label' => 'Каталог: товары и категории',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.blog' => [
                'label' => 'Блог',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.licenses' => [
                'label' => 'Лицензии',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.prices' => [
                'label' => 'Прайс: версии цен',
                'tab' => 'Elv Kit',
            ],
            'elv.kit.releases' => [
                'label' => 'Релизы архива',
                'tab' => 'Elv Kit',
            ],
        ];
    }

    public function registerNavigation()
    {
        return [
            'kit' => [
                'label' => 'Elv Kit',
                'url' => \Backend::url('elv/kit/orders'),
                'icon' => 'icon-cubes',
                'order' => 410,
                'permissions' => ['elv.kit.orders'],
                'sideMenu' => [
                    'install' => [
                        'label' => 'Установка',
                        'icon' => 'icon-magic',
                        'url' => \Backend::url('elv/kit/install'),
                        'permissions' => ['elv.kit.install'],
                    ],
                    'orders' => [
                        'label' => 'Заказы',
                        'icon' => 'icon-shopping-cart',
                        'url' => \Backend::url('elv/kit/orders'),
                        'permissions' => ['elv.kit.orders'],
                    ],
                    'bookings' => [
                        'label' => 'Брони',
                        'icon' => 'icon-calendar-check-o',
                        'url' => \Backend::url('elv/kit/bookings'),
                        'permissions' => ['elv.kit.bookings'],
                    ],
                    'slots' => [
                        'label' => 'Слоты записи',
                        'icon' => 'icon-clock-o',
                        'url' => \Backend::url('elv/kit/slots'),
                        'permissions' => ['elv.kit.bookings'],
                    ],
                    'messages' => [
                        'label' => 'Сообщения',
                        'icon' => 'icon-envelope',
                        'url' => \Backend::url('elv/kit/messages'),
                        'permissions' => ['elv.kit.messages'],
                    ],
                    'subscribers' => [
                        'label' => 'Подписчики',
                        'icon' => 'icon-users',
                        'url' => \Backend::url('elv/kit/subscribers'),
                        'permissions' => ['elv.kit.subscribers'],
                    ],
                    'products' => [
                        'label' => 'Товары',
                        'icon' => 'icon-cube',
                        'url' => \Backend::url('elv/kit/products'),
                        'permissions' => ['elv.kit.catalog'],
                    ],
                    'categories' => [
                        'label' => 'Категории блога',
                        'icon' => 'icon-tags',
                        'url' => \Backend::url('elv/kit/categories'),
                        'permissions' => ['elv.kit.catalog'],
                    ],
                    'posts' => [
                        'label' => 'Статьи',
                        'icon' => 'icon-pencil',
                        'url' => \Backend::url('elv/kit/posts'),
                        'permissions' => ['elv.kit.blog'],
                    ],
                    'licenses' => [
                        'label' => 'Лицензии',
                        'icon' => 'icon-key',
                        'url' => \Backend::url('elv/kit/licenses'),
                        'permissions' => ['elv.kit.licenses'],
                    ],
                    'prices' => [
                        'label' => 'Прайс',
                        'icon' => 'icon-money',
                        'url' => \Backend::url('elv/kit/prices'),
                        'permissions' => ['elv.kit.prices'],
                    ],
                    'releases' => [
                        'label' => 'Релизы',
                        'icon' => 'icon-archive',
                        'url' => \Backend::url('elv/kit/releases'),
                        'permissions' => ['elv.kit.releases'],
                    ],
                ],
            ],
        ];
    }

    public function registerSchedule($schedule)
    {
        $schedule->command('elv:kit:demo --refresh-slots')->dailyAt('03:20')->withoutOverlapping();
        $schedule->command('elv:kit:remind')->hourly()->withoutOverlapping();
        $schedule->command('queue:work --stop-when-empty --max-time=50')->everyMinute()->withoutOverlapping();
    }

    public function boot()
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        $this->app
            ->make(\Illuminate\Contracts\Http\Kernel::class)
            ->pushMiddleware(SecurityHeaders::class)
            ->pushMiddleware(CompressResponse::class);

        \Event::listen('cms.page.beforeDisplay', function ($controller, $url, $page) {
            $raw = (string) (parse_url((string) request()->getRequestUri(), PHP_URL_PATH) ?: '');

            if ($raw === '' || $raw === '/' || str_ends_with($raw, '/') || request()->ajax() || !request()->isMethod('get')) {
                return null;
            }

            $query = (string) request()->getQueryString();

            return redirect()->to(url(ltrim($raw, '/')) . '/' . ($query !== '' ? '?' . $query : ''), 301);
        });
    }

    public function registerMarkupTags()
    {
        $account = new AccountService();

        return [
            'functions' => [
                'elv_setting' => function ($key, $default = null) {
                    return Settings::get((string) $key, $default);
                },
                'elv_money' => function ($cents, $currency = null) {
                    return Money::format((int) $cents, $currency);
                },
                'elv_price' => function ($product, $plan = 'single') {
                    return $product instanceof Product
                        ? Prices::resolve($product, (string) $plan)
                        : 0;
                },
                'elv_currency_symbol' => function () {
                    return Money::symbol();
                },
                'elv_base' => function () {
                    return Seo::base();
                },
                'elv_kit' => function () {
                    return Catalog::kit();
                },
                'elv_modules' => function () {
                    return Catalog::modules();
                },
                'elv_product' => function ($slug) {
                    $product = Catalog::find((string) $slug);
                    Seo::setProduct($product);

                    return $product;
                },
                'elv_plans' => function ($product = null) {
                    return Catalog::plans($product instanceof Product ? $product : null);
                },
                'elv_plan_keys' => function () {
                    return Catalog::planKeys();
                },
                'elv_plan_label' => function ($key) {
                    return Catalog::planLabel((string) $key);
                },
                'elv_order_url' => function (array $params = []) {
                    $query = array_filter($params, function ($value) {
                        return $value !== null && $value !== '';
                    });

                    return url('order') . '/' . ($query ? '?' . http_build_query($query) : '');
                },
                'elv_menu_products' => function () {
                    return Menu::products();
                },
                'elv_menu_products_label' => function () {
                    return Menu::label();
                },
                'elv_menu_products_current' => function () {
                    return Menu::hasCurrent();
                },
                'elv_demo_links' => function () {
                    return DemoLinks::items();
                },
                'elv_calculator' => function () {
                    return Calculator::data();
                },
                'elv_prices_updated_at' => function () {
                    return Prices::label();
                },
                'elv_calculator_estimate' => function ($plan, $modules = []) {
                    return Calculator::estimate((string) $plan, $modules);
                },
                'elv_calculator_quote' => function ($plan, $modules = [], $task = 'landing', $hosting = 'own') {
                    return Calculator::quote((string) $plan, $modules, (string) $task, (string) $hosting);
                },
                'elv_calculator_faq' => function () {
                    return Faq::items('calculator');
                },
                'elv_service_faq' => function () {
                    return Faq::items('services');
                },
                'elv_seo_faq' => function () {
                    return Faq::items('seo');
                },
                'elv_post_faq' => function ($slug) {
                    return Faq::post((string) $slug);
                },
                'elv_compare' => function () {
                    return Catalog::comparison();
                },
                'elv_includes' => function () {
                    return Catalog::includes();
                },
                'elv_excludes' => function () {
                    return Catalog::excludes();
                },
                'elv_anatomy' => function () {
                    return Catalog::anatomy();
                },
                'elv_scenarios' => function () {
                    return Catalog::scenarios();
                },
                'elv_changelog' => function () {
                    return Catalog::changelog();
                },
                'elv_posts' => function ($limit = 6, $category = null, $audience = Post::AUDIENCE_BUSINESS) {
                    $query = Post::query()
                        ->published()
                        ->audience((string) $audience)
                        ->with('category');

                    if ($category) {
                        $query->whereHas('category', function ($q) use ($category) {
                            $q->where('slug', (string) $category);
                        });
                    }

                    return $query->limit((int) $limit)->get();
                },
                'elv_post' => function ($slug) {
                    return Blog::find((string) $slug);
                },
                'elv_categories' => function ($audience = Post::AUDIENCE_BUSINESS) {
                    return Blog::categories((string) $audience);
                },
                'elv_tags' => function ($audience = Post::AUDIENCE_BUSINESS) {
                    return Blog::tags((string) $audience);
                },
                'elv_faq' => function () {
                    return Faq::items();
                },
                'elv_faq_home' => function () {
                    return Faq::items('faq', Faq::HOME_LIMIT);
                },
                'elv_badge' => function ($key) {
                    return Badges::label((string) $key);
                },
                'elv_icon' => function ($name, $class = '') {
                    return Icons::svg((string) $name, (string) $class);
                },
                'elv_canonical' => function ($pageNo = 1) {
                    return Seo::canonical((int) $pageNo);
                },
                'elv_page_no' => function () {
                    return max(1, (int) request()->query('page', 1));
                },
                'elv_404_if' => function ($condition) {
                    if ($condition) {
                        abort(404);
                    }

                    return '';
                },
                'elv_jsonld' => function ($page = null) {
                    return Seo::jsonld($page);
                },
                'elv_seo_title' => function ($page = null, $pageNo = 1) {
                    return Seo::title($page, (int) $pageNo);
                },
                'elv_seo_description' => function ($page = null) {
                    return Seo::description($page);
                },
                'elv_og_image' => function ($page = null) {
                    return Seo::image($page);
                },
                'elv_csp_nonce' => function () {
                    return SecurityHeaders::nonce();
                },
                'elv_csrf' => function () {
                    return csrf_field();
                },
                'elv_csrf_token' => function () {
                    return csrf_token();
                },
                'elv_license_stats' => function ($product = '') {
                    return (new LicenseService())->stats((string) $product);
                },
                'elv_slots' => function ($serviceCode = '', $days = 14) {
                    return Slots::days((string) $serviceCode, (int) $days);
                },
                'elv_booking_services' => function () {
                    return Slots::services();
                },
                'elv_year' => function () {
                    return date('Y');
                },
                'elv_css' => function ($relative) {
                    return Assets::css((string) $relative);
                },
                'elv_js' => function ($relative) {
                    return Assets::js((string) $relative);
                },
                'elv_widget' => function () {
                    return view('elv.kit::widget')->render();
                },
                'elv_kit_css' => function () {
                    return Assets::pluginCss('assets/widget.css');
                },
                'elv_kit_js' => function () {
                    return Assets::pluginJs('assets/widget.js');
                },
                'elv_blog' => function ($perPage = 9, $audience = Post::AUDIENCE_BUSINESS) {
                    return Blog::paginate([
                        'category' => request()->query('category'),
                        'tag' => request()->query('tag'),
                        'q' => request()->query('q'),
                    ], (int) $perPage, (string) $audience);
                },
                'elv_blog_start' => function ($limit = 0) {
                    return Blog::start((int) $limit);
                },
                'elv_blog_category' => function ($slug, $limit = 5) {
                    return Blog::byCategory((string) $slug, (int) $limit);
                },
                'elv_blog_total' => function ($audience = Post::AUDIENCE_BUSINESS) {
                    return Blog::total((string) $audience);
                },
                'elv_use_post' => function ($slug) {
                    $post = Blog::find((string) $slug);
                    Seo::setPost($post);

                    return $post;
                },
                'elv_order' => function ($number) {
                    return \Elv\Kit\Models\Order::query()
                        ->where('order_no', (string) $number)
                        ->first();
                },
                'elv_flash' => function ($key) {
                    return session()->get((string) $key);
                },
                'elv_errors' => function () {
                    return (array) session()->get('elv_errors', []);
                },
                'elv_old' => function ($key, $default = '') {
                    return old((string) $key, $default);
                },
                'elv_cover_icon' => function ($cover) {
                    return Icons::svg((string) ($cover ?: 'file'));
                },
                'elv_not_found' => function () {
                    abort(404);
                },
                'elv_query' => function ($key, $default = null) {
                    $value = request()->query((string) $key, $default);

                    return is_scalar($value) ? (string) $value : $default;
                },
                'elv_search_index' => function () {
                    return Search::index();
                },
                'elv_search_json' => function ($items = null) {
                    return Search::json(is_array($items) ? $items : null);
                },
                'elv_search_config' => function () {
                    return SearchConfig::json();
                },
                'elv_search' => function ($query, $type = Search::TYPE_ALL, $limit = 20) {
                    return Search::query((string) $query, (string) $type, (int) $limit);
                },
                'elv_breadcrumbs' => function () {
                    return Seo::breadcrumbs();
                },
                'elv_backend_url' => function ($path = '') {
                    return \Backend::url((string) $path);
                },
                'elv_related' => function ($post, $limit = 3) {
                    return $post instanceof Post ? Blog::related($post, (int) $limit) : collect();
                },
                'elv_account' => function () use ($account) {
                    return $account->user();
                },
                'elv_account_orders' => function () use ($account) {
                    $customer = $account->user();

                    return $customer ? $account->orders($customer) : collect();
                },
                'elv_account_licenses' => function () use ($account) {
                    $customer = $account->user();

                    return $customer ? $account->licenses($customer) : collect();
                },
                'elv_account_domains' => function () use ($account) {
                    $customer = $account->user();

                    return $customer ? $account->installs($customer) : collect();
                },
                'elv_account_can_unbind' => function ($install) use ($account) {
                    $customer = $account->user();

                    if (!$customer || !$install instanceof \Elv\Kit\Models\Install) {
                        return ['ok' => false, 'message' => ''];
                    }

                    return $account->canUnbind($customer, $install);
                },
                'elv_account_next_unbind' => function ($license) use ($account) {
                    return $license instanceof \Elv\Kit\Models\License
                        ? $account->nextUnbindAt($license)
                        : null;
                },
            ],
            'filters' => [
                'elv_money' => function ($cents, $currency = null) {
                    return Money::format((int) $cents, $currency);
                },
                'elv_excerpt' => function ($value, $limit = 160) {
                    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $value)) ?? '');

                    return mb_strlen($text) > $limit
                        ? mb_substr($text, 0, $limit - 1) . '…'
                        : $text;
                },
                'elv_text' => function ($value) {
                    return nl2br(e((string) $value), false);
                },
            ],
        ];
    }
}
