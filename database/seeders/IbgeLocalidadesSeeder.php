<?php

namespace Database\Seeders;

use App\Services\Ibge\IbgeLocalidadesSync;
use Illuminate\Database\Seeder;

/** Estados e municípios do IBGE a partir do arquivo versionado (upsert). */
class IbgeLocalidadesSeeder extends Seeder
{
    public function run(IbgeLocalidadesSync $sync): void
    {
        $sync->carregarArquivo();
    }
}
