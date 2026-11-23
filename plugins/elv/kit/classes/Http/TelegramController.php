<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\Telegram;
use Elv\Kit\Classes\TicketService;
use Elv\Kit\Models\Ticket;
use Illuminate\Http\Request;

/**
 * TelegramController — вебхук ChatOps.
 *
 * Команды принимаются только из настроенного чата и только с секретом в
 * адресе. Ответ покупателю уходит письмом, а подтверждение — в Telegram.
 * Вебхук всегда отвечает 200, иначе Telegram повторяет одно и то же сообщение.
 */
class TelegramController
{
    public function __construct(protected TicketService $tickets = new TicketService())
    {
    }

    public function webhook(Request $request, string $secret)
    {
        if (!Telegram::secretMatches($secret)) {
            abort(404);
        }

        $update = (array) $request->json()->all();
        $message = (array) ($update['message'] ?? $update['edited_message'] ?? []);
        $chatId = (string) ($message['chat']['id'] ?? '');

        if (!Telegram::chatMatches($chatId)) {
            return response()->json(['ok' => true]);
        }

        $text = trim((string) ($message['text'] ?? ''));

        if ($text === '') {
            return response()->json(['ok' => true]);
        }

        $author = trim((string) ($message['from']['first_name'] ?? '')) ?: 'Поддержка';

        if (preg_match('~^/(start|help)\b~u', $text)) {
            Telegram::send(Telegram::helpText());

            return response()->json(['ok' => true]);
        }

        if (preg_match('~^/reply\s+(TK-\d{6})\s+([\s\S]+)$~u', $text, $match)) {
            $ticket = Ticket::query()->where('number', $match[1])->first();

            if (!$ticket) {
                Telegram::send('Обращение ' . $match[1] . ' не найдено.');

                return response()->json(['ok' => true]);
            }

            $this->tickets->supportReply($ticket, trim($match[2]), $author);
            Telegram::send('Ответ отправлен в ' . $ticket->number . '. Статус: ждём ответа покупателя.');

            return response()->json(['ok' => true]);
        }

        if (preg_match('~^/close\s+(TK-\d{6})$~u', $text, $match)) {
            $ticket = Ticket::query()->where('number', $match[1])->first();

            if (!$ticket) {
                Telegram::send('Обращение ' . $match[1] . ' не найдено.');

                return response()->json(['ok' => true]);
            }

            $this->tickets->close($ticket, $author);
            Telegram::send('Обращение ' . $ticket->number . ' закрыто.');

            return response()->json(['ok' => true]);
        }

        if (preg_match('~^/status\s+(TK-\d{6})$~u', $text, $match)) {
            $ticket = Ticket::query()->where('number', $match[1])->first();

            if (!$ticket) {
                Telegram::send('Обращение ' . $match[1] . ' не найдено.');

                return response()->json(['ok' => true]);
            }

            Telegram::send(implode("\n", [
                $ticket->number . ' — ' . $ticket->status_label,
                'Тема: ' . $ticket->subject,
                'Покупатель: ' . $ticket->email,
                $ticket->sla_label,
            ]));

            return response()->json(['ok' => true]);
        }

        Telegram::send('Не понял команду. ' . "\n" . Telegram::helpText());

        return response()->json(['ok' => true]);
    }
}
