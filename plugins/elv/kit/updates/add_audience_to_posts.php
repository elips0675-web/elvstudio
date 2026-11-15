<?php

use Elv\Kit\Classes\Demo;
use October\Rain\Database\Updates\Migration;

/**
 * Аудитория статьи: пользовательский блог или технический раздел.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('elv_kit_posts', 'audience')) {
            Schema::table('elv_kit_posts', function ($table) {
                $table->string('audience', 20)->default('business')->after('category_id');
                $table->index(['audience', 'is_published', 'published_at'], 'elv_kit_posts_audience_published');
            });
        }

        DB::table('elv_kit_posts')->update(['audience' => 'business']);

        $tech = Demo::techPostSlugs();

        if ($tech !== []) {
            DB::table('elv_kit_posts')->whereIn('slug', $tech)->update(['audience' => 'tech']);
        }
    }

    public function down()
    {
        if (!Schema::hasColumn('elv_kit_posts', 'audience')) {
            return;
        }

        Schema::table('elv_kit_posts', function ($table) {
            $table->dropIndex('elv_kit_posts_audience_published');
            $table->dropColumn('audience');
        });
    }
};
