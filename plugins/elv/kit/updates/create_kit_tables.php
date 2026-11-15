<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

return new class extends Migration
{
    public function up()
    {
        if (!Schema::hasTable('elv_kit_categories')) {
            Schema::create('elv_kit_categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 120);
                $table->string('slug', 120)->unique();
                $table->string('description', 255)->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('elv_kit_products')) {
            Schema::create('elv_kit_products', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 160);
                $table->string('slug', 160)->unique();
                $table->string('kind', 20)->default('module');
                $table->string('tagline', 255)->nullable();
                $table->text('description')->nullable();
                $table->unsignedInteger('price_cents')->default(0);
                $table->unsignedInteger('price_multi_cents')->nullable();
                $table->unsignedInteger('price_extended_cents')->nullable();
                $table->string('badge', 60)->nullable();
                $table->string('icon', 40)->nullable();
                $table->string('demo_url', 255)->nullable();
                $table->string('docs_url', 255)->nullable();
                $table->json('features')->nullable();
                $table->json('compatibility')->nullable();
                $table->json('changelog')->nullable();
                $table->json('meta')->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();

                $table->index(['kind', 'is_active']);
                $table->index('sort_order');
            });
        }

        if (!Schema::hasTable('elv_kit_posts')) {
            Schema::create('elv_kit_posts', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('category_id')->nullable();
                $table->string('audience', 20)->default('business');
                $table->string('title', 200);
                $table->string('slug', 200)->unique();
                $table->string('author', 120)->nullable();
                $table->text('excerpt')->nullable();
                $table->longText('body')->nullable();
                $table->json('tags')->nullable();
                $table->unsignedSmallInteger('reading_time')->default(0);
                $table->string('cover', 40)->nullable();
                $table->string('seo_title', 255)->nullable();
                $table->string('seo_description', 320)->nullable();
                $table->boolean('is_published')->default(true);
                $table->dateTime('published_at')->nullable();
                $table->timestamps();

                $table->index(['audience', 'is_published', 'published_at'], 'elv_kit_posts_audience_published');
                $table->index(['is_published', 'published_at']);
                $table->index('category_id');
            });
        }

        if (!Schema::hasTable('elv_kit_orders')) {
            Schema::create('elv_kit_orders', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('order_no', 32)->unique();
                $table->unsignedBigInteger('product_id')->nullable();
                $table->string('product_name', 160);
                $table->string('plan', 20)->default('single');
                $table->unsignedSmallInteger('qty')->default(1);
                $table->unsignedInteger('amount_cents')->default(0);
                $table->string('currency', 8)->default('USD');
                $table->string('status', 20)->default('new');
                $table->string('customer_name', 160)->nullable();
                $table->string('email', 191);
                $table->string('phone', 32)->nullable();
                $table->string('company', 191)->nullable();
                $table->text('comment')->nullable();
                $table->string('payment_provider', 40)->nullable();
                $table->string('payment_id', 191)->nullable();
                $table->string('download_token', 64)->nullable()->unique();
                $table->dateTime('paid_at')->nullable();
                $table->dateTime('delivered_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('email');
                $table->index('payment_id');
            });
        }

        if (!Schema::hasTable('elv_kit_slots')) {
            Schema::create('elv_kit_slots', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('service_code', 64);
                $table->date('date');
                $table->string('time', 8);
                $table->unsignedSmallInteger('capacity')->default(1);
                $table->unsignedSmallInteger('taken')->default(0);
                $table->timestamps();

                $table->unique(['service_code', 'date', 'time'], 'elv_kit_slots_unique');
                $table->index(['service_code', 'date']);
            });
        }

        if (!Schema::hasTable('elv_kit_bookings')) {
            Schema::create('elv_kit_bookings', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('service_code', 64);
                $table->string('service', 160)->nullable();
                $table->string('price', 64)->nullable();
                $table->date('date');
                $table->string('time', 8);
                $table->string('person', 160);
                $table->string('phone', 32)->nullable();
                $table->string('email', 191)->nullable();
                $table->text('comment')->nullable();
                $table->string('status', 20)->default('new');
                $table->string('source', 64)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();

                $table->index(['status', 'date']);
                $table->index(['service_code', 'date']);
                $table->index('email');
            });
        }

        if (!Schema::hasTable('elv_kit_messages')) {
            Schema::create('elv_kit_messages', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('name', 160);
                $table->string('email', 191);
                $table->string('phone', 32)->nullable();
                $table->string('subject', 200)->nullable();
                $table->text('message');
                $table->string('status', 20)->default('new');
                $table->string('source', 64)->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();

                $table->index(['status', 'created_at']);
                $table->index('email');
            });
        }

        if (!Schema::hasTable('elv_kit_subscribers')) {
            Schema::create('elv_kit_subscribers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('email', 191)->unique();
                $table->string('token', 64)->unique();
                $table->dateTime('confirmed_at')->nullable();
                $table->dateTime('unsubscribed_at')->nullable();
                $table->string('ip', 45)->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('elv_kit_licenses')) {
            Schema::create('elv_kit_licenses', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('license_key', 64)->unique();
                $table->string('product_slug', 120);
                $table->string('email', 191)->nullable();
                $table->unsignedBigInteger('order_id')->nullable();
                $table->unsignedSmallInteger('domain_limit')->default(1);
                $table->string('status', 20)->default('active');
                $table->dateTime('activated_at')->nullable();
                $table->dateTime('last_seen_at')->nullable();
                $table->timestamps();

                $table->index(['product_slug', 'status']);
            });
        }

        if (!Schema::hasTable('elv_kit_installs')) {
            Schema::create('elv_kit_installs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('license_id');
                $table->string('product_slug', 120);
                $table->string('domain_hash', 64);
                $table->string('app_version', 32)->nullable();
                $table->timestamps();

                $table->unique(['license_id', 'domain_hash'], 'elv_kit_installs_unique');
                $table->index('product_slug');
            });
        }
    }

    public function down()
    {
        foreach ([
            'elv_kit_installs',
            'elv_kit_licenses',
            'elv_kit_subscribers',
            'elv_kit_messages',
            'elv_kit_bookings',
            'elv_kit_slots',
            'elv_kit_orders',
            'elv_kit_posts',
            'elv_kit_products',
            'elv_kit_categories',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
