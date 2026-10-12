<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\Oportunidades\ImportarMaisVendidosShopee;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa a mineração dos mais vendidos da Central do Vendedor da Shopee
 * (arquivo JSON, formato em ImportarMaisVendidosShopee). A tela
 * Oportunidades › Shopee passa a mostrar a coleta nova na hora.
 *
 *   php artisan shopee:importar-mais-vendidos /tmp/shopee-mais-vendidos.json
 */
class ImportarMaisVendidosShopeeCommand extends Command
{
    protected $signature = 'shopee:importar-mais-vendidos {arquivo : caminho do JSON minerado}';

    protected $description = 'Grava no banco os mais vendidos da Shopee minerados na Central do Vendedor';

    public function handle(ImportarMaisVendidosShopee $importador): int
    {
        $arquivo = (string) $this->argument('arquivo');
        if (! is_readable($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        try {
            $coleta = $importador->importar(json_decode((string) file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error('Não importou: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Coleta #{$coleta->id}: {$coleta->total_categorias} subcategorias e {$coleta->total_produtos} posições de produto.");

        return self::SUCCESS;
    }
}
