<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Question;
use October\Rain\Mail\Mailable;

/**
 * QuestionAdmin — письмо администратору о новом вопросе.
 */
class QuestionAdmin extends Mailable
{
    public function __construct(public Question $question)
    {
    }

    public function build()
    {
        return $this->subject('Новый вопрос о продукте')
            ->view('elv.kit::mail.question-admin')
            ->with([
                'question' => [
                    'product' => (string) ($this->question->product->name ?? ''),
                    'name' => (string) $this->question->name,
                    'email' => (string) $this->question->email,
                    'text' => (string) $this->question->question,
                    'url' => \Backend::url('elv/kit/questions/update/' . (int) $this->question->id),
                ],
            ]);
    }
}
