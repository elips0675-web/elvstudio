<?php namespace Elv\Kit\Models;

use Model;

/**
 * Install — активированный домен лицензии. Уникален в рамках лицензии.
 */
class Install extends Model
{
    public $table = 'elv_kit_installs';

    public $fillable = [
        'license_id', 'product_slug', 'domain_hash', 'domain', 'app_version',
    ];

    /**
     * getDomainLabelAttribute — домен для кабинета.
     *
     * У активаций до 1.1.0 домен не сохранялся: в базе только хэш, и
     * показать его нельзя. Врать «домен скрыт» без пояснения не нужно.
     */
    public function getDomainLabelAttribute(): string
    {
        $domain = trim((string) $this->domain);

        return $domain !== '' ? $domain : 'домен не сохранён (активация до обновления)';
    }

    public $belongsTo = [
        'license' => [License::class, 'key' => 'license_id'],
    ];
}
