# AGENTS.md — витрина продаж Elv Kit

Этот каталог продаёт продукт и демонстрирует его. Это **не** шаблон библиотеки:
продукт-шаблон лежит в `D:\October CMS`, его правила — `D:\October CMS\AGENTS.md`.
Стенд приёмки — `D:\Elv test`.

Репозитории: `D:\October CMS` → https://github.com/elips0675-web/OctoberCMS,
`D:\Elv Landing` → https://github.com/elips0675-web/elvstudio,
`D:\Elv test` → https://github.com/elips0675-web/Elvtest.

## Роль

`D:\Elv Landing` — сайт продаж (тема `themes/elv-studio`) и сам продукт Elv Kit
(плагин `plugins/elv/kit`, namespace `Elv\Kit`): онлайн-запись, заказы и оплата,
обратная связь, блог, подписка, лицензии, выдача ZIP после оплаты.

## Запуск

- Сайт: `php artisan serve --port=8090` или `запуск.bat`.
- БД: `elv_studio` (MySQL Laragon `127.0.0.1:3306`, `root`, пароль пустой).
- PHP не в PATH: `C:\laragon\bin\php\php-8.3.30-Win32-vs16-x64\php.exe`.

## Структура

| Путь | Что внутри |
|---|---|
| `themes/elv-studio/` | лендинг: `layouts/`, `pages/` (index, kit, modules, module, pricing, demo, blog, blog-tech, blog-post, docs, order, order-success, booking, feedback, contacts, terms, privacy, sitemap, 404), `partials/`, `assets/`, `content/` |
| `plugins/elv/kit/classes/` | `Catalog`, `Demo`, `Seo`, `Sitemap`, `Rss`, `DownloadService`, `LicenseService`, `OrderService`, `BookingService`, `Slots`, `Chat`, `AntiSpam`, `Http/*` |
| `plugins/elv/kit/demo/` | `blog.json` — демо-статьи блога (38 базовых + 54 переопределения + 25 блоков перелинковки); в товарный архив не входит |
| `plugins/elv/kit/models/` | `Product`, `Post`, `Order`, `Booking`, `Slot`, `Message`, `Subscriber`, `License`, `Install`, `Category`, `Settings` |
| `plugins/elv/kit/controllers/` | админка: `posts`, `products`, `categories`, `orders`, `bookings`, `messages`, `subscribers`, `licenses`, `install` |
| `plugins/elv/kit/console/` | `elv:kit:demo`, `elv:kit:reset`, `elv:kit:package` |
| `plugins/elv/kit/updates/` | `version.yaml` (единственный источник версии плагина) + миграции и сиды |
| `plugins/elv/kit/routes.php` | формы, оплата, `/download/{token}`, `/api/v1/license/*`, sitemap/robots/llms/rss |
| `tools/` | гейты `check-*.mjs`, `production-check.php`, `export-static.php`, `blog-content-audit.php`, `build-test-snapshot.mjs` (снимок `test/ai-chat`) |
| `docs/` | `00-audit-documents.md` (аудит источников и решений), `html/` (42 исходных документа), `blog-audit.md`, `blog-content-plan.md`, `content-policy.md`, `prices.md`, `search.md`, `stages-01-11.md`, `studio-features-roadmap.md`, `qa/` |
| `demo-static/` | статический экспорт демо для Vercel |
| `exports/` | выгрузки статей блога (данные, не для импорта в October CMS) |

## Как устроена продажа

1. `php artisan elv:kit:package` собирает **товарный** ZIP без демо-данных в
   `storage/app/kit/elv-kit-<версия>.zip`, `--demo` — вариант для лендинга
   (`…-demo.zip`, с `updates/seed_kit_*.php`). Состав: `elv-kit/theme`,
   `elv-kit/plugin`, README, LICENSE (`classes/DownloadService.php:38-41`).
2. Заказ и оплата (ЮKassa/Stripe) → выдача по токену `/download/{token}`
   (`routes.php:34`); архив отдаётся только оплаченному заказу
   (`classes/DownloadService.php:79-86`).
3. Активация лицензии — `POST /api/v1/license/activate` (`routes.php:51`),
   домены хранятся хэшем, лимит по тарифу.
4. Установка у покупателя без терминала — страница админки
   `plugins/elv/kit/controllers/install` (`index.htm:14-36`): «Применить миграции»,
   «Загрузить демо-данные», очередь, кэш. Файлы при этом копируются через файловый
   менеджер хостинга (`index.htm:5-6`).

## Правила

1. Статьи блога отсюда **не переносятся** в `D:\October CMS` — там свой блог.
2. SEO-наработки идут в одну сторону: отсюда → `Elv\Library`. Обратно не тащить.
3. October не ставит плагин из ZIP через админку: ZIP распаковывается в файловом
   менеджере, дальше — страница «Установка».
4. Версия продукта живёт в одном месте — `plugins/elv/kit/updates/version.yaml`;
   `DownloadService::version()` (`classes/DownloadService.php:15-38`) читает
   старшую версию из файла, имя ZIP и README собираются из неё. Захардкоженная
   константа версии — дефект: проверка `node tools/check-package.mjs` (входит в
   `node tools/gate.mjs`) падает на расхождении и на возврате константы.
   Тема версионируется отдельно — `themes/elv-studio/version.yaml`.
5. Секреты только в `.env`; в репозитории — `.env.example`.
6. Витрина обязана работать на пустой базе: тарифов нет — `/pricing/` пишет
   «Прайс пока пуст» и не печатает дату цен, `/order/` объясняет, почему заказ
   закрыт, а `?product=<несуществующий>` отдаёт 404. Выдуманные цены и даты —
   дефект, а не заглушка. Проверка:
   `node tools/check-empty-install.mjs --base=http://127.0.0.1:<порт>`
   (в `gate.mjs` не входит — её запускают против чистой установки).
7. Тарифы: ключи `single`/`multi`/`extended` неизменны (на них ссылаются заказы,
   лицензии и цены), а подписи и число продаваемых тарифов — из настроек
   (`Catalog::planLabels()`, `Catalog::planKeys()`, вкладка «Продажи»). Подпись
   в шаблоне строкой — дефект: `elv_plan_label()`, `plans`, `elv_plan_keys()`.
   Выключенный тариф не показывается и не принимается в заказе; значения
   сравнения привязаны к ключу, а не к порядку колонок. Подпись в тексте
   консультанта, калькулятора и рекомендаций — тот же дефект, что в шаблоне:
   `Chat` и `Calculator` берут `Catalog::planLabels()`/`plans()`, лимит доменов
   считается только в `Catalog::planDomainLimit()`. Проверка:
   `node tools/check-plans.mjs` (входит в `node tools/gate.mjs`); поиск подписи
   идёт подстрокой по теме и коду плагина.
8. В файлах, которые уезжают покупателю (`plugins/elv/kit`, `themes/elv-studio`),
   нет нашего домена и контактов: `elv-studio.by`, `sales@elv-studio`,
   `t.me/elvstudio`. Контакты — только из Settings (`email`, `telegram`), валюта —
   `Money::currency()`, дефолт почты — `sales@example.com`. Проверка:
   `node tools/check-brand.mjs` (входит в `node tools/gate.mjs`); `demo-static`
   не сканируется — это наш собственный сайт.
9. Один адрес на страницу: каноническая форма — со слэшем. `/blog` уводится 301
   на `/blog/` (`Plugin.php`, `cms.page.beforeDisplay`), у страниц пагинации свой
   canonical `?page=N` и свой `<title>` «— страница N» (`Seo::canonical()`,
   `Seo::title()`), страница за последней — 404 (`pages/blog.htm`), фильтры
   `?category/?tag/?q` — `noindex` и canonical на общий список
   (`layouts/default.htm`). Ссылки October генерирует без слэша, поэтому первый
   переход по ним получает 301 (он кэшируется). Проверка:
   `node tools/check-blog.mjs` (входит в `tools/gate.mjs`). Метрика:
   `node tools/check-perf.mjs` — Lighthouse mobile, медиана Performance 100,
   худший прогон не ниже 95; стенд перед замером прогрет (`tools/warmup.ps1`
   вызывается из `запуск.bat`), иначе первый прогон платит за компиляцию Twig.
10. Встроенный сервер стартует с OPcache: `запуск.bat:108-116` вызывает
    `php -d zend_extension=…\ext\php_opcache.dll -d opcache.enable=1
    -d opcache.enable_cli=1 -d opcache.validate_timestamps=1
    -d opcache.revalidate_freq=0 -S %HOST%:%PORT% server.php` — та же команда,
    которой Laravel поднимает сервер (`ServeCommand.php:209-221`), плюс кэш
    опкодов. Без него TTFB скачет 383–930 мс и Lighthouse даёт 99 при TBT 0;
    с ним медиана 115 мс и Performance 100. `validate_timestamps=1` обязателен,
    иначе правки шаблонов не видны. После правки `.env` стенд перезапускается
    (раньше это делал `artisan serve`). Проверка:
    `node tools/check-perf.mjs --runs=5`; быстрая часть (p90 ответа ≤ 300 мс)
    входит в `node tools/gate.mjs` как `check-perf.mjs --ttfb-only`.
11. Консультант не продаёт чужое: услуги и ссылка на них — из настроек
    (`chat_services`, `chat_services_url`, вкладка «Виджет»). Пустой список —
    нейтральный ответ про форму обратной связи, без названия студии и без
    ссылки; про аудит консультант говорит, только если аудит есть в списке
    услуг (`Chat.php`, `servicesReply()`). Демо-значения ставит
    `elv:kit:demo` (`Demo.php`, `contacts()`), на чистой установке их нет.
    Зашитый список услуг в коде — дефект. Ссылки чата канонические, со слэшем:
    `Chat::link()` возвращает его после `url()`, иначе каждая ссылка даёт 301.
    Проверка: `node tools/check-php.mjs` (входит в `node tools/gate.mjs`).
12. FAQ — только из настроек (вкладка «FAQ»): `faq_items` (общий блок),
    `faq_calculator_items`, `faq_services_items`, `faq_seo_items` в формате
    «Вопрос | Ответ» по строке и `faq_post_items` — «адрес статьи | вопрос |
    ответ». `config/*.php` — демо-датасет для `elv:kit:demo`, в рантайме не
    читается (`Faq::configItems()` только для сида). Пустые настройки — секции
    FAQ не рендерятся (`{% if faqItems|length %}` в `index.htm`, `pricing.htm`,
    `calculator.htm`, `services.htm`, `services-seo.htm`), `FAQPage` в JSON-LD
    не добавляется. Наш текст в `Faq::items()` как фолбэк — дефект. Проверка:
    `tools/seo-check.php` в `node tools/check-php.mjs`.
13. Услуги записи — из настроек (`booking_services`, вкладка «Запись»): формат
    `код | название | минут | цена | описание | иконка`, длительность 5–480,
    код латиницей. `config/booking.php` — только демо-датасет для
    `elv:kit:demo`. Пустое поле → `/booking/` показывает «Запись временно
    недоступна», чат не перечисляет услуги; чужие цены («120 BYN») в коде
    плагина — дефект. Проверка: `tools/booking-check.php` в
    `node tools/check-php.mjs`.
14. Статьи блога — демо-датасет, а не код: тексты лежат в
    `plugins/elv/kit/demo/blog.json` (`seed`, `overrides`, `extras`),
    `Demo::posts()` только собирает их по `slug`. Файл входит в архив
    `--demo` и исключается из товарного (`DownloadService::addDirectory()`),
    поэтому у покупателя блог пуст до его собственных статей, а
    `elv:kit:demo` в товарном архиве честно предупреждает об этом
    (`Demo::hasPostsDataset()`). Динамика в текстах — только плейсхолдер
    `{{currency}}`, который подставляется из `Money::currency()` при загрузке.
    Зашитый текст статьи в PHP-классе — дефект. Проверка:
    `node tools/check-package.mjs` (товарный архив без `demo/blog.json`,
    демо-архив с ним, `Demo::posts()` совпадает с числом уникальных slug).
15. Пункт «Продукты» в шапке — из настроек (вкладка «Меню»):
    `menu_products_label` (по умолчанию «Продукты») и `menu_products` —
    «Название | адрес | подпись» по строке. Пустые настройки дают «Elv Kit» и
    «Шаблон» (`Menu::fallback()`), поэтому шапка работает на чистой установке.
    Демо-значения ставит `elv:kit:demo` (`Demo::menu()`). Зашитый список
    разработок в `header.htm` — дефект.
16. Живой предпросмотр — `/preview/:slug?` (`layouts/preview.htm`,
    `partials/preview.htm`): без шапки и подвала, `noindex, nofollow`,
    `no-store`, вне sitemap и `llms.txt`. Фрейм разрешён только своему
    источнику: `frame-ancestors 'self'`, `X-Frame-Options: SAMEORIGIN`,
    `frame-src 'self' https:`. Список демо на `/demo/` — продукты с
    `demo_url` плюс `demo_links` из настроек (вкладка «Продажи», формат
    «Название | адрес демо | адрес страницы | подпись»); зашитый список
    демо в шаблоне — дефект. Проверка: `node tools/check-headers.mjs`
    (входит в `node tools/gate.mjs`), `tools/export-static.php` собирает
    `/preview/` и `/preview/<slug>/`.
17. Ссылки на заказ — `elv_order_url({ product: …, plan: … })`
    (`Plugin.php`), а не `'order'|page({ … })`: фильтр `|page` подставляет
    параметры только в плейсхолдеры адреса (`/blog/:slug`), а у `/order/`
    их нет — `?product=` и `?plan=` теряются, и все кнопки ведут на заказ
    с тарифом по умолчанию. Бесплатный раздел (`price_cents = 0`) отдельно
    не продаётся: `/order/?product=<slug>` показывает «входит в Elv Kit»,
    а не форму с нулевой суммой.
