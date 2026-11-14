<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * FormController — общая часть публичных форм.
 *
 * Один вход и три выхода: успех, ошибки валидации (422 для JSON) и редирект
 * с flash-сообщением для браузера. Honeypot отбивает ботов молча: они
 * получают тот же «успех», но запись не создаётся.
 */
abstract class FormController
{
    /**
     * store принимает отправку формы.
     *
     * @return JsonResponse|RedirectResponse
     */
    public function store(Request $request)
    {
        if (AntiSpam::isBot($request)) {
            return $this->done($request);
        }

        $data = $this->normalize($request);
        $validator = Validator::make($data, $this->rules(), $this->messages());

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        return $this->handle($data, $request) ?: $this->done($request);
    }

    /**
     * rules — правила валидации.
     *
     * @return array<string, mixed>
     */
    abstract protected function rules(): array;

    /**
     * messages — человеческие тексты ошибок.
     *
     * @return array<string, string>
     */
    abstract protected function messages(): array;

    /**
     * handle сохраняет данные. Возвращает ответ, если запись не состоялась.
     *
     * @param  array<string, mixed>  $data
     * @return JsonResponse|RedirectResponse|null
     */
    abstract protected function handle(array $data, Request $request);

    /**
     * fallbackPath — куда вернуть, если браузер не прислал Referer.
     */
    abstract protected function fallbackPath(): string;

    /**
     * anchor — якорь блока формы для возврата.
     */
    abstract protected function anchor(): string;

    /**
     * normalize — обрезает теги и нормализует скаляры.
     *
     * @return array<string, mixed>
     */
    protected function normalize(Request $request): array
    {
        $data = [];

        foreach ((array) $request->all() as $key => $value) {
            if (is_string($value)) {
                $data[$key] = AntiSpam::clean($value, 5000);
            }
            elseif (is_scalar($value)) {
                $data[$key] = $value;
            }
        }

        $data['consent'] = $request->boolean('consent');

        return $data;
    }

    /**
     * invalid отдаёт ошибки валидации.
     *
     * @param  array<string, mixed>  $errors
     * @return JsonResponse|RedirectResponse
     */
    protected function invalid(Request $request, array $errors)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'code' => 'VALIDATION',
                'errors' => $errors,
            ], 422);
        }

        $first = '';

        foreach ($errors as $messages) {
            $first = (string) (is_array($messages) ? reset($messages) : $messages);
            break;
        }

        return $this->back($request, ['error' => '1'])
            ->withInput()
            ->withErrors($errors)
            ->with('elv_errors', $errors)
            ->with('elv_error', $first);
    }

    /**
     * conflict отвечает на занятый слот.
     *
     * @return JsonResponse|RedirectResponse
     */
    protected function conflict(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'code' => 'SLOT_UNAVAILABLE',
                'message' => $message,
            ], 409);
        }

        return $this->back($request, ['error' => 'slot'])
            ->withInput()
            ->withErrors(['time' => $message])
            ->with('elv_errors', ['time' => [$message]])
            ->with('elv_error', $message);
    }

    /**
     * done отвечает на успешную отправку.
     *
     * @return JsonResponse|RedirectResponse
     */
    protected function done(Request $request, array $extra = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['ok' => true], $extra), 201);
        }

        return $this->back($request, ['sent' => '1']);
    }

    /**
     * back возвращает на страницу формы.
     *
     * @param  array<string, string>  $query
     */
    protected function back(Request $request, array $query): RedirectResponse
    {
        return redirect($this->returnUrl($request, $query) . '#' . $this->anchor());
    }

    /**
     * returnUrl собирает адрес возврата, не теряя чужие параметры.
     *
     * @param  array<string, string>  $query
     */
    protected function returnUrl(Request $request, array $query): string
    {
        $referer = trim((string) $request->headers->get('referer', ''));
        $host = $referer !== '' ? (string) parse_url($referer, PHP_URL_HOST) : '';

        $url = ($referer !== '' && $host !== '' && $host === $request->getHost())
            ? $referer
            : rtrim(url('/'), '/') . $this->fallbackPath();

        $parts = parse_url($url);
        $params = [];

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $params);
        }

        $params = array_merge($params, $query);

        $base = ($parts['scheme'] ?? 'http') . '://' . ($parts['host'] ?? 'localhost');

        if (!empty($parts['port'])) {
            $base .= ':' . $parts['port'];
        }

        $base .= $parts['path'] ?? '/';

        return $base . '?' . http_build_query($params);
    }
}
