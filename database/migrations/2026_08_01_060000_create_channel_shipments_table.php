<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('channel');
            $table->string('external_shipment_id')->nullable();
            // ML: self_service (Flex) | drop_off | xd_drop_off | fulfillment.
            // Shopee: sempre dropoff (drop-off), guardado literal por
            // clareza no admin, mesmo sem variação hoje.
            $table->string('shipping_method')->nullable();
            $table->string('status')->default('pending');
            $table->string('label_path')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('label_ready_at')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_shipments');
    }
};
