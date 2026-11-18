<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * メールアドレスの変更と戻すリンク（Accounts\EmailChanges）。トークンは SHA-256 だけを持つ。
 * 時刻は dateTime に UTC で書く（timestamp は MySQL で接続の時間帯の変換が入り、timestampTz は MySQL にない）。
 * 部分の索引を使わない（MySQL にない）。まだ確かめていない頼みを1つにするのは、頼むときの取り消しで行う
 */
return new class extends Migration
{
    public function up(): void
    {
        // MySQL・MariaDB の既定の照合順序は、大文字・小文字・アクセント（・後ろの空白）を区別せずに比べる。
        // アカウントの種類と id は、字のとおりに比べる照合順序にする（OneTimeCodes の kind・subject と同じ）
        $exact = match (Schema::getConnection()->getDriverName()) {
            'mysql' => 'utf8mb4_0900_bin',
            'mariadb' => 'utf8mb4_nopad_bin',
            default => null,
        };

        Schema::create('foundation_email_changes', function (Blueprint $table) use ($exact) {
            $table->id();
            $type = $table->string('account_type', 255);
            $id = $table->string('account_id', 64);
            if ($exact !== null) {
                $type->charset('utf8mb4')->collation($exact);
                $id->charset('utf8mb4')->collation($exact);
            }
            $table->string('old_email', 255);
            $table->string('new_email', 255);
            // 押さえの比べ（EmailKey の値）
            $table->string('old_email_key', 64);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('undone_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            // null は重なってよい（PostgreSQL・MySQL・SQLite とも）
            $table->char('undo_token_hash', 64)->nullable()->unique();
            $table->dateTime('undo_expires_at')->nullable();
            $table->json('snapshot')->nullable();
            $table->dateTime('created_at');

            $table->index(['account_type', 'account_id']);
            $table->index('old_email_key');
            $table->index('undo_expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_email_changes');
    }
};
