<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\Oportunidades\RadarShopee;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa uma coleta do ranking "Produtos em alta" da Central do Vendedor
 * da Shopee (lida no Chrome do Cora Box) para o Radar da Shopee.
 *
 *   php artisan shopee:radar-importar /tmp/shopee-top-products.json
 */
class ImportarRadarShopee extends Command
{
    protected $signature = 'shopee:radar-importar {arquivo : JSON com categorias e produtos (ver RadarShopee)}';

    protected $description = 'Importa o ranking de produtos em alta da Central do Vendedor da Shopee';

    public function handle(RadarShopee $radar): int
    {
        $arquivo = (string) $this->argument('arquivo');
        if (! is_readable($arquivo)) {
            $this->error("Arquivo não encontrado: {$arquivo}");

            return self::FAILURE;
        }

        try {
            $dados = json_decode((string) file_get_contents($arquivo), true, 512, JSON_THROW_ON_ERROR);
            $coleta = $radar->importar($dados);
        } catch (Throwable $e) {
            $this->error('Não importei: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info("Coleta {$coleta->id} de {$coleta->coletado_em->format('d/m/Y H:i')}: {$coleta->total_categorias} subcategorias, {$coleta->total_produtos} produtos.");

        return self::SUCCESS;
    }
}
