<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AccountService;
use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Chat;
use Elv\Kit\Classes\TicketService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

/**
 * ChatController — ответы Консультанта по базе знаний сайта.
 *
 * Внешних API нет: ответ собирается из каталога и настроек, поэтому работает
 * и на демо-контуре без ключей. Лимит запросов задан в routes.php.
 *
 * Переписка хранится в сессии: кнопка «Передать оператору» отправляет её в
 * поддержку вместе с контактами, поэтому покупателю не нужно пересказывать
 * вопрос заново. История обрезается до последних сообщений и живёт до отправки
 * обращения.
 */
class ChatController extends Controller
{
    public const HISTORY_KEY = 'elv_chat_history';
    public const HISTORY_LIMIT = 30;

    public function store(Request $request)
    {
        if (AntiSpam::isBot($request)) {
            return AntiSpam::json([
                'ok' => true,
                'answer' => 'Здравствуйте! Чем помочь по Elv Kit?',
                'chips' => ['Сколько стоит?', 'Как установить?'],
                'link' => null,
                'open' => null,
                'sources' => [],
                'rejected' => false,
            ]);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:2|max:' . Chat::MAX_LENGTH,
        ], [
            'message.required' => 'Напишите вопрос.',
            'message.min' => 'Напишите вопрос подробнее — от двух символов.',
            'message.max' => 'Слишком длинный вопрос: до ' . Chat::MAX_LENGTH . ' символов.',
        ]);

        if ($validator->fails()) {
            return AntiSpam::json([
                'ok' => false,
                'code' => 'VALIDATION',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $key = 'elv-chat:' . $request->session()->getId();

        if (RateLimiter::tooManyAttempts($key, 15)) {
            return AntiSpam::json([
                'ok' => false,
                'code' => 'RATE_LIMIT',
                'message' => 'Слишком много сообщений подряд. Подождите минуту.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        $message = (string) $request->input('message');
        $result = Chat::answer($message);

        // Отклонённые реплики (бессмыслица, грубость) в историю не попадают:
        // оператору нужен диалог по делу, а не мусор.
        if (empty($result['rejected']) && empty($result['hide_user'])) {
            $this->remember('user', $message);
            $this->remember('bot', (string) ($result['answer'] ?? ''));
        }

        return AntiSpam::json(['ok' => true] + $result);
    }

    /**
     * handoff создаёт обращение из переписки Консультанта.
     *
     * Ответы бота сохраняются отдельным автором: поддержка видит, что уже
     * сказал консультант. После отправки история очищается, чтобы следующее
     * обращение не приносило старые сообщения.
     */
    public function handoff(Request $request)
    {
        if (AntiSpam::isBot($request)) {
            return AntiSpam::json(['ok' => true, 'number' => '', 'url' => null]);
        }

        $key = 'elv-handoff:' . $request->session()->getId();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            return AntiSpam::json([
                'ok' => false,
                'code' => 'RATE_LIMIT',
                'message' => 'Слишком много попыток. Подождите несколько минут.',
            ], 429)->header('Retry-After', (string) RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 600);

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:160',
            'email' => 'required|email|max:191',
            'message' => 'nullable|string|max:2000',
            'consent' => 'accepted',
        ], [
            'name.required' => 'Укажите имя.',
            'email.required' => 'Укажите e-mail для ответа.',
            'email.email' => 'Проверьте адрес электронной почты.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ]);

        if ($validator->fails()) {
            return AntiSpam::json([
                'ok' => false,
                'code' => 'VALIDATION',
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $ticket = (new TicketService())->handoff([
            'email' => (string) $request->input('email'),
            'name' => AntiSpam::clean((string) $request->input('name'), 160),
            'message' => AntiSpam::clean((string) $request->input('message', ''), 2000),
            'history' => (array) $request->session()->get(self::HISTORY_KEY, []),
        ]);

        $request->session()->forget(self::HISTORY_KEY);

        $customer = (new AccountService())->user();

        return AntiSpam::json([
            'ok' => true,
            'number' => (string) $ticket->number,
            'url' => $customer ? (string) $ticket->url : null,
        ]);
    }

    protected function remember(string $role, string $text): void
    {
        $text = trim($text);

        if ($text === '') {
            return;
        }

        $history = (array) session()->get(self::HISTORY_KEY, []);
        $history[] = ['role' => $role === 'bot' ? 'bot' : 'user', 'text' => mb_substr($text, 0, 2000)];

        session()->put(self::HISTORY_KEY, array_slice($history, -self::HISTORY_LIMIT));
    }
}
