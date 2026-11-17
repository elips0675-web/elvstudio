<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\Calculator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * QuoteController — серверный PDF сметы калькулятора.
 *
 * PDF печатает локальный Chrome/Chromium из временного HTML, собранного Blade.
 * Так маршрут не зависит от второго HTTP-запроса и работает на single-worker
 * локальном сервере. Если браузера нет, фронт откатывается на window.print().
 */
class QuoteController
{
    public function pdf(Request $request)
    {
        $plan = (string) $request->query('plan', 'single');
        $modules = (string) $request->query('modules', '');
        $task = (string) $request->query('task', 'landing');
        $hosting = (string) $request->query('hosting', 'own');

        $quote = Calculator::quote($plan, $modules, $task, $hosting);

        if (($quote['plan'] ?? []) === []) {
            abort(404);
        }

        $chrome = $this->chromeBinary();

        if ($chrome === null) {
            return response()->json([
                'ok' => false,
                'code' => 'PDF_UNAVAILABLE',
                'message' => 'Серверный PDF недоступен: не найден Chrome/Chromium.',
            ], 503);
        }

        $dir = storage_path('app/tmp');
        File::ensureDirectoryExists($dir);

        $base = 'quote-' . $quote['number'] . '-' . bin2hex(random_bytes(4));
        $pdf = $dir . DIRECTORY_SEPARATOR . $base . '.pdf';
        $htmlPath = $dir . DIRECTORY_SEPARATOR . $base . '.html';

        try {
            File::put($htmlPath, view('elv.kit::quote-pdf', ['quote' => $quote])->render());
            $this->printPdf($chrome, $this->fileUrl($htmlPath), $pdf, $dir, $base);
            @unlink($htmlPath);

            if (!is_file($pdf) || filesize($pdf) < 1000) {
                throw new \RuntimeException('Chrome did not create a PDF');
            }

            return response()
                ->download($pdf, 'elv-quote-' . $quote['number'] . '.pdf', [
                    'Content-Type' => 'application/pdf',
                    'X-Quote-Number' => $quote['number'],
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            @unlink($htmlPath);

            if (is_file($pdf)) {
                @unlink($pdf);
            }

            logger()->warning('Elv quote PDF failed', [
                'quote' => $quote['number'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'ok' => false,
                'code' => 'PDF_FAILED',
                'message' => 'Не удалось собрать PDF. Используйте печать браузера.',
            ], 503);
        }
    }

    private function printPdf(string $chrome, string $url, string $pdf, string $dir, string $base): void
    {
        $lastError = '';

        foreach (['--headless=new', '--headless'] as $index => $headless) {
            $profile = $dir . DIRECTORY_SEPARATOR . 'chrome-' . $base . '-' . $index;

            try {
                $process = new Process([
                    $chrome,
                    $headless,
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--disable-extensions',
                    '--hide-scrollbars',
                    '--no-first-run',
                    '--no-sandbox',
                    '--no-pdf-header-footer',
                    '--run-all-compositor-stages-before-draw',
                    '--virtual-time-budget=3000',
                    '--user-data-dir=' . $profile,
                    '--print-to-pdf=' . $pdf,
                    $url,
                ]);
                $process->setTimeout(40);
                $process->run();

                if ($process->isSuccessful() && is_file($pdf) && filesize($pdf) >= 1000) {
                    return;
                }

                $lastError = trim($process->getErrorOutput() . ' ' . $process->getOutput());
            } finally {
                File::deleteDirectory($profile);
            }
        }

        throw new \RuntimeException($lastError !== '' ? $lastError : 'Chrome print failed');
    }

    private function fileUrl(string $path): string
    {
        $path = str_replace('\\', '/', $path);

        return (str_starts_with($path, '/') ? 'file://' : 'file:///') . $path;
    }

    private function chromeBinary(): ?string
    {
        $candidates = [
            (string) env('ELV_CHROME_BIN', ''),
            'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
            'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/usr/bin/chromium',
            '/usr/bin/chromium-browser',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || !is_file($candidate)) {
                continue;
            }

            if (DIRECTORY_SEPARATOR !== '\\' && !is_executable($candidate)) {
                continue;
            }

            return $candidate;
        }

        return null;
    }
}
