<?php

namespace Miraqceed\Foundation\Tests\Support\Retention;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Schema;

/**
 * 台帳の試しの表（作り物のアプリ）。DB のすべての表を数える試しなので、ほかの試しの表を消してから作る。
 * 試しの後に消し、次の RefreshDatabase の試しが移行し直すようにする
 */
trait CreatesRetentionTables
{
    protected function createRetentionTables(): void
    {
        $this->dropRetentionTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('plan')->default('free');
            $table->dateTime('created_at')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('title');
        });
        // 3段目：users ← posts ← post_images（外部キーの子から消さないと落ちる）
        Schema::create('post_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts');
            $table->string('path');
        });
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('email')->unique();
            $table->string('phone')->nullable();
            $table->string('label');
        });
        Schema::create('clicks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->dateTime('created_at');
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->longText('payload');
        });
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->unsignedInteger('amount');
        });
        // 残す表が、消す表（posts）を指す。行があれば posts を消せない（巻き戻しの試し）
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('posts');
            $table->string('body');
        });
        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('path');
        });
    }

    protected function createEmailChangeTable(): void
    {
        $migration = require __DIR__.'/../../../database/email-change-migrations/2026_11_19_000000_create_foundation_email_changes_table.php';
        $migration->up();
    }

    protected function dropRetentionTables(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropAllTables();
        Schema::enableForeignKeyConstraints();

        RefreshDatabaseState::$migrated = false;
    }
}
