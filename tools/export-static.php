<?php

/**
 * export-static — статическая демо-копия лендинга для Vercel.
 *
 * Vercel не исполняет PHP, поэтому October CMS там отдаётся как файл.
 * Команда снимает готовый HTML с локального сервера, переписывает базовый
 * адрес и складывает страницы в demo-static/<path>/index.html.
 *
 * Запуск: php tools/export-static.php --origin=https://elvstudio.vercel.app
 */

$options = getopt('', ['base::', 'origin::', 'out::']);

$base = rtrim($options['base'] ?? 'http://127.0.0.1:8090', '/');
$origin = rtrim($options['origin'] ?? 'https://elvstudio.vercel.app', '/');
$out = rtrim($options['out'] ?? dirname(__DIR__) . '/demo-static', '/\\');

function staticFetch(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => 'ElvKitStaticExport/1.0',
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $error = curl_error($handle);
    curl_close($handle);

    if ($body === false) {
        fwrite(STDERR, 'fetch failed: ' . $url . ' — ' . $error . PHP_EOL);

        return [0, ''];
    }

    return [$status, (string) $body];
}

function staticRemove(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($items as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($directory);
}

function staticCopy(string $source, string $target): void
{
    if (!is_dir($source)) {
        return;
    }

    if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
        throw new RuntimeException('cannot create ' . $target);
    }

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($items as $item) {
        $destination = $target . '/' . $items->getSubPathname();

        if ($item->isDir()) {
            if (!is_dir($destination)) {
                mkdir($destination, 0775, true);
            }

            continue;
        }

        copy($item->getPathname(), $destination);
    }
}

function staticPagePath(string $out, string $path): string
{
    return $path === '/'
        ? $out . '/index.html'
        : ($path === '/404' ? $out . '/404.html' : $out . '/' . trim($path, '/') . '/index.html');
}

function staticFinalize(string $html, string $base, string $origin, string $inject): string
{
    $html = str_replace([$base, 'http://localhost:8090'], $origin, $html);
    $quoted = preg_quote($origin, '~');
    $html = (string) preg_replace('~(\b(?:href|src|action)=")' . $quoted . '/~i', '$1/', $html);
    $html = (string) preg_replace('~(\b(?:href|src|action)=")' . $quoted . '"~i', '$1/"', $html);
    $html = (string) preg_replace('~(<link rel="canonical" href=")/~i', '$1' . $origin . '/', $html);

    $html = (string) preg_replace('~/blog/?\?category=([a-z0-9-]+)(?:&amp;|&)page=(\d+)~i', '/blog/category/$1/page/$2/', $html);
    $html = (string) preg_replace('~/blog/?\?page=(\d+)(?:&amp;|&)category=([a-z0-9-]+)~i', '/blog/category/$2/page/$1/', $html);
    $html = (string) preg_replace('~/blog/?\?category=([a-z0-9-]+)~i', '/blog/category/$1/', $html);
    $html = (string) preg_replace('~/blog/?\?page=(\d+)~i', '/blog/page/$1/', $html);
    $html = (string) preg_replace('~/blog/page/1/?~i', '/blog/', $html);
    $html = (string) preg_replace('~/blog/category/([a-z0-9-]+)/page/1/?~i', '/blog/category/$1/', $html);

    $bar = '<div id="elv-demo-bar" role="status">Статическая демо-версия Elv Kit: формы, бронирование и оплата отключены. Полная версия работает на PHP-хостинге.</div>';

    if (!str_contains($html, 'elv-demo-bar')) {
        $html = (string) preg_replace('~(<body[^>]*>)~i', '$1' . $bar, $html, 1);
    }

    return str_replace('</body>', $inject . '</body>', $html);
}

function staticWrite(string $out, string $path, string $html): void
{
    $file = staticPagePath($out, $path);

    if (!is_dir(dirname($file))) {
        mkdir(dirname($file), 0775, true);
    }

    file_put_contents($file, $html);
}

$style = '<style>'
    . '#elv-demo-bar{position:relative;z-index:80;background:#0b1220;color:#dfe6f5;font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;padding:9px 16px;text-align:center}'
    . '#elv-static-note{position:fixed;left:50%;bottom:24px;transform:translateX(-50%) translateY(8px);background:#0b1220;color:#fff;padding:12px 18px;border-radius:12px;font:14px/1.4 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;opacity:0;pointer-events:none;transition:opacity .2s,transform .2s;z-index:200;max-width:90vw;text-align:center;box-shadow:0 12px 40px rgba(0,0,0,.35)}'
    . '#elv-static-note.is-visible{opacity:1;transform:translateX(-50%) translateY(0)}'
    . '</style>';

$script = <<<'HTML'
<script>
(function () {
    window.ELV_STATIC_DEMO = true;

    var note = function (text) {
        var box = document.getElementById('elv-static-note');

        if (!box) {
            box = document.createElement('div');
            box.id = 'elv-static-note';
            box.setAttribute('role', 'status');
            document.body.appendChild(box);
        }

        box.textContent = text;
        box.classList.add('is-visible');
        window.clearTimeout(box.elvTimer);
        box.elvTimer = window.setTimeout(function () {
            box.classList.remove('is-visible');
        }, 6000);
    };

    document.addEventListener('click', function (event) {
        var node = event.target instanceof Element ? event.target : null;

        if (!node) {
            return;
        }

        var order = node.closest('a[href*="/order?"], a[href*="/order/?"]');

        if (order) {
            event.preventDefault();
            event.stopImmediatePropagation();
            note('Оформление заказа доступно в полной версии на PHP-хостинге.');
            return;
        }

        if (node.closest('[data-chat-open]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            note('ИИ-чат доступен в полной версии на PHP-хостинге.');
            return;
        }

        if (node.closest('a[href*="?tag="]')) {
            event.preventDefault();
            event.stopImmediatePropagation();
            note('Фильтр по тегам доступен в полной версии на PHP-хостинге.');
        }
    }, true);

    document.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopImmediatePropagation();

        var form = event.target;

        if (form instanceof Element && form.matches('form[role="search"]')) {
            note('Поиск по статьям доступен в полной версии на PHP-хостинге.');
            return;
        }

        note('Формы отключены в статической демо-версии.');
    }, true);
})();
</script>
HTML;

$inject = $style . $script;

staticRemove($out);
mkdir($out, 0775, true);

[, $sitemap] = staticFetch($base . '/sitemap.xml');
preg_match_all('~<loc>([^<]+)</loc>~', $sitemap, $matches);

$paths = [];

foreach ($matches[1] ?? [] as $loc) {
    $path = (string) parse_url(html_entity_decode($loc, ENT_QUOTES | ENT_XML1), PHP_URL_PATH);

    if ($path === '' || str_contains($path, '/download/') || str_contains($path, '/order/success') || str_starts_with($path, '/admin')) {
        continue;
    }

    $paths[rtrim($path, '/') ?: '/'] = true;
}

$paths['/'] = true;
$paths['/404'] = true;
ksort($paths);

$pages = 0;

foreach (array_keys($paths) as $path) {
    $url = $base . ($path === '/' ? '/' : $path);
    [$status, $html] = staticFetch($url);

    if ($path === '/404') {
        if ($html === '') {
            fwrite(STDERR, 'skip: /404' . PHP_EOL);
            continue;
        }
    } elseif ($status !== 200) {
        fwrite(STDERR, 'skip: ' . $path . ' (' . $status . ')' . PHP_EOL);
        continue;
    }

    $html = staticFinalize($html, $base, $origin, $inject);

    $relative = $path === '/' ? 'index.html' : ($path === '/404' ? '404.html' : ltrim($path, '/') . '/index.html');
    staticWrite($out, $path, $html);
    $pages++;
    echo 'page: ' . $relative . PHP_EOL;
}

$extra = 0;
$blogBase = $base . '/blog/';
[$blogStatus, $blogHtml] = staticFetch($blogBase);

if ($blogStatus === 200 && $blogHtml !== '') {
    $blogPages = 1;

    if (preg_match('~Страница\s+\d+\s+из\s+(\d+)~u', $blogHtml, $match)) {
        $blogPages = max(1, (int) $match[1]);
    }

    for ($page = 2; $page <= $blogPages; $page++) {
        [$status, $html] = staticFetch($blogBase . '?page=' . $page);

        if ($status !== 200 || $html === '') {
            fwrite(STDERR, 'skip: /blog/page/' . $page . ' (' . $status . ')' . PHP_EOL);
            continue;
        }

        $path = '/blog/page/' . $page;
        staticWrite($out, $path, staticFinalize($html, $base, $origin, $inject));
        $extra++;
        echo 'page: blog/page/' . $page . '/index.html' . PHP_EOL;
    }

    preg_match_all('~/blog/?\?category=([a-z0-9-]+)~i', $blogHtml, $categoryMatches);

    foreach (array_unique($categoryMatches[1] ?? []) as $slug) {
        $categoryUrl = $blogBase . '?category=' . $slug;
        [$categoryStatus, $categoryHtml] = staticFetch($categoryUrl);

        if ($categoryStatus !== 200 || $categoryHtml === '') {
            fwrite(STDERR, 'skip: /blog/category/' . $slug . ' (' . $categoryStatus . ')' . PHP_EOL);
            continue;
        }

        $categoryPages = 1;

        if (preg_match('~Страница\s+\d+\s+из\s+(\d+)~u', $categoryHtml, $categoryPageMatch)) {
            $categoryPages = max(1, (int) $categoryPageMatch[1]);
        }

        for ($page = 1; $page <= $categoryPages; $page++) {
            $html = $categoryHtml;

            if ($page > 1) {
                [$status, $html] = staticFetch($categoryUrl . '&page=' . $page);

                if ($status !== 200 || $html === '') {
                    fwrite(STDERR, 'skip: /blog/category/' . $slug . '/page/' . $page . PHP_EOL);
                    continue;
                }
            }

            $path = '/blog/category/' . $slug . ($page > 1 ? '/page/' . $page : '');
            staticWrite($out, $path, staticFinalize($html, $base, $origin, $inject));
            $extra++;
            echo 'page: blog/category/' . $slug . ($page > 1 ? '/page/' . $page : '') . '/index.html' . PHP_EOL;
        }
    }
}

$pages += $extra;

foreach (['sitemap.xml', 'robots.txt', 'llms.txt', 'rss.xml'] as $name) {
    [$status, $body] = staticFetch($base . '/' . $name);

    if ($status !== 200 || $body === '') {
        fwrite(STDERR, 'skip: ' . $name . ' (' . $status . ')' . PHP_EOL);
        continue;
    }

    file_put_contents($out . '/' . $name, str_replace([$base, 'http://localhost:8090'], $origin, $body));
    echo 'file: ' . $name . PHP_EOL;
}

staticCopy(dirname(__DIR__) . '/themes/elv-studio/assets', $out . '/themes/elv-studio/assets');

echo 'pages: ' . $pages . PHP_EOL;
echo 'origin: ' . $origin . PHP_EOL;
echo 'output: ' . $out . PHP_EOL;
