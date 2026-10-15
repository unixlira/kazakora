<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Tabela própria de estados e municípios do IBGE (pedido do Lira em
 * 2026-10-15). BUG REAL 2026-10-15: a NF-e dependia da API do IBGE
 * (certificado com cadeia incompleta, cURL 60) e da BrasilAPI (504); a
 * "última lista boa" ficava no cache, o deploy limpou o cache e TODAS as
 * notas pararam de novo. Agora a lista mora no banco e já nasce populada
 * com o arquivo versionado database/data/ibge-localidades.json.
 *
 * A carga fica aqui dentro (sem usar classes do app) para a migration não
 * quebrar se o código mudar no futuro. Atualizações: `ibge:sincronizar`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ibge_estados', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary(); // código IBGE da UF (11..53)
            $table->char('sigla', 2)->unique();
            $table->string('nome', 60);
            $table->timestamps();
        });

        Schema::create('ibge_municipios', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary(); // código IBGE de 7 dígitos
            $table->string('nome', 120);
            $table->string('nome_normalizado', 120);
            $table->unsignedTinyInteger('estado_id');
            $table->char('uf', 2);
            $table->timestamps();

            $table->foreign('estado_id')->references('id')->on('ibge_estados');
            $table->index(['uf', 'nome_normalizado']);
            $table->index('estado_id');
        });

        // Nos testes (SQLite em memória) o RefreshDatabase roda as
        // migrations a CADA teste: 5.571 inserts em cada um estoura memória
        // e tempo. Os testes que precisam dos dados usam o seeder.
        if (! app()->runningUnitTests()) {
            $this->popularSeVazia();
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ibge_municipios');
        Schema::dropIfExists('ibge_estados');
    }

    public function popularSeVazia(): void
    {
        if (DB::table('ibge_estados')->exists()) {
            return;
        }

        $dados = json_decode((string) file_get_contents(database_path('data/ibge-localidades.json')), true, flags: JSON_THROW_ON_ERROR);
        $agora = now();
        $siglas = [];

        $estados = [];
        foreach ($dados['estados'] as [$id, $sigla, $nome]) {
            $siglas[$id] = $sigla;
            $estados[] = ['id' => $id, 'sigla' => $sigla, 'nome' => $nome, 'created_at' => $agora, 'updated_at' => $agora];
        }
        DB::table('ibge_estados')->insert($estados);

        $municipios = [];
        foreach ($dados['municipios'] as [$id, $nome, $estadoId]) {
            $municipios[] = [
                'id' => $id,
                'nome' => $nome,
                'nome_normalizado' => Str::of($nome)->ascii()->lower()->trim()->toString(),
                'estado_id' => $estadoId,
                'uf' => $siglas[$estadoId],
                'created_at' => $agora,
                'updated_at' => $agora,
            ];
        }

        foreach (array_chunk($municipios, 500) as $lote) {
            DB::table('ibge_municipios')->insert($lote);
        }
    }
};
