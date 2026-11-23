<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Поддержка: обращения покупателей и переписка по ним.
 *
 * `elv_kit_tickets` — обращение с номером, статусом и сроком первого ответа.
 * `elv_kit_ticket_messages` — сообщения покупателя и поддержки: одно обращение
 * не теряет историю при смене статуса.
 *
 * Почта хранится в обращении отдельно от `customer_id`: покупатель может
 * написать с другого адреса, а поддержка всё равно найдёт обращение поиском.
 * Внешних ключей нет: таблицы создаются в одном обновлении, а покупатель и
 * заказ могли быть удалены раньше обращения.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_tickets')) {
            Schema::create('elv_kit_tickets', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('number', 32)->nullable()->unique();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('email', 191);
                $table->unsignedBigInteger('order_id')->nullable();
                $table->string('subject', 200);
                $table->string('status', 20)->default('new');
                $table->string('priority', 20)->default('normal');
                $table->string('source', 32)->default('account');
                $table->dateTime('sla_due_at')->nullable();
                $table->dateTime('first_response_at')->nullable();
                $table->dateTime('closed_at')->nullable();
                $table->timestamps();

                $table->index('customer_id');
                $table->index('email');
                $table->index('order_id');
                $table->index(['status', 'sla_due_at']);
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('elv_kit_ticket_messages')) {
            Schema::create('elv_kit_ticket_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('ticket_id');
                $table->string('author', 20)->default('customer');
                $table->string('author_name', 160)->nullable();
                $table->text('body');
                $table->timestamps();

                $table->index(['ticket_id', 'id']);
            });
        }
    }

    public function down()
    {
        Schema::dropIfExists('elv_kit_ticket_messages');
        Schema::dropIfExists('elv_kit_tickets');
    }
};
