<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Coluna site em site_visits (pedido do Lira 2026-10-12): o banco é
 * compartilhado com os outros sites do grupo (kazakora, ferrakora,
 * beautykora). Primeiro passo, que vai sozinho para a produção: só a
 * coluna, nula e sem índice, para não mudar nada no que já roda.
 *
 * O preenchimento (null = kazakora), o padrão e o índice ficam na
 * 2026_10_13_030000_add_site_to_site_visits, que vai junto com as telas.
 * Se a coluna já existir (homolog), não faz nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('site_visits', 'site')) {
            return;
        }

        Schema::table('site_visits', function (Blueprint $table) {
            $table->string('site', 20)->nullable()->after('id');
        });
    }

    public function down(): void
    {
        // Sem volta: no homolog a coluna é da 030000 e não pode sair daqui.
    }
};
