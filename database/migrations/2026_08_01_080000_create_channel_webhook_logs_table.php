<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_logs', function (Blueprint $table) {
            $table->id();
            $table->string('channel');
            $table->string('event_type')->nullable();
            $table->json('payload')->nullable();
            $table->json('headers')->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('status')->default('received');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index(['channel', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_logs');
    }
};
