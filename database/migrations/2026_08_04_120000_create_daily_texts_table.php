<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_texts', function (Blueprint $table) {
            $table->id();
            // Data do texto (fuso America/Sao_Paulo, mesmo já usado no
            // resto do app) — chave real de idempotência: rodar o fetch
            // várias vezes no mesmo dia atualiza a mesma linha em vez de
            // duplicar.
            $table->date('date')->unique();
            $table->string('weekday_label');
            $table->text('scripture_quote');
            $table->string('scripture_reference');
            // Comentário completo guardado por completude (o que a fonte
            // real devolve), mesmo que a tela do KoraSync hoje só mostre o
            // texto/versículo em si — evita precisar re-raspar se um dia
            // quiser exibir mais.
            $table->text('commentary')->nullable();
            $table->string('source_doc_id')->nullable();
            $table->timestamp('fetched_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_texts');
    }
};
