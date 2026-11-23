<?php namespace Elv\Kit\Classes;

use Elv\Kit\Models\Settings;
use Elv\Kit\Models\Ticket;
use Elv\Kit\Models\TicketMessage;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Telegram — уведомления поддержки и ChatOps без внешней библиотеки.
 *
 * Бот шлёт только служебные строки: номер обращения, тему, адрес покупателя
 * и срок ответа. Токен и chat_id берутся из настроек или `.env`; пустой токен
 * выключает канал, а сайт продолжает работать.
 */
class Telegram
{
    public static function token(): string
    {
        return trim((string) (Settings::get('telegram_bot_token') ?: env('ELV_TELEGRAM_BOT_TOKEN', '')));
    }

    public static function chatId(): string
    {
        return trim((string) (Settings::get('telegram_chat_id') ?: env('ELV_TELEGRAM_CHAT_ID', '')));
    }

    public static function secret(): string
    {
        return trim((string) (Settings::get('telegram_webhook_secret') ?: env('ELV_TELEGRAM_WEBHOOK_SECRET', '')));
    }

    public static function enabled(): bool
    {
        return static::token() !== '' && static::chatId() !== '';
    }

    public static function webhookUrl(): string
    {
        $secret = static::secret();

        return $secret === '' ? '' : url('/telegram/webhook/' . $secret);
    }

    public static function secretMatches(string $secret): bool
    {
        $expected = static::secret();

        return $expected !== '' && hash_equals($expected, trim($secret));
    }

    public static function chatMatches(string $chatId): bool
    {
        $expected = static::chatId();

        return $expected !== '' && hash_equals($expected, trim($chatId));
    }

    /**
     * send — одно сообщение в настроенный чат. Ошибка не ломает обращение.
     */
    public static function send(string $text): bool
    {
        if (!static::enabled()) {
            return false;
        }

        $text = mb_substr(trim($text), 0, 4000);

        if ($text === '') {
            return false;
        }

        try {
            $response = Http::timeout(6)
                ->asForm()
                ->post('https://api.telegram.org/bot' . static::token() . '/sendMessage', [
                    'chat_id' => static::chatId(),
                    'text' => $text,
                    'disable_web_page_preview' => 'true',
                ]);

            if (!$response->successful()) {
                Log::warning('Elv Kit: Telegram отклонил сообщение', [
                    'status' => $response->status(),
                    'description' => (string) ($response->json('description') ?? ''),
                ]);

                return false;
            }

            return true;
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: Telegram недоступен', ['error' => $e->getMessage()]);

            return false;
        }
    }

    public static function notifyNew(Ticket $ticket): bool
    {
        return static::send(implode("\n", [
            'Новое обращение ' . $ticket->number,
            'Тема: ' . $ticket->subject,
            'Покупатель: ' . $ticket->email,
            Support::describe($ticket),
            '',
            'Ответить: /reply ' . $ticket->number . ' текст',
        ]));
    }

    public static function notifyCustomerMessage(Ticket $ticket, TicketMessage $message): bool
    {
        return static::send(implode("\n", [
            'Сообщение покупателя в ' . $ticket->number,
            'Тема: ' . $ticket->subject,
            'Покупатель: ' . $ticket->email,
            '',
            mb_substr((string) $message->body, 0, 1200),
            '',
            'Ответить: /reply ' . $ticket->number . ' текст',
        ]));
    }

    public static function helpText(): string
    {
        return implode("\n", [
            'Elv Kit: поддержка',
            '/reply TK-000001 текст — ответить покупателю',
            '/close TK-000001 — закрыть обращение',
            '/status TK-000001 — статус и срок',
        ]);
    }
}
