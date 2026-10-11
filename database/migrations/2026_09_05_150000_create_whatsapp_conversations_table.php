<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_conversations')) {
            return;
        }

        Schema::create('whatsapp_conversations', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id')->unique();
            $table->string('phone')->nullable()->index();
            $table->string('profile_name')->nullable();
            $table->string('status')->default('open')->index();
            $table->boolean('needs_human')->default(false)->index();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->timestamp('last_customer_message_at')->nullable();
            $table->timestamp('last_auto_reply_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_conversations');
    }
};
