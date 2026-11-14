<?php namespace Elv\Kit\Models;

use Model;

/**
 * Install — активированный домен лицензии. Уникален в рамках лицензии.
 */
class Install extends Model
{
    public $table = 'elv_kit_installs';

    public $fillable = [
        'license_id', 'product_slug', 'domain_hash', 'app_version',
    ];

    public $belongsTo = [
        'license' => [License::class, 'key' => 'license_id'],
    ];
}
