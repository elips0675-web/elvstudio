<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('elv_kit_bookings') && !Schema::hasColumn('elv_kit_bookings', 'admin_reminded_at')) {
            Schema::table('elv_kit_bookings', function (Blueprint $table) {
                $table->dateTime('admin_reminded_at')->nullable()->after('reminded_at');
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('elv_kit_bookings') && Schema::hasColumn('elv_kit_bookings', 'admin_reminded_at')) {
            Schema::table('elv_kit_bookings', function (Blueprint $table) {
                $table->dropColumn('admin_reminded_at');
            });
        }
    }
};
