<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_releases')) {
            Schema::create('elv_kit_releases', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('product_slug', 160)->default('elv-kit');
                $table->string('version', 32);
                $table->string('channel', 16)->default('stable');
                $table->string('file', 255);
                $table->unsignedBigInteger('size')->default(0);
                $table->string('checksum', 64)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_demo')->default(false);
                $table->boolean('is_current')->default(false);
                $table->dateTime('published_at')->nullable();
                $table->timestamps();

                $table->unique(['product_slug', 'version', 'is_demo'], 'elv_kit_release_unique');
                $table->index(['product_slug', 'is_demo', 'is_current'], 'elv_kit_release_current');
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('elv_kit_releases');
    }
};
