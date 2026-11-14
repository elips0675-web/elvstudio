<?php namespace Elv\Kit\Models;

use Model;

/**
 * Category — рубрика блога.
 */
class Category extends Model
{
    use \October\Rain\Database\Traits\Validation;

    public $table = 'elv_kit_categories';

    public $rules = [
        'name' => 'required|max:120',
        'slug' => 'required|max:120|unique:elv_kit_categories,slug',
    ];

    public $hasMany = [
        'posts' => [Post::class, 'key' => 'category_id'],
    ];

    public function getUrlAttribute(): string
    {
        return url('/blog/?category=' . $this->slug);
    }
}
