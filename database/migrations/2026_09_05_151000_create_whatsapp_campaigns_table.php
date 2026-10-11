<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('whatsapp_campaigns')) {
            return;
        }

        Schema::create('whatsapp_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('mode')->default('text');
            $table->string('status')->default('draft')->index();
            $table->string('template_name')->nullable();
            $table->string('template_language', 16)->nullable();
            $table->text('message_body')->nullable();
            $table->string('media_type')->nullable();
            $table->string('media_path')->nullable();
            $table->string('media_original_name')->nullable();
            $table->string('media_mime', 120)->nullable();
            $table->string('whatsapp_media_id')->nullable();
            $table->unsignedInteger('total_recipients')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->boolean('dry_run')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_campaigns');
    }
};
