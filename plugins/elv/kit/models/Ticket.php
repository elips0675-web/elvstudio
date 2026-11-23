<?php namespace Elv\Kit\Models;

use Elv\Kit\Classes\Support;
use Model;

/**
 * Ticket — обращение покупателя в поддержку.
 *
 * Номер вида `TK-000123` присваивается после вставки: он нужен в письмах,
 * Telegram и админке, но не должен зависеть от ввода покупателя. Срок первого
 * ответа считается по рабочим часам (`Support::dueAt()`).
 */
class Ticket extends Model
{
    public const STATUS_NEW = 'new';
    public const STATUS_OPEN = 'open';
    public const STATUS_WAITING = 'waiting';
    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';
    public const PRIORITY_NORMAL = 'normal';
    public const PRIORITY_HIGH = 'high';

    public const AUTHOR_CUSTOMER = 'customer';
    public const AUTHOR_SUPPORT = 'support';
    public const AUTHOR_SYSTEM = 'system';

    public $table = 'elv_kit_tickets';

    public $fillable = [
        'number', 'customer_id', 'email', 'order_id', 'subject',
        'status', 'priority', 'source', 'sla_due_at', 'first_response_at', 'closed_at',
    ];

    public $rules = [
        'email' => 'required|email|max:191',
        'subject' => 'required|max:200',
        'status' => 'required|in:new,open,waiting,closed',
        'priority' => 'required|in:low,normal,high',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'first_response_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public $belongsTo = [
        'customer' => Customer::class,
        'order' => Order::class,
    ];

    public $hasMany = [
        'messages' => [TicketMessage::class, 'key' => 'ticket_id', 'order' => 'id asc'],
    ];

    protected static function booted()
    {
        static::creating(function (self $ticket) {
            if (!$ticket->sla_due_at) {
                $ticket->sla_due_at = Support::dueAt(now());
            }
        });

        static::created(function (self $ticket) {
            if (trim((string) $ticket->number) === '') {
                $ticket->number = 'TK-' . str_pad((string) $ticket->id, 6, '0', STR_PAD_LEFT);
                $ticket->saveQuietly();
            }
        });
    }

    /**
     * forCustomer — обращения покупателя по id или e-mail.
     */
    public function scopeForCustomer($query, Customer $customer)
    {
        return $query->where(function ($inner) use ($customer) {
            $inner->where('customer_id', (int) $customer->id)
                ->orWhere('email', $customer->email);
        });
    }

    public function scopeUnanswered($query)
    {
        return $query->whereIn('status', [self::STATUS_NEW, self::STATUS_OPEN]);
    }

    public function getStatusLabelAttribute(): string
    {
        return match ((string) $this->status) {
            self::STATUS_OPEN => 'В работе',
            self::STATUS_WAITING => 'Ждём ответа',
            self::STATUS_CLOSED => 'Закрыто',
            default => 'Новое',
        };
    }

    public function getPriorityLabelAttribute(): string
    {
        return match ((string) $this->priority) {
            self::PRIORITY_HIGH => 'Высокий',
            self::PRIORITY_LOW => 'Низкий',
            default => 'Обычный',
        };
    }

    public function getIsClosedAttribute(): bool
    {
        return (string) $this->status === self::STATUS_CLOSED;
    }

    public function getUrlAttribute(): string
    {
        // url() срезает слэш на конце, поэтому канонический адрес собирается
        // из двух частей: иначе каждая ссылка на обращение даёт 301.
        return url('/tickets') . '/?id=' . (int) $this->id;
    }

    public function getSlaLabelAttribute(): string
    {
        return Support::describe($this);
    }

    public function getSlaOverdueAttribute(): bool
    {
        return Support::isOverdue($this);
    }

    public function getLastMessageAtAttribute()
    {
        $message = $this->messages->last();

        return $message ? $message->created_at : $this->created_at;
    }

}
