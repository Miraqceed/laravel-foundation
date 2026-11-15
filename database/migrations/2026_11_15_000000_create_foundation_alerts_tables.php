<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foundation_alerts', function (Blueprint $table) {
            $table->id();
            $table->string('key');
            $table->string('subject');
            $table->text('body');
            $table->timestamp('created_at')->nullable();

            $table->index(['key', 'created_at']);
        });

        // 送れたかは送り先ごとに持つ
        Schema::create('foundation_alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_id')->constrained('foundation_alerts')->cascadeOnDelete();
            $table->string('channel', 32);
            $table->timestamp('sent_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('abandoned_at')->nullable();
            $table->text('last_error')->nullable();

            $table->index(['sent_at', 'abandoned_at', 'last_attempted_at'], 'foundation_alert_deliveries_pending');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('foundation_alert_deliveries');
        Schema::dropIfExists('foundation_alerts');
    }
};
