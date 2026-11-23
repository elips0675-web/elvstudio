<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AccountService;
use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Models\Install;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * AccountController — вход по ссылке, выход и отвязка домена.
 *
 * Форма входа не говорит, есть ли такой покупатель: ответ одинаковый для
 * любого адреса. Ссылка одноразовая, поэтому чужой переход по ней ничего не
 * оставляет открытым.
 */
class AccountController
{
    public function __construct(protected AccountService $account = new AccountService())
    {
    }

    /**
     * login принимает e-mail и отправляет ссылку.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function login(Request $request)
    {
        if (AntiSpam::isBot($request)) {
            return $this->sent($request);
        }

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:191',
        ], [
            'email.required' => 'Укажите e-mail.',
            'email.email' => 'Проверьте адрес электронной почты.',
        ]);

        if ($validator->fails()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'VALIDATION',
                    'errors' => $validator->errors()->toArray(),
                ], 422);
            }

            return redirect(url('/account') . '/?error=1')
                ->withInput()
                ->with('elv_error', $validator->errors()->first('email'));
        }

        $email = mb_strtolower(AntiSpam::clean((string) $request->input('email'), 191));
        $name = AntiSpam::clean((string) $request->input('name', ''), 160);

        $this->account->requestLink($email, $name);

        return $this->sent($request);
    }

    /**
     * auth открывает кабинет по одноразовому токену.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function auth(string $token)
    {
        if (!$this->account->verify($token)) {
            return redirect(url('/account') . '/?login=invalid');
        }

        return redirect(url('/account') . '/');
    }

    /**
     * logout закрывает сессию кабинета.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function logout(Request $request)
    {
        $this->account->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect(url('/account') . '/');
    }

    /**
     * unbind снимает домен с лицензии, если прошло 30 дней.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function unbind(int $id)
    {
        $customer = $this->account->user();

        if (!$customer) {
            abort(404);
        }

        $install = Install::query()->find($id);

        if (!$install) {
            abort(404);
        }

        $result = $this->account->unbind($customer, $install);

        return redirect(url('/account') . '/?' . ($result['ok'] ? 'unbound=1' : 'unbind=blocked') . '#domains')
            ->with($result['ok'] ? 'elv_success' : 'elv_error', $result['message']);
    }

    /**
     * sent — одинаковый ответ на любой адрес.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    protected function sent(Request $request)
    {
        $message = 'Если этот адрес есть в заказах, мы отправили ссылку для входа. Проверьте почту и папку «Спам».';

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message], 201);
        }

        return redirect(url('/account') . '/?sent=1')->with('elv_success', $message);
    }
}
