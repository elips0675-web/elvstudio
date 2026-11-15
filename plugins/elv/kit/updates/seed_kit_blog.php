<?php

use Elv\Kit\Classes\Demo;
use October\Rain\Database\Updates\Migration;

/**
 * Блог: тридцать восемь статей и четыре рубрики. Сид идемпотентный,
 * поэтому существующие записи не дублируются, а тексты демо-статей
 * обновляются до актуальной версии.
 */
return new class extends Migration
{
    public function up()
    {
        Demo::seed(true);
    }

    public function down()
    {
    }
};
