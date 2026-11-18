<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Chat;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * ChatController — ответы Консультанта по базе знаний сайта.
 *
 * Внешних API нет: ответ собирается из каталога и настроек, поэтому работает
 * и на демо-контуре без ключей. Лимит запросов задан в routes.php.
 */
class ChatController extends Controller
{
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

        $result = Chat::answer((string) $request->input('message'));

        return AntiSpam::json(['ok' => true] + $result);
    }
}
