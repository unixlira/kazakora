<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_quantity_discounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('min_quantity');
            $table->decimal('discount_percentage', 5, 2);
            $table->timestamps();

            $table->unique(['product_id', 'min_quantity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_quantity_discounts');
    }
};
