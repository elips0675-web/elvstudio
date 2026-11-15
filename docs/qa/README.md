# QA-снимок блога — 15.11.2026

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

`npm run check:console` обходит все 78 HTML-страниц `demo-static/` в
headless Chrome с viewport 360×800 и падает на console-ошибке,
необработанном исключении, ошибке в `Log`, ответе 5xx или
горизонтальной прокрутке. На 15.11.2026 — 0 ошибок и 0 страниц с
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
