<?php namespace Elv\Kit\Controllers;

use Backend\Behaviors\FormController;
use Backend\Behaviors\ListController;
use Backend\Classes\Controller;
use BackendAuth;
use BackendMenu;
use Elv\Kit\Classes\TicketService;
use Elv\Kit\Models\Ticket;
use Flash;
use October\Rain\Exception\ValidationException;

/**
 * Обращения — раздел поддержки в админке.
 *
 * Ответ и закрытие идут через `TicketService`: он же ставит письмо покупателю.
 * После действия страница перезагружается, чтобы статус и история совпадали.
 */
class Tickets extends Controller
{
    public $implement = [
        ListController::class,
        FormController::class,
    ];

    public $listConfig = 'config_list.yaml';
    public $formConfig = 'config_form.yaml';

    public $requiredPermissions = ['elv.kit.tickets'];

    public function __construct()
    {
        parent::__construct();

        BackendMenu::setContext('Elv.Kit', 'kit', 'tickets');
    }

    public function update($recordId = null, $context = null)
    {
        $this->vars['quickReplies'] = TicketService::quickReplies();

        return parent::update($recordId, $context);
    }

    public function onReply()
    {
        $ticket = $this->ticketFromRequest();
        $body = trim((string) post('body'));

        if (mb_strlen($body) < 2) {
            throw new ValidationException(['body' => 'Напишите ответ покупателю.']);
        }

        (new TicketService())->supportReply($ticket, $body, $this->authorName());

        Flash::success('Ответ отправлен покупателю.');

        return \Backend::redirect('elv/kit/tickets/update/' . (int) $ticket->id);
    }

    public function onClose()
    {
        $ticket = $this->ticketFromRequest();

        (new TicketService())->close($ticket, $this->authorName());

        Flash::success('Обращение закрыто.');

        return \Backend::redirect('elv/kit/tickets/update/' . (int) $ticket->id);
    }

    protected function ticketFromRequest(): Ticket
    {
        $ticket = Ticket::query()->find((int) post('id'));

        if (!$ticket) {
            throw new \ApplicationException('Обращение не найдено.');
        }

        return $ticket;
    }

    protected function authorName(): string
    {
        $user = BackendAuth::getUser();

        return $user ? (string) ($user->login ?: $user->email) : 'Поддержка';
    }
}
