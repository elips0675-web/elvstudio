<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Mail\MessageAdmin;
use Elv\Kit\Models\Message;
use Elv\Kit\Models\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * FeedbackController — виджет обратной связи.
 *
 * Сообщение сначала сохраняется, потом ставится письмо: если SMTP лежит,
 * обращение не теряется, оно видно в админке.
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

        $admin = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        if ($admin !== '') {
            try {
                Mail::to($admin)->queue(new MessageAdmin($message));
            }
            catch (\Throwable $e) {
                Log::warning('Elv Kit: письмо обратной связи не отправлено', ['message' => $message->id, 'error' => $e->getMessage()]);
            }
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'id' => $message->id], 201);
        }

        return $this->back($request, ['sent' => '1'])
            ->with('elv_success', 'Сообщение отправлено. Ответим на ' . $message->email . '.');
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
