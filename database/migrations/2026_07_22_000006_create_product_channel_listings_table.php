<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_channel_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->boolean('is_enabled')->default(false);
            $table->string('status')->default('draft');
            $table->string('external_id')->nullable();
            $table->json('attributes')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['product_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_channel_listings');
    }
};
