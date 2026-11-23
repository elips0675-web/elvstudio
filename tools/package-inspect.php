<?php

/**
 * package-inspect — содержимое товарного и демо-архивов Elv Kit в JSON.
 *
 * Вызывается из `tools/check-package.mjs`, отдельно запускать не нужно.
 * Собирает оба архива заново (--force), чтобы проверялся текущий код,
 * а не старый файл в storage.
 */

require __DIR__ . '/../bootstrap/autoload.php';

ini_set('display_errors', '0');
error_reporting(E_ALL);

set_error_handler(function (int $severity, string $message, string $file = '', int $line = 0): bool {
    fwrite(STDERR, 'PHP: ' . $message . ' (' . $file . ':' . $line . ')' . PHP_EOL);

    return true;
});

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Elv\Kit\Classes\DownloadService;

$service = new DownloadService();
$report = [];

foreach (['product' => false, 'demo' => true] as $key => $demo) {
    $path = $service->ensureArchive($demo, true);
    $zip = new ZipArchive();

    if ($zip->open($path) !== true) {
        fwrite(STDERR, 'FAIL: не удалось открыть ' . $path . "\n");
        exit(1);
    }

    $files = [];

    for ($index = 0; $index < $zip->numFiles; $index++) {
        $files[] = $zip->getNameIndex($index);
    }

    $versionFile = (string) $zip->getFromName('elv-kit/plugin/updates/version.yaml');
    $readme = (string) $zip->getFromName('elv-kit/README.md');
    $zip->close();

    preg_match_all('~^\s*-\s*([a-z0-9_]+\.php)\s*$~mu', $versionFile, $listed);
    $shippedUpdates = array_map(
        fn ($file) => basename($file),
        array_filter($files, fn ($file) => (bool) preg_match('~^elv-kit/plugin/updates/[a-z0-9_]+\.php$~', $file))
    );

    $report[$key] = [
        'path' => $path,
        'size' => filesize($path),
        'files' => count($files),
        'seeds' => array_values(array_filter($files, fn ($file) => (bool) preg_match('~updates/seed_kit_[a-z0-9_]+\.php$~', $file))),
        'versionFile' => $versionFile,
        'versionFileSeeds' => (bool) preg_match('~seed_kit_[a-z0-9_]+\.php~', $versionFile),
        'listed' => $listed[1] ?? [],
        'missingListed' => array_values(array_diff($listed[1] ?? [], $shippedUpdates)),
        'readme' => mb_substr($readme, 0, 60),
        'hasComposer' => in_array('elv-kit/plugin/composer.json', $files, true)
            && in_array('elv-kit/theme/composer.json', $files, true),
        'hasScreenshot' => in_array('elv-kit/theme/screenshot.png', $files, true),
        'hasDemoDataset' => in_array('elv-kit/plugin/demo/blog.json', $files, true),
        'presets' => array_values(array_filter(
            $files,
            fn ($file) => (bool) preg_match('~^elv-kit/plugin/demo/presets/[a-z0-9-]+\.json$~', $file)
        )),
    ];
}

$datasetFile = __DIR__ . '/../plugins/elv/kit/demo/blog.json';
$dataset = is_file($datasetFile) ? json_decode((string) file_get_contents($datasetFile), true) : null;

$loaded = null;

try {
    $loaded = count(Elv\Kit\Classes\Demo::posts());
} catch (Throwable $e) {
    $loaded = null;
}

$report['dataset'] = [
    'file' => is_file($datasetFile),
    'seed' => is_array($dataset['seed'] ?? null) ? count($dataset['seed']) : 0,
    'overrides' => is_array($dataset['overrides'] ?? null) ? count($dataset['overrides']) : 0,
    'extras' => is_array($dataset['extras'] ?? null) ? count($dataset['extras']) : 0,
    'loaded' => $loaded,
];

echo json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
