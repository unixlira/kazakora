<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketplace_ad_photo_briefs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('marketplace', 40)->index();
            $table->string('product_name', 180);
            $table->string('category_hint', 180)->nullable()->index();
            $table->text('description');
            $table->text('immutable_notes')->nullable();
            $table->text('reference_links')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_url')->nullable();
            $table->string('image_original_name')->nullable();
            $table->json('competitive_research')->nullable();
            $table->json('hero_decision')->nullable();
            $table->json('matrix')->nullable();
            $table->longText('copy_pack')->nullable();
            $table->json('generated_product')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 40)->default('ready_for_approval')->index();
            $table->string('approval_status', 40)->default('pending')->index();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('generation_started_at')->nullable();
            $table->timestamp('generation_finished_at')->nullable();
            $table->timestamp('due_at')->nullable();
            $table->text('generation_notes')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketplace_ad_photo_briefs');
    }
};
