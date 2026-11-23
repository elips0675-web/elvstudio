<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendMenu;
use Elv\Kit\Classes\Reviews as ReviewService;
use Elv\Kit\Models\Review;
use Flash;

/**
 * Отзывы — модерация отзывов покупателей.
 *
 * Отзыв приходит по одноразовой ссылке из письма и ждёт решения. Одобренный
 * отзыв показывается на странице продукта и попадает в `AggregateRating`;
 * отклонённый остаётся в базе, но на витрину не выходит.
 */
class Reviews extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.reviews'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'reviews');
    }

    public function onApprove()
    {
        $review = $this->reviewFromRequest();

        (new ReviewService())->approve($review);

        Flash::success('Отзыв одобрен и опубликован.');

        return \Backend::redirect('elv/kit/reviews/update/' . (int) $review->id);
    }

    public function onReject()
    {
        $review = $this->reviewFromRequest();

        (new ReviewService())->reject($review);

        Flash::success('Отзыв отклонён и снят с витрины.');

        return \Backend::redirect('elv/kit/reviews/update/' . (int) $review->id);
    }

    protected function reviewFromRequest(): Review
    {
        $review = Review::query()->find((int) post('id'));

        if (!$review) {
            throw new \ApplicationException('Отзыв не найден.');
        }

        return $review;
    }
}
