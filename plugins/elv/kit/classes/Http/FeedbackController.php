<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\TicketService;
use Elv\Kit\Models\Message;
use Illuminate\Http\Request;

/**
 * FeedbackController — виджет обратной связи.
 *
 * Сообщение остаётся в `elv_kit_messages` для истории админки, но работа с
 * покупателем идёт через обращение: оно получает номер, срок ответа и видно в
 * кабинете, если e-mail совпал с клиентом. Письмо администратору отправляет
 * `TicketService`, поэтому дубля нет.
 */
class FeedbackController extends FormController
{
    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:160',
            'email' => 'required|email|max:191',
            'phone' => 'nullable|string|max:32',
            'subject' => 'nullable|string|max:200',
            'message' => 'required|string|min:5|max:5000',
            'consent' => 'accepted',
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Укажите имя.',
            'email.required' => 'Укажите e-mail для ответа.',
            'email.email' => 'Проверьте адрес электронной почты.',
            'message.required' => 'Напишите сообщение.',
            'message.min' => 'Сообщение слишком короткое.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }

    protected function handle(array $data, Request $request)
    {
        $message = new Message();
        $message->fill([
            'name' => $data['name'],
            'email' => mb_strtolower((string) $data['email']),
            'phone' => $data['phone'] ?? '',
            'subject' => $data['subject'] ?? '',
            'message' => $data['message'],
            'status' => Message::STATUS_NEW,
            'source' => AntiSpam::clean($data['source'] ?? 'widget', 64),
            'ip' => AntiSpam::ip($request),
        ]);
        $message->save();

        $ticket = (new TicketService())->openGuest([
            'email' => (string) $message->email,
            'name' => (string) $message->name,
            'subject' => (string) ($message->subject ?: mb_substr((string) $message->message, 0, 120)),
            'body' => (string) $message->message,
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $message->id,
                'ticket' => (string) $ticket->number,
            ], 201);
        }

        return $this->back($request, ['sent' => '1'])
            ->with('elv_success', 'Обращение ' . $ticket->number . ' создано. Ответ придёт на ' . $message->email . '.');
    }

    protected function fallbackPath(): string
    {
        return '/contacts/';
    }

    protected function anchor(): string
    {
        return 'feedback-form';
    }
}
