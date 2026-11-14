<?php

use Elv\Kit\Classes\Demo;
use Illuminate\Support\Facades\DB;
use October\Rain\Database\Updates\Migration;

/**
 * Демо-контент: Elv Kit, модули, категории и три статьи.
 * Данные живут в `Classes\Demo`, чтобы команда `elv:kit:demo` не расходилась
 * с миграцией.
 */
return new class extends Migration
{
    public function up()
    {
        Demo::seed();
        Demo::slots();
    }

    public function down()
    {
        DB::table('elv_kit_posts')->truncate();
        DB::table('elv_kit_products')->truncate();
        DB::table('elv_kit_categories')->truncate();
    }
};
