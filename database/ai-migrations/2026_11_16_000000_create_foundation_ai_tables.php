<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 上限の知らせを送ったかの印（同じ鍵では1回だけ）
        Schema::create('foundation_ai_notices', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->timestamp('created_at')->nullable();
        });

        // 日ごとの合計。額はマイクロ円（1円 = 1,000,000）の整数
        Schema::create('foundation_ai_totals', function (Blueprint $table) {
            $table->id();
            $table->date('day')->unique();
            $table->bigInteger('cost_micro_yen')->default(0);
            $table->unsignedInteger('calls')->default(0);
        });

        // 呼び出しごとの使用量と原価
        Schema::create('foundation_ai_usages', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);
            $table->string('status', 16);
            $table->string('invocation_id');
            $table->unsignedInteger('step_number')->nullable();
            $table->string('agent')->nullable();
            $table->string('provider_name');
            $table->string('provider_driver');
            $table->string('requested_model');
            $table->string('responded_model')->nullable();
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->nullable();
            $table->unsignedInteger('cache_write_tokens')->nullable();
            $table->unsignedInteger('reasoning_tokens')->nullable();
            // 実際の原価（単価がなければ空）と上限に数えた額。失敗の行はどちらも空
            $table->bigInteger('cost_micro_yen')->nullable();
            $table->bigInteger('counted_micro_yen')->nullable();
            $table->boolean('priced')->default(false);
            // 例外のクラス名だけ。文には秘密が入りうるので残さない
            $table->string('error', 255)->nullable();
            $table->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_ai_usages');
        Schema::dropIfExists('foundation_ai_totals');
        Schema::dropIfExists('foundation_ai_notices');
    }
};
