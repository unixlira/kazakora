<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_posts', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->unique();
            $t->string('title');
            $t->text('excerpt')->nullable();
            $t->longText('body');
            $t->string('type', 20);
            $t->string('category', 100);
            $t->string('status', 20)->default('draft');
            $t->timestamp('published_at')->nullable();
            $t->string('video_url', 2048)->nullable();
            $t->string('poster_url', 2048)->nullable();
            $t->string('source_url', 2048)->nullable();
            $t->string('source_name')->nullable();
            $t->boolean('rights_confirmed')->default(false);
            $t->boolean('editorial_checked')->default(false);
            $t->foreignId('editor_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['status', 'published_at']);
        });
        Schema::create('editorial_post_product', function (Blueprint $t) {
            $t->foreignId('editorial_post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $t->foreignId('product_id')->constrained()->cascadeOnDelete();
            $t->primary(['editorial_post_id', 'product_id']);
        });
        Schema::create('editorial_likes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['post_id', 'user_id']);
        });
        Schema::create('editorial_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('post_id')->constrained('editorial_posts')->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('display_name');
            $t->text('body');
            $t->string('status', 20)->default('pending');
            $t->foreignId('moderator_id')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('moderated_at')->nullable();
            $t->timestamps();
            $t->index(['post_id', 'status']);
        });
    }

    public function down(): void
    {
        foreach (['editorial_comments', 'editorial_likes', 'editorial_post_product', 'editorial_posts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
