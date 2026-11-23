# Боевое окружение

Чек-лист того, что нельзя проверить на локальном стенде: реальные ключи, SMTP,
очередь, Cron и бэкапы. Пункт «Блокер 6» в `Что доделать.txt`.

Проверка одной командой:

```bash
php tools/production-check.php --strict
```

На проде — 0 предупреждений и код возврата 0. На локальном стенде `--strict`
намеренно падает: там `APP_ENV=local`, `APP_DEBUG=true`, очередь `sync`, почта
в лог и нет платёжных ключей. Это состояние разработки, а не ошибка.

## 1. `.env`

| Переменная | Значение на проде | Почему |
|---|---|---|
| `APP_ENV` | `production` | включает проверки и запрещает mock-оплату |
| `APP_DEBUG` | `false` | иначе ошибки и стектрейсы видны посетителям |
| `APP_KEY` | `php artisan key:generate` один раз | шифрование сессий и cookie |
| `APP_URL` | `https://<домен>` | абсолютные ссылки, canonical, письма |
| `APP_TIMEZONE` | `Europe/Minsk` | время в заказах и брони |
| `DB_*` | своя база и пользователь | не `root` и не пустой пароль |
| `QUEUE_CONNECTION` | `database` | письма уходят воркером, а не в момент запроса |
| `CACHE_STORE` | `file` | без Redis на дешёвом хостинге |
| `SESSION_DRIVER` | `file` | сессии переживают перезапуск PHP |
| `SESSION_SECURE_COOKIE` | `true` | cookie только по HTTPS |
| `MAIL_MAILER` | `smtp` | `log` пишет письма в файл, покупатель ничего не получит |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_ENCRYPTION` | от почтового провайдера | отправка писем |
| `MAIL_FROM_ADDRESS` | адрес на своём домене | `example.com` и `elv.local` блокируют спам-фильтры |
| `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET` | из личного кабинета | приём оплаты |
| `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | из панели Stripe | приём оплаты |
| `LOG_CHANNEL` | `daily` | логи не растут одним файлом |
| `LOG_LEVEL` | `warning` | на проде не нужен `debug` |
| `ELV_CHROME_BIN` | путь к Chrome/Chromium | PDF сметы; пусто — поиск в стандартных местах |
| `ELV_FRAME_ANCESTORS` | `https://<домен витрины>` | встраивание демо-стенда в `/preview/`; пусто — только свой домен |

Ключи ЮKassa и Stripe можно задать и в админке (Настройки → Продажи), `.env`
имеет приоритет. После правки `.env` — `php artisan config:clear`: на стенде
конфигурация кэшируется и старое значение останется в памяти воркера.

## 2. Очередь

Письма покупателю, администратору и подтверждение подписки ставятся в очередь
(`Mail::queue`). Без воркера они лежат в таблице `jobs` и не уходят.

1. `php artisan october:up` — создаёт `jobs` и `failed_jobs`. Если очередь
   `database`, а таблицы нет, `production-check.php` покажет FAIL.
2. Воркер держит supervisor или systemd:

```ini
[program:elv-queue]
command=php /var/www/elv/artisan queue:work --sleep=1 --tries=3 --timeout=90
directory=/var/www/elv
autostart=true
autorestart=true
numprocs=1
user=www-data
redirect_stderr=true
stdout_logfile=/var/log/elv-queue.log
```

3. Проверка: `php artisan queue:work --once` и `SELECT COUNT(*) FROM jobs` —
   после заказа счётчик должен вернуться к нулю.
4. Разбор неудачных: `php artisan queue:failed`, затем `php artisan queue:retry all`.

## 3. Cron

Одна строка, раз в минуту:

```cron
* * * * * cd /var/www/elv && php artisan schedule:run >> /dev/null 2>&1
```

Без Cron не работают напоминания и регулярные задачи October. Проверить, что
задача вообще запускается, можно по `storage/logs` и `php artisan schedule:list`.

## 4. Платежи

| Шлюз | Ключи | Адрес уведомлений |
|---|---|---|
| ЮKassa | `YOOKASSA_SHOP_ID`, `YOOKASSA_SECRET` | `https://<домен>/payment/yookassa` |
| Stripe | `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET` | `https://<домен>/payment/stripe`, событие `checkout.session.completed` |

- У ЮKassa нет HMAC-подписи: безопасность держится на повторном GET платежа
  через API (`YooKassa`, `PaymentController`).
- Mock-оплата в `production` запрещена кодом (`PaymentManager::mockAllowed()`),
  статус `paid` ставится только после подтверждения шлюза.
- Smoke-тест после подключения: заказ на минимальную сумму → письмо с ключом и
  ссылкой `/download/{token}` → скачивание архива → повторный вебхук не создаёт
  второй платёж и второе письмо.

## 5. Почта

- Настроить SPF, DKIM и DMARC на домене отправителя. Письмо с лицензионным
  ключом, попавшее в спам, — потерянный покупатель.
- Проверить три письма: покупателю после оплаты, администратору о новом заказе,
  подтверждение подписки (double opt-in).
- Адрес администратора — Настройки → Продажи (`order_notify_email`) или общий
  `email`; заглушка `sales@example.com` на проде не годится.

## 6. Файлы и права

- `storage/`, `storage/app`, `storage/framework/*`, `storage/logs`,
  `bootstrap/cache` — запись для пользователя PHP.
- `.htaccess` с `RewriteEngine On` (Apache) или `try_files` (Nginx).
- HTTPS на всём домене, `SESSION_SECURE_COOKIE=true`.
- Товарные архивы собираются в `storage/app/kit/` — каталог не должен быть
  доступен по HTTP напрямую, выдача идёт только через `/download/{token}`.

## 7. Бэкапы

- Ежедневно: дамп базы, `storage/app` (архивы, вложения) и `.env` отдельно от
  кода. Хранить 30 дней.
- Раз в квартал — проверка восстановления на копии: развернуть дамп, открыть
  `/pricing/`, `/order/`, админку.
- Пример дампа: `mysqldump --single-transaction --quick -u<user> -p <база> | gzip > backup-$(date +%F).sql.gz`.

## 8. После деплоя

```bash
php artisan october:up
php artisan config:clear && php artisan cache:clear
php tools/production-check.php --strict
node tools/check-headers.mjs
```

Smoke-список: `/`, `/pricing/`, `/order/?product=<товар>`, `/blog/`, `/sitemap.xml`,
`/robots.txt`, `/llms.txt`, `/admin`.

Демо-данные на проде не запускать: `php artisan elv:kit:demo` — команда стенда.

## 9. Демо-стенды ниш и iframe

Нишевый стенд (`booking.<домен>`) показывается на витрине внутри `<iframe>`.
Чтобы браузер не заблокировал встраивание:

1. На стенде: `ELV_FRAME_ANCESTORS="https://<домен витрины>"` — CSP
   `frame-ancestors` перечислит родителя, `X-Frame-Options` не отдаётся.
2. На стенде: `SESSION_SAME_SITE=none` и `SESSION_SECURE_COOKIE=true` — иначе
   POST из iframe (заказ, запись, чат) получит 419.
3. Проверка: `node tools/check-iframe-demo.mjs --base=https://booking.<домен>
   --parent=https://<домен витрины>`.

Пустая переменная или мусор в ней (`*`, адрес без схемы) оставляют
`frame-ancestors 'self'`: случайная строка не должна открывать сайт для
встраивания куда угодно.
