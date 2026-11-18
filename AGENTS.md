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
| `plugins/elv/kit/classes/` | `Catalog`, `Demo`, `BlogContent`, `Seo`, `Sitemap`, `Rss`, `DownloadService`, `LicenseService`, `OrderService`, `BookingService`, `Slots`, `Chat`, `AntiSpam`, `Http/*` |
| `plugins/elv/kit/models/` | `Product`, `Post`, `Order`, `Booking`, `Slot`, `Message`, `Subscriber`, `License`, `Install`, `Category`, `Settings` |
| `plugins/elv/kit/controllers/` | админка: `posts`, `products`, `categories`, `orders`, `bookings`, `messages`, `subscribers`, `licenses`, `install` |
| `plugins/elv/kit/console/` | `elv:kit:demo`, `elv:kit:reset`, `elv:kit:package` |
| `plugins/elv/kit/updates/` | `version.yaml` (единственный источник версии плагина) + миграции и сиды |
| `plugins/elv/kit/routes.php` | формы, оплата, `/download/{token}`, `/api/v1/license/*`, sitemap/robots/llms/rss |
| `tools/` | гейты `check-*.mjs`, `production-check.php`, `export-static.php`, `blog-content-audit.php` |
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
