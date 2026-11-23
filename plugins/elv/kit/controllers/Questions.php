<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Elv\Kit\Classes\Questions as QuestionService;
use Elv\Kit\Models\Question;
use Flash;
use October\Rain\Exception\ValidationException;

/**
 * Вопросы — премодерация блока «Вопрос-ответ».
 *
 * Публикация — это ответ: `Questions::answer()` ставит статус `published`
 * и дату, поэтому отдельной кнопки «опубликовать» нет. Отклонённый вопрос
 * остаётся в базе, но на витрину не попадает.
 */
class Questions extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.questions'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'questions');
    }

    public function onPublish()
    {
        $question = $this->questionFromRequest();
        $answer = trim((string) post('answer'));

        if (mb_strlen($answer) < 2) {
            throw new ValidationException(['answer' => 'Напишите ответ: он публикует вопрос.']);
        }

        (new QuestionService())->answer($question, $answer, $this->authorName());

        Flash::success('Ответ сохранён, вопрос опубликован.');

        return \Backend::redirect('elv/kit/questions/update/' . (int) $question->id);
    }

    public function onReject()
    {
        $question = $this->questionFromRequest();

        (new QuestionService())->reject($question);

        Flash::success('Вопрос отклонён и снят с витрины.');

        return \Backend::redirect('elv/kit/questions/update/' . (int) $question->id);
    }

    protected function questionFromRequest(): Question
    {
        $question = Question::query()->find((int) post('id'));

        if (!$question) {
            throw new \ApplicationException('Вопрос не найден.');
        }

        return $question;
    }

    protected function authorName(): string
    {
        $user = BackendAuth::getUser();

        return $user ? (string) ($user->login ?: $user->email) : 'Поддержка';
    }
}
