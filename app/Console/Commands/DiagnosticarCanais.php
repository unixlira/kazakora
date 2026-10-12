<?php

namespace App\Console\Commands;

use App\Modules\Marketplace\Support\Diagnostico\DiagnosticoMercadoLivre;
use App\Modules\Marketplace\Support\Diagnostico\DiagnosticoShopee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Gera o diagnóstico do Cauê (Shopee) e do Diego (Mercado Livre) que aparece
 * no dashboard: o que ajustar em cada anúncio para vender mais. Só lê as
 * APIs; não muda nada nos canais.
 *
 *   php artisan marketplace:diagnostico
 *   php artisan marketplace:diagnostico --canal=shopee
 */
class DiagnosticarCanais extends Command
{
    protected $signature = 'marketplace:diagnostico {--canal= : shopee ou mercado_livre (padrão: os dois)}';

    protected $description = 'Diagnóstico de anúncios da Shopee (Cauê) e do Mercado Livre (Diego) para o dashboard';

    public function handle(): int
    {
        $canais = [
            'shopee' => DiagnosticoShopee::class,
            'mercado_livre' => DiagnosticoMercadoLivre::class,
        ];
        $pedido = $this->option('canal');
        $falhou = false;

        foreach ($canais as $canal => $classe) {
            if ($pedido && $pedido !== $canal) {
                continue;
            }
            try {
                $dados = app($classe)->gerar();
                DB::table('diagnosticos_canal')->updateOrInsert(['canal' => $canal], [
                    'dados' => json_encode($dados, JSON_UNESCAPED_UNICODE),
                    'gerado_em' => now(),
                    'updated_at' => now(),
                    'created_at' => now(),
                ]);
                $porPrioridade = collect($dados['alertas'])->countBy('prioridade')->all();
                $this->info("{$canal}: {$dados['kpis']['anuncios']} anúncios, ".count($dados['alertas']).' alertas '.json_encode($porPrioridade));
            } catch (Throwable $e) {
                report($e);
                $this->error("{$canal}: {$e->getMessage()}");
                $falhou = true;
            }
        }

        return $falhou ? self::FAILURE : self::SUCCESS;
    }
}
