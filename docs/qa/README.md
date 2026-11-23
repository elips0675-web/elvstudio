# QA-снимок блога — 15–18.11.2026

Проверки после разделения блога на пользовательские и технические статьи
(35 `business` / 15 `tech`, всего 50).

## Lighthouse mobile

| Страница | Performance | Accessibility | Best Practices | SEO | LCP | CLS | TBT |
|---|---:|---:|---:|---:|---:|---:|---:|
| `/blog/tech/` | 100 | 100 | 100 | 100 | 1.2 s | 0 | 0 ms |
| `/blog/skolko-stoit-sajt-s-onlajn-zapisyu/` | 100 | 100 | 100 | 100 | 1.1 s | 0.002 | 0 ms |

Полные отчёты: `lighthouse-blog-tech.json`, `lighthouse-blog-article.json`.

Команда:

```bash
npx lighthouse "https://elvstudio.vercel.app/blog/tech/" \
  --output=json --output-path=docs/qa/lighthouse-blog-tech.json \
  --only-categories=performance,accessibility,best-practices,seo \
  --chrome-flags="--headless=new --no-sandbox --disable-gpu" --quiet
```

## Viewport 360 px

Проверены `/blog/`, `/blog/tech/` и пользовательская статья
`/blog/skolko-stoit-sajt-s-onlajn-zapisyu/` в headless Chrome с
`width=360`, `height=800`, `mobile=true`.

Во всех трёх случаях:

- `window.innerWidth = 360`;
- `document.documentElement.scrollWidth = 360`;
- горизонтальной прокрутки нет.

Скрытые служебные элементы (`skip-link`, honeypot-поле) находятся за
пределами экрана намеренно, в `offenders` они перечислены отдельно.
Скриншоты: `viewport-360-*.png`, данные: `viewport-360.json`.

## Firefox 360 px

Открыты `/blog/tech/` и статья про цену сайта с онлайн-записью в
headless Firefox с окном 360×800. Скриншоты:
`firefox-360-blog-tech.png`, `firefox-360-blog-article.png`.

Safari на Windows не запускается; проверка Safari остаётся ручной на
macOS/iOS.

## Консоль и 360 px на всех страницах

`npm run check:console` обходит все 107 HTML-страниц `demo-static/` в
headless Chrome с viewport 360×800 и падает на console-ошибке,
необработанном исключении, ошибке в `Log`, ответе 5xx или
горизонтальной прокрутке. На 18.11.2026 — 0 ошибок и 0 страниц с
переполнением.

Команда входит в общий `npm run check` (после `check:orphans`).

## Vercel-демо индексируется

Статический снимок `demo-static/` открыт для поиска: `vercel.json` не
отдаёт `X-Robots-Tag: noindex`, а `robots.txt` содержит `Allow: /`.
Проверка живого деплоя:

```bash
curl -I https://elvstudio.vercel.app/
curl https://elvstudio.vercel.app/robots.txt
```

В ответе не должно быть `X-Robots-Tag: noindex`, в `robots.txt` должен
быть `Allow: /`. `npm run check` проверяет, что демо снова не закрыли
случайно. После возврата индексации переобход запрашивается в Google
Search Console.

## Локальный Lighthouse

Чистый замер без расширений и фоновой нагрузки (headless Chrome,
`npx lighthouse@12`, mobile + simulated 4G):

| Метрика | Значение |
|---|---|
| Performance | 100 |
| SEO | 100 |
| FCP | 1.2 s |
| LCP | 1.3 s |
| TBT | 0 ms |
| CLS | 0 |
| Speed Index | 1.8 s |

Desktop-пресет: Performance 100, SEO 100, FCP 0.5 s, LCP 0.7 s, TBT 0 ms,
CLS 0, Speed Index 0.8 s. Единственная аудит-находка — размер DOM главной
(821 элемент, оценка 0.5); на балл Performance она не влияет.

Числа из DevTools могут отличаться: другой профиль throttling, открытые
расширения или параллельная нагрузка на машину добавляют TBT и Speed
Index. Для сравнения запускайте headless Lighthouse на том же профиле.

## Выгрузка статей блога

```bash
php tools/export-blog-articles.php
php tools/export-blog-articles.php --base=http://localhost:8090
```

Скрипт читает `elv_kit_posts` и пишет два файла в `exports/`:

- `blog-articles-YYYY-MM-DD.csv` — таблица для Excel (UTF-8 BOM, `;`);
- `blog-articles-YYYY-MM-DD.md` — сводная таблица и карточки статей.

Поля: ID, раздел, категория, заголовок, slug, URL, ключевые слова,
краткое описание, SEO-описание, автор, дата, время чтения, число
символов и статус. Каталог `exports/` в `.gitignore`: это выгрузка,
а не исходники.

## Аудит контента блога

```bash
php tools/blog-content-audit.php
```

Скрипт читает 112 статей и пишет `exports/blog-content-audit-YYYY-MM-DD.md`:
объём, внутренние ссылки, CTA, FAQ, изображения, совпадение SEO-описания
с кратким, категории, даты, теги и таблицу всех статей. На 23.11.2026:
0 статей короче 1 500 символов, 0 без внутренних ссылок, 0 без CTA,
0 SEO-описаний, равных кратким; FAQ есть у 34 статей, изображений в теле
по-прежнему нет. Это отчёт, не блокирующий гейт; пороги для будущего гейта — в
`docs/blog-content-plan.md`.

## Прод-чек

```bash
php tools/production-check.php
php tools/production-check.php --strict
```

Скрипт проверяет PHP 8.3+, расширения `pdo_mysql`/`mbstring`/`json`/`gd`,
права `storage/*` и `bootstrap/cache`, `.htaccess` с `RewriteEngine On`,
`APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL`, подключение к БД, очередь,
почту и наличие платёжных ключей. Секреты не печатаются — только факт
«задано / не задано». `--strict` превращает предупреждения в ошибки: это
режим для cPanel после заполнения `.env`. Локально 16.11.2026: 15 OK,
7 предупреждений (local, debug, localhost, sync, log, ключи, cron),
0 ошибок.
