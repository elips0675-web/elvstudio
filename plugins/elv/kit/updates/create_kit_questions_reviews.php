<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * «Вопрос-ответ» и отзывы покупателей.
 *
 * `elv_kit_questions` — вопрос к продукту. Публикуется только после ответа
 * и модерации: `status = published` ставит админка, а не отправитель.
 * `elv_kit_reviews` — отзыв с оценкой 1–5. Собирается по одноразовой ссылке
 * из письма, поэтому в заказе хранится `review_token`.
 *
 * Внешних ключей нет: продукт, заказ и покупатель могли быть удалены раньше
 * записи, а история вопроса или отзыва должна остаться.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_questions')) {
            Schema::create('elv_kit_questions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('name', 160);
                $table->string('email', 191);
                $table->text('question');
                $table->text('answer')->nullable();
                $table->string('answered_by', 160)->nullable();
                $table->string('status', 20)->default('new');
                $table->boolean('is_pinned')->default(false);
                $table->integer('sort_order')->default(0);
                $table->dateTime('answered_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamps();

                $table->index('product_id');
                $table->index('customer_id');
                $table->index(['status', 'published_at']);
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('elv_kit_reviews')) {
            Schema::create('elv_kit_reviews', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedBigInteger('customer_id')->nullable();
                $table->string('author_name', 160);
                $table->string('email_hash', 64)->nullable();
                $table->unsignedTinyInteger('rating')->default(5);
                $table->string('title', 160)->nullable();
                $table->text('text');
                $table->text('pros')->nullable();
                $table->text('cons')->nullable();
                $table->string('status', 20)->default('pending');
                $table->boolean('is_verified')->default(false);
                $table->text('reply')->nullable();
                $table->dateTime('replied_at')->nullable();
                $table->dateTime('published_at')->nullable();
                $table->string('ip_hash', 64)->nullable();
                $table->timestamps();

                $table->unique(['order_id', 'product_id']);
                $table->index('product_id');
                $table->index(['status', 'published_at']);
                $table->index('created_at');
            });
        }

        if (!Schema::hasColumn('elv_kit_orders', 'review_token')) {
            Schema::table('elv_kit_orders', function (Blueprint $table) {
                $table->string('review_token', 48)->nullable()->unique();
                $table->dateTime('review_token_expires_at')->nullable();
                $table->dateTime('review_invited_at')->nullable();
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('elv_kit_orders', 'review_token')) {
            Schema::table('elv_kit_orders', function (Blueprint $table) {
                $table->dropUnique(['review_token']);
                $table->dropColumn(['review_token', 'review_token_expires_at', 'review_invited_at']);
            });
        }

        Schema::dropIfExists('elv_kit_reviews');
        Schema::dropIfExists('elv_kit_questions');
    }
};
