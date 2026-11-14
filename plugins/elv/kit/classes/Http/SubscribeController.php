<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Mail\SubscribeConfirm;
use Elv\Kit\Models\Subscriber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * SubscribeController — подписка на блог с подтверждением по ссылке.
 *
 * Без double opt-in адрес можно подставить чужой: письмо уходит владельцу
 * ящика, а подписка считается оформленной. Поэтому `confirmed_at` ставится
 * только по переходу из письма.
 */
class SubscribeController extends FormController
{
    /**
     * store принимает адрес.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        if (AntiSpam::isBot($request)) {
            return $this->done($request);
        }

        $validator = validator($request->all(), [
            'email' => 'required|email|max:191',
        ], [
            'email.required' => 'Укажите e-mail.',
            'email.email' => 'Проверьте адрес электронной почты.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        $email = mb_strtolower(AntiSpam::clean((string) $request->input('email'), 191));

        $subscriber = Subscriber::query()->where('email', $email)->first();

        if (!$subscriber) {
            $subscriber = new Subscriber();
            $subscriber->email = $email;
            $subscriber->token = Subscriber::newToken();
            $subscriber->ip = AntiSpam::ip($request);
            $subscriber->save();
        }
        elseif ($subscriber->unsubscribed_at !== null) {
            $subscriber->unsubscribed_at = null;
            $subscriber->token = Subscriber::newToken();
            $subscriber->save();
        }

        try {
            Mail::to($subscriber->email)->queue(new SubscribeConfirm($subscriber));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо подписки не отправлено', ['email' => $email, 'error' => $e->getMessage()]);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true], 201);
        }

        return $this->back($request, ['subscribed' => 'pending'])
            ->with('elv_success', 'Проверьте почту: на ' . $email . ' отправлено письмо со ссылкой подтверждения.');
    }

    /**
     * confirm включает подписку по токену из письма.
     */
    public function confirm(string $token)
    {
        $subscriber = Subscriber::query()->where('token', $token)->first();

        if (!$subscriber) {
            return redirect('/blog/?subscribed=invalid');
        }

        $subscriber->confirmed_at = $subscriber->confirmed_at ?: now();
        $subscriber->unsubscribed_at = null;
        $subscriber->save();

        return redirect('/blog/?subscribed=1');
    }

    /**
     * unsubscribe отключает подписку.
     */
    public function unsubscribe(string $token)
    {
        $subscriber = Subscriber::query()->where('token', $token)->first();

        if ($subscriber) {
            $subscriber->unsubscribed_at = now();
            $subscriber->save();
        }

        return redirect('/blog/?unsubscribed=1');
    }

    protected function rules(): array
    {
        return [];
    }

    protected function messages(): array
    {
        return [];
    }

    protected function handle(array $data, Request $request)
    {
        return null;
    }

    protected function fallbackPath(): string
    {
        return '/blog/';
    }

    protected function anchor(): string
    {
        return 'subscribe-form';
    }
}
