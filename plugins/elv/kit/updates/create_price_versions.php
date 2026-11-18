<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_price_versions')) {
            Schema::create('elv_kit_price_versions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_slug', 160);
                $table->string('plan', 20)->default('single');
                $table->unsignedInteger('price_cents')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->date('valid_from');
                $table->date('valid_to')->nullable();
                $table->boolean('is_active')->default(true);
                $table->string('note', 255)->nullable();
                $table->unsignedInteger('created_by')->nullable();
                $table->timestamps();

                $table->index(['product_slug', 'plan', 'is_active'], 'elv_kit_prices_lookup');
                $table->index(['valid_from', 'valid_to'], 'elv_kit_prices_period');
            });
        }

        $this->seedCurrentPrices();
    }

    public function down()
    {
        Schema::dropIfExists('elv_kit_price_versions');
    }

    protected function seedCurrentPrices()
    {
        if (!Schema::hasTable('elv_kit_products')) {
            return;
        }

        if (Db::table('elv_kit_price_versions')->count() > 0) {
            return;
        }

        $kit = Db::table('elv_kit_products')->where('kind', 'kit')->first();

        if (!$kit) {
            return;
        }

        $validFrom = $this->catalogDate();
        $currency = (string) (\Elv\Kit\Models\Settings::get('currency') ?: 'USD');
        $single = (int) $kit->price_cents;
        $rows = [
            ['plan' => 'single', 'price_cents' => $single],
            ['plan' => 'multi', 'price_cents' => (int) ($kit->price_multi_cents ?: $single)],
            ['plan' => 'extended', 'price_cents' => (int) ($kit->price_extended_cents ?: $single)],
        ];

        $now = date('Y-m-d H:i:s');

        foreach ($rows as $row) {
            Db::table('elv_kit_price_versions')->insert([
                'product_id' => $kit->id,
                'product_slug' => (string) $kit->slug,
                'plan' => $row['plan'],
                'price_cents' => $row['price_cents'],
                'currency' => $currency,
                'valid_from' => $validFrom,
                'valid_to' => null,
                'is_active' => true,
                'note' => 'Начальная версия: цена из каталога',
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    protected function catalogDate(): string
    {
        $label = (string) \Elv\Kit\Models\Settings::get('prices_updated_at');
        $parsed = $label !== '' ? date_create_from_format('d.m.Y', $label) : false;

        return $parsed ? $parsed->format('Y-m-d') : '2026-11-17';
    }
};
