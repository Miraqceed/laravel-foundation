<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 1回だけ使える合言葉（OneTimeCodes）。合言葉は SHA-256 だけを持つ。
 * 時刻は dateTime に UTC で書く（timestamp は MySQL で接続の時間帯の変換が入り、timestampTz は MySQL にない）。
 * 部分の索引を使わない（MySQL にない）。同じ対象に効くものを1つにするのは、出すときの失効で行う
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_one_time_codes', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 64);
            $table->char('code_hash', 64)->unique();
            $table->string('subject', 255)->nullable();
            $table->json('data')->nullable();
            $table->dateTime('expires_at');
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->dateTime('created_at');

            $table->index(['kind', 'subject']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_one_time_codes');
    }
};
