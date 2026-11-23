<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Catalog;
use Elv\Kit\Classes\Questions;
use Elv\Kit\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * QuestionController — вопрос к продукту со страницы витрины.
 *
 * Форма открыта без входа в кабинет: вопрос покупателя не требует заказа.
 * Запись создаётся в статусе `new` и на витрине не появляется, пока её не
 * опубликуют ответом. Возврат — на ту же страницу продукта к блоку «Вопрос-ответ».
 */
class QuestionController extends FormController
{
    protected ?Product $product = null;

    public function __construct(protected Questions $questions = new Questions())
    {
    }

    protected function rules(): array
    {
        return [
            'product' => 'required|string|max:160',
            'name' => 'required|string|max:160',
            'email' => 'required|email|max:191',
            'question' => 'required|string|min:10|max:3000',
            'consent' => 'accepted',
        ];
    }

    protected function messages(): array
    {
        return [
            'product.required' => 'Не выбран продукт для вопроса.',
            'name.required' => 'Укажите имя.',
            'email.required' => 'Укажите e-mail — на него ответим.',
            'email.email' => 'Проверьте адрес e-mail.',
            'question.required' => 'Напишите вопрос.',
            'question.min' => 'Опишите вопрос подробнее — минимум 10 символов.',
            'consent.accepted' => 'Нужно согласие на обработку персональных данных.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return JsonResponse|RedirectResponse|null
     */
    protected function handle(array $data, Request $request)
    {
        if (!Questions::enabled()) {
            return $this->invalid($request, ['question' => ['Приём вопросов временно закрыт.']]);
        }

        $this->product = Catalog::find((string) ($data['product'] ?? ''));

        if (!$this->product) {
            return $this->invalid($request, ['product' => ['Такой продукт не найден.']]);
        }

        $this->questions->ask($this->product, [
            'name' => (string) $data['name'],
            'email' => (string) $data['email'],
            'question' => (string) $data['question'],
        ], AntiSpam::ip($request));

        return null;
    }

    protected function fallbackPath(): string
    {
        if ($this->product) {
            return $this->product->kind === 'kit'
                ? '/kit/'
                : '/modules/' . $this->product->slug . '/';
        }

        return '/kit/';
    }

    protected function anchor(): string
    {
        return 'qa';
    }

    protected function done(Request $request, array $extra = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['ok' => true], $extra), 201);
        }

        return $this->back($request, ['sent' => '1'])
            ->with('elv_success', 'Вопрос отправлен. Ответ появится на этой странице после проверки.');
    }
}
