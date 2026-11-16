<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 二段階の認証（手書きのアプリ向けの TwoFactor）の3つの列を、利用者の表に足す。無い列だけを足す（Fortify の2つの列が既にあっても落ちない）。
 * 列の名前は foundation.two_factor.columns。秘密の鍵は暗号にした文で入る（平文では置かない）
 */
return new class extends Migration
{
    public function up(): void
    {
        $table = config('foundation.two_factor.table', 'users');
        $columns = config('foundation.two_factor.columns');

        Schema::table($table, function (Blueprint $blueprint) use ($table, $columns) {
            if (! Schema::hasColumn($table, $columns['secret'])) {
                $blueprint->text($columns['secret'])->nullable();
            }
            if (! Schema::hasColumn($table, $columns['confirmed_at'])) {
                $blueprint->timestamp($columns['confirmed_at'])->nullable();
            }
            if (! Schema::hasColumn($table, $columns['last_step'])) {
                // 最後に受けた確認コードの刻み（Unix 時刻 ÷ 30）。これ以下の刻みのコードは使い回しとして断る
                $blueprint->bigInteger($columns['last_step'])->nullable();
            }
        });
    }

    public function down(): void
    {
        $table = config('foundation.two_factor.table', 'users');
        $columns = config('foundation.two_factor.columns');

        // last_step だけを消す（secret・confirmed_at は Fortify の列でもありうるので残す）
        if (Schema::hasColumn($table, $columns['last_step'])) {
            Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropColumn($columns['last_step']));
        }
    }
};
