<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 機械用のトークン（MachineTokens）。対象ごとに1行で、今・入れ替え待ち・前の鍵を SHA-256 だけで持つ。
 * 時刻は dateTime に UTC で書く（timestamp は MySQL で接続の時間帯の変換が入り、timestampTz は MySQL にない）。
 * ハッシュの列の一意の索引は、null どうしを同じと見ない（3つの DB とも）
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

        Schema::create('foundation_machine_tokens', function (Blueprint $table) use ($exact) {
            $table->id();
            $kind = $table->string('kind', 64);
            $subject = $table->string('subject', 255);
            if ($exact !== null) {
                $kind->charset('utf8mb4')->collation($exact);
                $subject->charset('utf8mb4')->collation($exact);
            }
            $table->char('current_hash', 64)->unique();
            $table->dateTime('current_issued_at');
            $table->char('pending_hash', 64)->nullable()->unique();
            $table->dateTime('pending_issued_at')->nullable();
            $table->char('previous_hash', 64)->nullable()->unique();
            $table->dateTime('previous_until')->nullable();
            $table->dateTime('last_used_at')->nullable();
            $table->dateTime('created_at');

            $table->unique(['kind', 'subject']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_machine_tokens');
    }
};
