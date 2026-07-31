<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('melhor_envio_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('account_label')->nullable();
            $table->text('access_token');
            // text(), não string() — o refresh_token da Mercado Livre já
            // estourou um varchar(255) uma vez neste projeto (ver migration
            // widen_refresh_token_on_mercado_livre_tokens_table), então já
            // começamos largo aqui pra não repetir o mesmo bug.
            $table->text('refresh_token');
            $table->dateTime('token_expires_at');
            $table->json('scopes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('melhor_envio_tokens');
    }
};
