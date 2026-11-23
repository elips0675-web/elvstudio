<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AccountService;
use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * TicketController — обращения покупателя: создать, ответить, закрыть.
 *
 * Доступ только из кабинета: покупатель опознаётся по сессии Magic Link, а
 * обращение проверяется по `customer_id`/e-mail. Чужое обращение отвечает 404,
 * как несуществующее.
 */
class TicketController
{
    public function __construct(
        protected AccountService $account = new AccountService(),
        protected TicketService $tickets = new TicketService()
    ) {
    }

    /**
     * store создаёт обращение с первым сообщением.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $customer = $this->account->user();

        if (!$customer) {
            return $this->denied($request);
        }

        if (AntiSpam::isBot($request)) {
            return $this->created($request, null);
        }

        $validator = Validator::make($request->all(), [
            'subject' => 'required|string|min:5|max:200',
            'body' => 'required|string|min:10|max:5000',
            'order_id' => 'nullable|integer|min:1',
            'consent' => 'accepted',
        ], [
            'subject.required' => 'Укажите тему обращения.',
            'subject.min' => 'Тема слишком короткая.',
            'body.required' => 'Опишите вопрос.',
            'body.min' => 'Опишите вопрос подробнее — минимум 10 символов.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray());
        }

        $orderId = (int) $request->input('order_id', 0);
        $order = null;

        if ($orderId > 0) {
            $order = $this->account->orders($customer)->firstWhere('id', $orderId);

            if (!$order) {
                return $this->invalid($request, ['order_id' => ['Заказ не найден среди ваших заказов.']]);
            }
        }

        $ticket = $this->tickets->open($customer, [
            'subject' => AntiSpam::clean((string) $request->input('subject'), 200),
            'body' => AntiSpam::clean((string) $request->input('body'), 5000),
            'order_id' => $order ? (int) $order->id : null,
        ]);

        return $this->created($request, $ticket);
    }

    /**
     * reply добавляет сообщение покупателя в обращение.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function reply(Request $request, int $id)
    {
        $customer = $this->account->user();

        if (!$customer) {
            return $this->denied($request);
        }

        $ticket = $this->account->ticket($customer, $id);

        if (!$ticket) {
            abort(404);
        }

        if (AntiSpam::isBot($request)) {
            return $this->replied($request, $id);
        }

        $validator = Validator::make($request->all(), [
            'body' => 'required|string|min:2|max:5000',
        ], [
            'body.required' => 'Напишите сообщение.',
            'body.min' => 'Сообщение слишком короткое.',
        ]);

        if ($validator->fails()) {
            return $this->invalid($request, $validator->errors()->toArray(), $id);
        }

        $this->tickets->customerReply(
            $ticket,
            AntiSpam::clean((string) $request->input('body'), 5000),
            $customer->display_name
        );

        return $this->replied($request, $id);
    }

    /**
     * close закрывает обращение по просьбе покупателя.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function close(Request $request, int $id)
    {
        $customer = $this->account->user();

        if (!$customer) {
            return $this->denied($request);
        }

        $ticket = $this->account->ticket($customer, $id);

        if (!$ticket) {
            abort(404);
        }

        $this->tickets->close($ticket, $customer->display_name);

        return redirect(url('/tickets') . '/?id=' . (int) $ticket->id . '&closed=1#thread')
            ->with('elv_success', 'Обращение закрыто. Если понадобится, напишите снова — оно откроется.');
    }

    protected function created(Request $request, $ticket)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => true,
                'id' => $ticket ? (int) $ticket->id : null,
                'number' => $ticket ? (string) $ticket->number : '',
                'url' => $ticket ? (string) $ticket->url : '',
            ], 201);
        }

        $url = $ticket
            ? url('/tickets') . '/?id=' . (int) $ticket->id . '&created=1#thread'
            : url('/tickets') . '/?created=1';

        return redirect($url)->with('elv_success', 'Обращение создано. Ответ придёт на e-mail и появится здесь.');
    }

    protected function replied(Request $request, int $id)
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => true], 201);
        }

        return redirect(url('/tickets') . '/?id=' . $id . '&sent=1#thread')
            ->with('elv_success', 'Сообщение отправлено.');
    }

    /**
     * @param  array<string, mixed>  $errors
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    protected function invalid(Request $request, array $errors, ?int $id = null)
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

        $url = url('/tickets') . '/' . ($id ? '?id=' . $id . '#thread' : '#new-ticket');

        return redirect($url)
            ->withInput()
            ->withErrors($errors)
            ->with('elv_errors', $errors)
            ->with('elv_error', $first);
    }

    protected function denied(Request $request)
    {
        if ($request->expectsJson()) {
            return response()->json([
                'ok' => false,
                'code' => 'AUTH',
                'message' => 'Войдите в кабинет, чтобы написать в поддержку.',
            ], 401);
        }

        return redirect(url('/account') . '/')
            ->with('elv_error', 'Войдите в кабинет, чтобы написать в поддержку.');
    }
}
