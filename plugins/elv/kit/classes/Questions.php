<?php namespace Elv\Kit\Classes;

use Elv\Kit\Classes\Mail\QuestionAdmin;
use Elv\Kit\Models\Product;
use Elv\Kit\Models\Question;
use Elv\Kit\Models\Settings;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Questions — публичные вопросы к продукту и их модерация.
 *
 * Вопрос сохраняется сразу, письмо администратору ставится после записи.
 * На витрину вопрос попадает только после ответа: `answer()` сам ставит
 * `published`, потому что ответ и есть модерация.
 */
class Questions
{
    public static function enabled(): bool
    {
        return filter_var(Settings::get('qa_enabled', true), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * published — вопросы продукта, которые можно показывать.
     *
     * @return \October\Rain\Database\Collection<int, Question>
     */
    public static function published(Product $product, int $limit = 0)
    {
        $query = Question::query()
            ->published()
            ->where('product_id', (int) $product->id);

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public static function count(Product $product): int
    {
        return Question::query()
            ->where('product_id', (int) $product->id)
            ->where('status', Question::STATUS_PUBLISHED)
            ->whereNotNull('answer')
            ->where('answer', '<>', '')
            ->count();
    }

    /**
     * ask — новый вопрос. Статус `new`, на витрине его ещё нет.
     *
     * @param  array{name: string, email: string, question: string}  $data
     */
    public function ask(Product $product, array $data, string $ip = ''): Question
    {
        $question = new Question();
        $question->fill([
            'product_id' => (int) $product->id,
            'name' => (string) $data['name'],
            'email' => mb_strtolower((string) $data['email']),
            'question' => (string) $data['question'],
            'status' => Question::STATUS_NEW,
            'ip_hash' => Community::ipHash($ip),
        ]);
        $question->save();

        $this->notifyAdmin($question);

        return $question;
    }

    /**
     * answer — ответ поддержки. Публикует вопрос.
     */
    public function answer(Question $question, string $answer, string $author = 'Поддержка'): Question
    {
        $answer = trim($answer);

        if ($answer === '') {
            return $question;
        }

        $question->answer = $answer;
        $question->answered_by = mb_substr($author, 0, 160);
        $question->answered_at = now();
        $question->status = Question::STATUS_PUBLISHED;
        $question->published_at = now();
        $question->save();

        return $question->fresh();
    }

    public function reject(Question $question): Question
    {
        $question->status = Question::STATUS_REJECTED;
        $question->published_at = null;
        $question->save();

        return $question->fresh();
    }

    protected function notifyAdmin(Question $question): void
    {
        $admin = (string) (Settings::get('order_notify_email') ?: Settings::get('email'));

        if ($admin === '') {
            Log::warning('Elv Kit: некуда отправить письмо о новом вопросе', ['question' => $question->id]);

            return;
        }

        try {
            Mail::to($admin)->queue(new QuestionAdmin($question));
        }
        catch (\Throwable $e) {
            Log::warning('Elv Kit: письмо о новом вопросе не отправлено', [
                'question' => $question->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
