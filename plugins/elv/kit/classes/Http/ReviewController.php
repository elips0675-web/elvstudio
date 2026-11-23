<?php namespace Elv\Kit\Classes\Http;

use Elv\Kit\Classes\AntiSpam;
use Elv\Kit\Classes\Reviews;
use Elv\Kit\Models\Order;
use Elv\Kit\Models\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * ReviewController — отзыв по одноразовой ссылке из письма.
 *
 * Ссылка выдаётся оплаченному заказу (`review_token`) и гасится после отправки.
 * Отзыв ждёт модерации: на витрину и в `AggregateRating` попадают только
 * одобренные. Просроченная или использованная ссылка отвечает 404.
 */
class ReviewController extends FormController
{
    protected ?Order $order = null;

    protected ?Product $product = null;

    protected string $token = '';

    public function __construct(protected Reviews $reviews = new Reviews())
    {
    }

    protected function rules(): array
    {
        return [
            'author_name' => 'required|string|max:160',
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:160',
            'text' => 'required|string|min:20|max:4000',
            'pros' => 'nullable|string|max:1000',
            'cons' => 'nullable|string|max:1000',
            'consent' => 'accepted',
        ];
    }

    protected function messages(): array
    {
        return [
            'author_name.required' => 'Укажите имя.',
            'rating.required' => 'Поставьте оценку от 1 до 5.',
            'rating.min' => 'Оценка — от 1 до 5.',
            'rating.max' => 'Оценка — от 1 до 5.',
            'text.required' => 'Напишите отзыв.',
            'text.min' => 'Расскажите подробнее — минимум 20 символов.',
            'consent.accepted' => 'Нужно согласие на публикацию отзыва.',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return JsonResponse|RedirectResponse|null
     */
    protected function handle(array $data, Request $request)
    {
        if (!Reviews::enabled()) {
            abort(404);
        }

        $this->token = (string) $request->route('token');
        $this->order = $this->reviews->orderByToken($this->token);

        if (!$this->order) {
            abort(404);
        }

        $this->product = $this->order->product;

        if ($this->reviews->existsFor($this->order)) {
            return $this->invalid($request, ['text' => ['Отзыв по этому заказу уже отправлен.']]);
        }

        $this->reviews->submit($this->order, [
            'author_name' => (string) $data['author_name'],
            'rating' => (int) $data['rating'],
            'title' => (string) ($data['title'] ?? ''),
            'text' => (string) $data['text'],
            'pros' => (string) ($data['pros'] ?? ''),
            'cons' => (string) ($data['cons'] ?? ''),
        ], AntiSpam::ip($request));

        return null;
    }

    protected function fallbackPath(): string
    {
        return '/review/' . $this->token . '/';
    }

    protected function anchor(): string
    {
        return 'review-form';
    }

    protected function done(Request $request, array $extra = [])
    {
        if ($request->expectsJson()) {
            return response()->json(array_merge(['ok' => true], $extra), 201);
        }

        // Токен гасится после отправки, поэтому возврат — на страницу продукта:
        // ссылка на отзыв к этому моменту уже недействительна.
        $url = $this->product ? (string) $this->product->url : rtrim(url('/'), '/') . '/';

        return redirect($url . '?review=sent#reviews')
            ->with('elv_success', 'Спасибо! Отзыв отправлен на проверку — опубликуем после модерации.');
    }
}
