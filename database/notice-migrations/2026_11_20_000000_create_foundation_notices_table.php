<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 知らせを1回だけ送る印（Notices\OnceNotices）。鍵は SHA-256 で一意にする（255字の鍵に一意の索引を張らない。
 * MySQL の索引の長さの上限と、照合順序で大文字・小文字を同じとみなすことを避ける）。
 * 時刻は dateTime に UTC で書く（timestamp は MySQL で接続の時間帯の変換が入る）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_notices', function (Blueprint $table) {
            $table->id();
            // 読むための鍵（記録と調べ）。比べるのは key_hash
            $table->string('key', 255);
            $table->char('key_hash', 64)->unique();
            // 取った処理の印。自分の token のときだけ、送った・戻す・延ばすが効く
            $table->char('token', 32);
            $table->dateTime('claimed_at');
            $table->dateTime('sent_at')->nullable();
            $table->dateTime('keep_until');

            $table->index('keep_until');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_notices');
    }
};
