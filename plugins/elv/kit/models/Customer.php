<?php namespace Elv\Kit\Models;

use Carbon\Carbon;
use Model;

/**
 * Customer — покупатель Elv Kit для личного кабинета.
 *
 * Пароля нет: вход только по одноразовой ссылке из письма. Токен живёт
 * 30 минут и гасится при первом переходе, поэтому ссылка из старого письма
 * не открывает кабинет повторно.
 */
class Customer extends Model
{
    public const TOKEN_TTL_MINUTES = 30;

    public $table = 'elv_kit_customers';

    public $fillable = [
        'email', 'name', 'phone', 'company',
        'login_token', 'login_token_expires_at', 'last_login_at',
    ];

    protected $casts = [
        'login_token_expires_at' => 'datetime',
        'last_login_at' => 'datetime',
    ];

    /**
     * newToken — 48 символов: 24 байта в hex.
     */
    public static function newToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /**
     * issueLoginToken — новый одноразовый токен и срок жизни.
     */
    public function issueLoginToken(int $minutes = self::TOKEN_TTL_MINUTES): string
    {
        $this->login_token = self::newToken();
        $this->login_token_expires_at = now()->addMinutes(max(5, min(120, $minutes)));
        $this->save();

        return $this->login_token;
    }

    public function clearLoginToken(): void
    {
        $this->login_token = null;
        $this->login_token_expires_at = null;
    }

    public function hasValidLoginToken(): bool
    {
        return (string) $this->login_token !== ''
            && $this->login_token_expires_at instanceof Carbon
            && $this->login_token_expires_at->isFuture();
    }

    public function magicLink(): string
    {
        return url('/account/auth/' . $this->login_token);
    }

    public function getDisplayNameAttribute(): string
    {
        $name = trim((string) $this->name);

        return $name !== '' ? $name : (string) $this->email;
    }

    public function getShortNameAttribute(): string
    {
        $name = trim((string) $this->name);

        if ($name === '') {
            return (string) $this->email;
        }

        $parts = preg_split('~\s+~u', $name) ?: [];
        $first = (string) ($parts[0] ?? '');
        $initial = '';

        if (isset($parts[1]) && $parts[1] !== '') {
            $initial = mb_strtoupper(mb_substr($parts[1], 0, 1)) . '.';
        }

        return trim($first . ' ' . $initial);
    }
}
