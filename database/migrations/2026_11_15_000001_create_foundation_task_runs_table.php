<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_task_runs', function (Blueprint $table) {
            $table->string('name')->primary();
            // 見張りが初めて見た時刻。書くのは foundation:watch だけ
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_started_at')->nullable();
            $table->timestamp('last_succeeded_at')->nullable();
            $table->timestamp('last_failed_at')->nullable();
            $table->text('last_result')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_task_runs');
    }
};
