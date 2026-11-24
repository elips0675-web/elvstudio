<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Поддержка: вложения в переписке обращений.
 *
 * `attachments` — JSON-список файлов сообщения: имя, размер, расширение и
 * случайное имя на диске. Сами файлы лежат в `storage/app/elv-kit/tickets/`,
 * вне публичной папки, поэтому прямой ссылки на них нет: скачивание идёт
 * через `/tickets/{id}/file/{name}` с проверкой прав.
 */
return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('elv_kit_ticket_messages') && !Schema::hasColumn('elv_kit_ticket_messages', 'attachments')) {
            Schema::table('elv_kit_ticket_messages', function (Blueprint $table) {
                $table->text('attachments')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasTable('elv_kit_ticket_messages') && Schema::hasColumn('elv_kit_ticket_messages', 'attachments')) {
            Schema::table('elv_kit_ticket_messages', function (Blueprint $table) {
                $table->dropColumn('attachments');
            });
        }
    }
};
