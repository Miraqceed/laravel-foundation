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
        // MySQL・MariaDB の既定の照合順序は、大文字・小文字・アクセント（・後ろの空白）を区別せずに比べる。
        // 種類と対象は、字のとおりに比べる照合順序にする（utf8mb4_bin は後ろの空白を区別しないので使わない）
        $exact = match (Schema::getConnection()->getDriverName()) {
            'mysql' => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_nopad_bin',
            default => null,
        };

        Schema::create('foundation_one_time_codes', function (Blueprint $table) use ($exact) {
            $table->id();
            $kind = $table->string('kind', 64);
            $table->char('code_hash', 64)->unique();
            $subject = $table->string('subject', 255)->nullable();
            if ($exact !== null) {
                $kind->charset('utf8mb4')->collation($exact);
                $subject->charset('utf8mb4')->collation($exact);
            }
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
