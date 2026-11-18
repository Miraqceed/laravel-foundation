<?php

namespace Miraqceed\Foundation\Tests\Support;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メールアドレスの変更の試しの表：利用者・セッション・再設定のトークン・変更の表（パッケージのマイグレーション）。
 * 子のプロセス（並列の試し）からも見えるよう、トランザクションの外で作って、試しの後に消す
 */
trait CreatesEmailChangeTables
{
    protected function createEmailChangeTables(): void
    {
        $this->dropEmailChangeTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
        });
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        $migration = require __DIR__.'/../../database/email-change-migrations/2026_11_19_000000_create_foundation_email_changes_table.php';
        $migration->up();
    }

    protected function dropEmailChangeTables(): void
    {
        foreach (['foundation_email_changes', 'password_reset_tokens', 'sessions', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
