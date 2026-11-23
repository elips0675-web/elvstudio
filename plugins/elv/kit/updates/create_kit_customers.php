<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Кабинет покупателя: клиенты, видимые домены и отметка отвязки.
 *
 * `elv_kit_customers` — вход по одноразовой ссылке, без паролей. Строки
 * создаются из заказов: у существующего магазина покупатели уже есть, и
 * заставлять их регистрироваться заново нельзя.
 *
 * `elv_kit_installs.domain` хранит домен в открытом виде только для показа
 * владельцу в кабинете; уникальность по-прежнему считается по `domain_hash`.
 * `elv_kit_licenses.domain_changed_at` — отметка отвязки: не чаще раза в 30 дней.
 */
return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_customers')) {
            Schema::create('elv_kit_customers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('email', 191)->unique();
                $table->string('name', 160)->nullable();
                $table->string('phone', 32)->nullable();
                $table->string('company', 191)->nullable();
                $table->string('login_token', 64)->nullable()->unique();
                $table->dateTime('login_token_expires_at')->nullable();
                $table->dateTime('last_login_at')->nullable();
                $table->timestamps();

                $table->index('last_login_at');
            });
        }

        if (Schema::hasTable('elv_kit_installs') && !Schema::hasColumn('elv_kit_installs', 'domain')) {
            Schema::table('elv_kit_installs', function (Blueprint $table) {
                $table->string('domain', 191)->nullable()->after('domain_hash');
            });
        }

        if (Schema::hasTable('elv_kit_licenses') && !Schema::hasColumn('elv_kit_licenses', 'domain_changed_at')) {
            Schema::table('elv_kit_licenses', function (Blueprint $table) {
                $table->dateTime('domain_changed_at')->nullable()->after('last_seen_at');
            });
        }

        if (Schema::hasTable('elv_kit_orders') && Schema::hasTable('elv_kit_customers')) {
            DB::table('elv_kit_orders')
                ->select(['email', 'customer_name', 'phone', 'company', 'created_at'])
                ->orderByDesc('id')
                ->chunk(200, function ($orders) {
                    $rows = [];

                    foreach ($orders as $order) {
                        $email = mb_strtolower(trim((string) ($order->email ?? '')));

                        if ($email === '' || isset($rows[$email])) {
                            continue;
                        }

                        $rows[$email] = [
                            'email' => $email,
                            'name' => trim((string) ($order->customer_name ?? '')) ?: null,
                            'phone' => trim((string) ($order->phone ?? '')) ?: null,
                            'company' => trim((string) ($order->company ?? '')) ?: null,
                            'created_at' => $order->created_at ?: now(),
                            'updated_at' => now(),
                        ];
                    }

                    if ($rows !== []) {
                        DB::table('elv_kit_customers')->insertOrIgnore(array_values($rows));
                    }
                });
        }
    }

    public function down()
    {
        if (Schema::hasTable('elv_kit_licenses') && Schema::hasColumn('elv_kit_licenses', 'domain_changed_at')) {
            Schema::table('elv_kit_licenses', function (Blueprint $table) {
                $table->dropColumn('domain_changed_at');
            });
        }

        if (Schema::hasTable('elv_kit_installs') && Schema::hasColumn('elv_kit_installs', 'domain')) {
            Schema::table('elv_kit_installs', function (Blueprint $table) {
                $table->dropColumn('domain');
            });
        }

        Schema::dropIfExists('elv_kit_customers');
    }
};
