# Elv Studio — лендинг и модули для October CMS

Сайт студии и витрина продукта **Elv Kit**: тема `elv-studio`, плагин `Elv.Kit` и демо-данные
для October CMS 4.x. Лендинг продаёт «комбайн» (тема + модули + демо) и отдельные модули:
Booking, Shop, Feedback, Blog.

Контур собран так, чтобы страницы отдавались одним файлом: CSS и JS встроены в HTML,
иконки — инлайновые SVG, растровых картинок и внешних доменов нет. Исключение —
`/search/`: её индекс и скрипт вынесены отдельно, чтобы не раздувать общий inline-бюджет.

## Репозитории

| Локально | GitHub |
|---|---|
| `D:\October CMS` | https://github.com/elips0675-web/OctoberCMS |
| `D:\Elv Landing` | https://github.com/elips0675-web/elvstudio |
| `D:\Elv test` | https://github.com/elips0675-web/Elvtest |

## Что в репозитории

| Путь | Роль |
|---|---|
| `plugins/elv/kit` | плагин: модели, миграции, маршруты форм, оплата, лицензии, Seo, админка |
| `themes/elv-studio` | тема лендинга: макет, партиалы, страницы, `styles.css`, `app.js` |
| `docs/` | аудит источников, решения этапа 0, аудит блога (`docs/blog-audit.md`), план развития блога (`docs/blog-content-plan.md`), калькулятор и студийные функции (`docs/studio-features-roadmap.md`), краткая карта этапов 01–11 (`docs/stages-01-11.md`), политика контактов/чата/фильтра (`docs/content-policy.md`), поиск (`docs/search.md`), версионность прайса (`docs/prices.md`) и QA-снимок (`docs/qa/`) |
| `Что сделано.txt` / `Что доделать.txt` | журнал этапов и открытый бэклог |
| `test/Для анализа.txt` | самодостаточный срез для внешнего AI-аудита (DeepSeek/Qwen): стек, карта кода, гейты, бэклог; `test/README.md` — как пользоваться |
| `config/`, `app/`, `bootstrap/` | конфигурация инсталляции October CMS |

`vendor/` и `modules/` (ядро October) в репозиторий не входят — ставятся через `composer install`.

## Требования

- PHP 8.3+ (`pdo_mysql`, `mbstring`, `json`, `gd` для OG-картинки)
- MySQL 8.0+ или MariaDB 10.6+
- Composer 2

## Установка

```bash
composer install
cp .env.example .env
php artisan key:generate
```

В `.env` задать базу и адрес:

```
APP_URL=http://localhost:8090
DB_CONNECTION=mysql
DB_DATABASE=elv_studio
DB_USERNAME=root
DB_PASSWORD=
ACTIVE_THEME=elv-studio
```

Создать пустую базу, затем:

```bash
php artisan october:migrate
php artisan elv:kit:demo
php artisan serve --port=8090
```

`elv:kit:demo` наполняет каталог, статьи и слоты бронирования идемпотентно;
`elv:kit:reset` удаляет демо-данные, `elv:kit:package` собирает ZIP поставки.

## Быстрый запуск на Windows

1. Запустить MySQL (Laragon, порт 3306).
2. Двойной клик по `запуск.bat` в корне проекта: скрипт находит PHP и MySQL,
   поднимает `php artisan serve` на `0.0.0.0:8090`, ждёт порт и открывает браузер.
3. Админка — `http://localhost:8090/admin`, логин `admin`, пароль в
   `.local/admin-credentials.md` (файл не коммитится).

Если сервер уже поднят, `админка.bat` просто откроет страницу входа.
Порты по умолчанию: сайт `8090`, MySQL `3306`.

## Страницы темы

| Адрес | Файл |
|---|---|
| `/` | `pages/index.htm` |
| `/kit/` | `pages/kit.htm` |
| `/modules/`, `/modules/:slug/` | `pages/modules.htm`, `pages/module.htm` |
| `/pricing/` | `pages/pricing.htm` |
| `/demo/` | `pages/demo.htm` |
| `/blog/`, `/blog/tech/`, `/blog/:slug/` | `pages/blog.htm`, `pages/blog-tech.htm`, `pages/blog-post.htm` |
| `/docs/` | `pages/docs.htm` |
| `/order/`, `/order/success/:number/` | `pages/order.htm`, `pages/order-success.htm` |
| `/booking/`, `/feedback/`, `/contacts/` | одноимённые страницы |
| `/terms/`, `/privacy/`, `/sitemap/`, `/search/`, `/404/` | служебные страницы |
| `/sitemap.xml`, `/robots.txt`, `/llms.txt`, `/rss.xml`, `/search-index.json` | маршруты плагина |

## Команды

```bash
npm run check                       # ссылки, SEO, заголовки, бюджет, сироты, консоль
npm run check:console               # 107 статических страниц: консоль, 5xx, 360 px
php tools/blog-content-audit.php    # срез блога: объём, ссылки, CTA, FAQ, meta
php tools/calculator-check.php      # формула, рекомендации, шаблон PDF
php tools/chat-check.php            # Консультант: ответы, ссылки, фильтр слов
npm run test:calculator             # Chrome E2E: выбор → результат → PDF
php tools/production-check.php      # прод-чек: права, БД, .htaccess, ключи, очередь
php tools/production-check.php --strict  # предупреждения считаются ошибками
php artisan elv:kit:demo            # демо-каталог, статьи, слоты
php artisan elv:kit:demo --refresh-slots
php tools/export-static.php --origin=https://elvstudio.vercel.app  # демо для Vercel, индексируется
php artisan elv:kit:reset --force   # очистить демо-данные
php artisan elv:kit:package         # ZIP темы и плагина для покупателя
```

## Безопасность и данные

- Формы под `web` + CSRF + `throttle`, honeypot в каждой форме.
- Оплата: редирект на ЮKassa или Stripe, фиксация только по подписи/повторному GET статуса,
  mock-режим отключён при наличии ключей и вне `local`.
- Секреты живут в `.env`; в репозиторий попадает только `.env.example`.
- База, заказы и брони остаются на своём сервере: внешних трекеров и CDN нет.

## Лицензия

Код темы и плагина — собственность Elv Studio. Сторонние компоненты — October CMS
и Laravel под их лицензиями (`LICENSE.md`).
