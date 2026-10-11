<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use Illuminate\Console\Command;

/**
 * Gera o conteúdo do anúncio (benefícios + descrição em blocos) dos produtos
 * ativos que ainda não têm ou cujo nome/descrição/fotos mudaram — pedido
 * 2026-10-09. Roda pelo agendador em lotes pequenos (cada produto é uma
 * chamada ao Gemini); --produto=ID gera um só, --forcar refaz mesmo sem
 * mudança.
 */
class GerarConteudoAnuncio extends Command
{
    protected $signature = 'produtos:gerar-conteudo-anuncio
        {--produto= : id de um produto}
        {--forcar : gera de novo mesmo sem mudança}
        {--limite=10 : máximo de produtos por execução}';

    protected $description = 'Gera benefícios e descrição em blocos (Gemini, com regra própria se falhar) para a página do produto.';

    public function handle(AnuncioConteudoService $servico): int
    {
        $forcar = (bool) $this->option('forcar');

        $query = Product::query()->with(['images', 'adContent'])->orderBy('id');

        if ($id = $this->option('produto')) {
            $query->whereKey($id);
        } else {
            $query->where('is_active', true);
        }

        $limite = max(1, (int) $this->option('limite'));
        $feitos = 0;

        foreach ($query->lazy() as $product) {
            if ($feitos >= $limite) {
                break;
            }

            if (! $forcar && ! $servico->precisaGerar($product)) {
                continue;
            }

            $conteudo = $servico->gerar($product, $forcar);
            $feitos++;
            $this->line(sprintf('#%d %s → %s%s', $product->id, $product->name, $conteudo->fonte, $conteudo->erro ? " ({$conteudo->erro})" : ''));
        }

        $this->info("{$feitos} produto(s) processado(s).");

        return self::SUCCESS;
    }
}
