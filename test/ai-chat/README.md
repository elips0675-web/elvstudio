# `test/ai-chat` — снимок кода для AI-анализа

Дата среза: 18.11.2026. Stage 56. Коммит: `70eb5b6`.
Репозиторий: https://github.com/elips0675-web/elvstudio

Этот набор закрывает пробел, который был у аудита Qwen: отчёт
`Аудит и план улучшения Elv Studio  Elv Kit Qwen.html` не читал код, а просил
прислать `composer.json`, `plugins/elv/kit/Plugin.php`, конфигурацию темы и
структуру папок. Здесь эти файлы и код Консультанта/поиска лежат в виде
обычных `.txt`, которые можно загрузить в чат.

## Что внутри

| Файл | Что внутри |
|---|---|
| `01-context.txt` | что за проект, что анализировать, текущее поведение, проверки, ограничения, вопросы аудитору |
| `02-structure.txt` | отслеживаемая структура репозитория (без `demo-static` целиком) |
| `03-composer-package-theme.txt` | `composer.json`, `package.json`, `theme.yaml`, `version.yaml`, `README.md` |
| `04-plugin.php.txt` | полный `plugins/elv/kit/Plugin.php` |
| `05-chat.php.txt` | полный `plugins/elv/kit/classes/Chat.php` |
| `06-search.php.txt` | полный `plugins/elv/kit/classes/Search.php` |
| `07-textfilter.php.txt` | полный `plugins/elv/kit/classes/TextFilter.php` |
| `08-chat-controller.txt` | полный `ChatController.php` |
| `09-widget.js.txt` | полный `widget.js` |
| `10-chat-check.php.txt` | полный `tools/chat-check.php` |
| `11-sitemap-seo.txt` | `sitemap.htm`, `Seo.php`, `Sitemap.php`, `routes.php` |
| `12-search-page.txt` | `search.htm`, `search.js`, `search.css` |
| `13-docs.txt` | `docs/search.md`, `docs/content-policy.md`, `docs/00-audit-documents.md` |
| `ALL-IN-ONE.txt` | всё перечисленное одним файлом (~193 КБ) |

## Как загружать

1. Если чат принимает один файл — загрузить `ALL-IN-ONE.txt`.
2. Если чат принимает несколько файлов — загрузить `01-context.txt`,
   `02-structure.txt`, `03-composer-package-theme.txt` и код:
   `05`–`10`. Карту сайта (`11`), поиск (`12`) и документацию (`13`)
   добавить, если вопрос про sitemap/SEO.
3. Если у модели маленький контекст — начать с `01-context.txt`,
   `05-chat.php.txt`, `06-search.php.txt`, `07-textfilter.php.txt`,
   `09-widget.js.txt`, `10-chat-check.php.txt`.

Пример запроса:

> Ниже снимок Elv Landing (Stage 56). Проверь Консультанта и поиск:
> где фильтр может пропустить бессмыслицу или грубость, корректен ли
> `rejected`-сценарий в виджете, нет ли ложных срабатываний на нормальных
> запросах. Дай находки с `file:line`, без выдуманных метрик и без
> предложений платных сервисов.

## Что здесь **не** лежит

- `.env`, пароли, ключи, дампы БД.
- `vendor/`, `modules/` (ядро October) и `demo-static` целиком.
- Полный бэклог и журнал — они в корне: `Что доделать.txt`, `Что сделано.txt`.

## Важно

- Это **снимок**, а не живой код. После изменений PHP/JS его нужно
  пересобрать; дата и коммит — в шапке каждого файла.
- Код в файлах не переписан: пути и содержимое совпадают с рабочим деревом
  на момент среза.
- `TextFilter.php` содержит список грубых основ. Он нужен для анализа
  фильтра, но не является секретом.
