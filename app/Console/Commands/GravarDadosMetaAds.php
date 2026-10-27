<?php

namespace App\Console\Commands;

use App\Services\Meta\MetaAdsDadosSincronizador;
use App\Services\Meta\MetaAdsException;
use App\Services\Meta\MetaAdsService;
use Illuminate\Console\Command;

/**
 * Metadados das campanhas da Meta no nosso banco (pedido do Lira em
 * 2026-10-27): estrutura da conta e performance diária de cada anúncio,
 * para a tela Metadados das campanhas. Só lê da Meta.
 * Na rotina relê os últimos 7 dias (a Meta ainda ajusta compras atribuídas
 * a cliques dos dias anteriores); --tudo relê desde o início da conta.
 */
class GravarDadosMetaAds extends Command
{
    protected $signature = 'meta-ads:gravar-dados
        {--dias=7 : Dias para trás, contando hoje}
        {--tudo : Desde o início da conta}';

    protected $description = 'Grava no banco campanhas, conjuntos, anúncios e a performance diária da Meta';

    public function handle(MetaAdsService $meta, MetaAdsDadosSincronizador $sincronizador): int
    {
        if (! $meta->configurado()) {
            $this->warn('Meta não configurada: faltam '.implode(', ', $meta->faltando()).'.');

            return self::SUCCESS;
        }

        try {
            $r = $sincronizador->sincronizar($this->option('tudo') ? null : max(1, (int) $this->option('dias')));
        } catch (MetaAdsException $erro) {
            $this->error('Meta: '.$erro->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Gravado: %d campanhas, %d conjuntos e %d anúncios listados na Meta; %d registros de anúncio por dia (%s a %s).',
            $r['campanhas'], $r['conjuntos'], $r['anuncios'], $r['dias_com_dado'], $r['de'] ?? 'início', $r['ate'],
        ));

        return self::SUCCESS;
    }
}
