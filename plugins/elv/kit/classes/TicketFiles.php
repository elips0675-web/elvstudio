<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Ticket;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * TicketFiles — вложения обращений: белый список, хранение и выдача.
 *
 * Файлы лежат в `storage/app/elv-kit/tickets/{id}/`, вне `public/`: прямой
 * ссылки у них нет, скачивание идёт через маршрут с проверкой прав. Имя на
 * диске случайное, исходное имя хранится только в метаданных, поэтому путь
 * нельзя подделать через параметры запроса.
 */
class TicketFiles
{
    public const MAX_FILES = 3;
    public const MAX_KB = 5120;

    /** @var string[] */
    public const ALLOWED = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'pdf', 'txt', 'log', 'csv', 'zip'];

    /** @var string[] */
    public const ALLOWED_MIME = [
        'image/png', 'image/jpeg', 'image/webp', 'image/gif',
        'application/pdf', 'text/plain', 'text/csv', 'application/csv',
        'application/zip', 'application/x-zip-compressed',
        'application/octet-stream',
    ];

    public static function enabled(): bool
    {
        $value = Settings::get('ticket_attachments_enabled');

        // Пустое значение — это ещё не сохранённая настройка, а не «выключено»:
        // поле включено по умолчанию, поэтому пусто и null читаем как true.
        return $value === '' || $value === null ? true : (bool) $value;
    }

    /**
     * rules — правила валидации загрузки для формы обращения.
     *
     * @return array<string, string>
     */
    public static function rules(): array
    {
        $mimes = implode(',', self::ALLOWED);

        return [
            'attachments' => 'array|max:' . self::MAX_FILES,
            'attachments.*' => 'file|max:' . self::MAX_KB . '|mimes:' . $mimes,
        ];
    }

    /**
     * messages — человеческие тексты ошибок загрузки.
     *
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'attachments.max' => 'К обращению можно приложить не больше ' . self::MAX_FILES . ' файлов.',
            'attachments.*.file' => 'Файл не загрузился. Попробуйте ещё раз.',
            'attachments.*.max' => 'Файл больше ' . (self::MAX_KB / 1024) . ' МБ.',
            'attachments.*.mimes' => 'Такой тип файла не принимается: ' . implode(', ', self::ALLOWED) . '.',
        ];
    }

    /**
     * store — сохраняет загруженные файлы и возвращает метаданные для БД.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array<int, array{name: string, size: int, ext: string, file: string}>
     */
    public function store(Ticket $ticket, array $files): array
    {
        if (!self::enabled() || $files === []) {
            return [];
        }

        $dir = $this->directory((int) $ticket->id);

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return [];
        }

        $saved = [];

        foreach (array_slice($files, 0, self::MAX_FILES) as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $ext = mb_strtolower((string) $file->getClientOriginalExtension());
            $mime = mb_strtolower((string) $file->getMimeType());

            if (
                !in_array($ext, self::ALLOWED, true)
                || !in_array($mime, self::ALLOWED_MIME, true)
                || $file->getSize() > self::MAX_KB * 1024
            ) {
                continue;
            }

            $stored = Str::random(32) . '.' . $ext;
            $name = $this->cleanName((string) $file->getClientOriginalName(), $ext);
            $size = (int) $file->getSize();

            if (!$file->move($dir, $stored)) {
                continue;
            }

            $saved[] = [
                'name' => $name,
                'size' => $size,
                'ext' => $ext,
                'file' => $stored,
            ];
        }

        return $saved;
    }

    /**
     * path — полный путь к файлу по имени из метаданных.
     */
    public function path(int $ticketId, string $stored): ?string
    {
        $stored = basename($stored);

        if (!preg_match('/^[A-Za-z0-9]{32}\.[a-z0-9]{2,5}$/', $stored)) {
            return null;
        }

        $path = $this->directory($ticketId) . DIRECTORY_SEPARATOR . $stored;

        return is_file($path) ? $path : null;
    }

    /**
     * find — метаданные вложения по имени файла на диске.
     *
     * @param  array<int, array<string, mixed>>  $attachments
     * @return array<string, mixed>|null
     */
    public static function find(array $attachments, string $stored): ?array
    {
        foreach ($attachments as $item) {
            if (is_array($item) && (string) ($item['file'] ?? '') === $stored) {
                return $item;
            }
        }

        return null;
    }

    public static function sizeLabel(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return round($bytes / 1024 / 1024, 1) . ' МБ';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024) . ' КБ';
        }

        return $bytes . ' Б';
    }

    protected function directory(int $ticketId): string
    {
        return storage_path('app/elv-kit/tickets/' . $ticketId);
    }

    protected function cleanName(string $name, string $ext): string
    {
        $name = basename($name);
        $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '_', $name) ?: 'file.' . $ext;
        $name = trim($name);

        if ($name === '' || $name === '.' . $ext) {
            $name = 'file.' . $ext;
        }

        return mb_substr($name, 0, 120);
    }
}
