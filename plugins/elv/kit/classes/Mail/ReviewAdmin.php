<?php namespace Elv\Kit\Classes\Mail;

use Elv\Kit\Models\Review;
use October\Rain\Mail\Mailable;

/**
 * ReviewAdmin — письмо администратору о новом отзыве на модерацию.
 */
class ReviewAdmin extends Mailable
{
    public function __construct(public Review $review)
    {
    }

    public function build()
    {
        return $this->subject('Новый отзыв на модерацию')
            ->view('elv.kit::mail.review-admin')
            ->with([
                'review' => [
                    'product' => (string) ($this->review->product->name ?? ''),
                    'author' => (string) $this->review->author_name,
                    'rating' => (int) $this->review->rating,
                    'title' => (string) $this->review->title,
                    'text' => (string) $this->review->text,
                    'url' => \Backend::url('elv/kit/reviews/update/' . (int) $this->review->id),
                ],
            ]);
    }
}
