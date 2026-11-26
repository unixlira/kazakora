<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Tira um produto da vitrine da kazakora.com sem desativar (pedido do Lira 26/11/2026, macaco
 * elétrico repetido): ele continua ativo para os anúncios de marketplace e os pedidos, mas some
 * da home, busca, categoria, sitemap e feed do Google, e a página dele responde 301 para --para.
 * --somar-avaliacoes põe as avaliações dele na página do --para (avaliacoes_de).
 *
 *   php artisan loja:fora-da-vitrine 194 --para=508 --somar-avaliacoes="Macaco Elétrico 4 em 1"
 *   php artisan loja:fora-da-vitrine 194 --desfazer
 *
 * Antes de mudar, grava o estado dos dois produtos em storage/app/backups/fora-da-vitrine-*.json.
 */
class ForaDaVitrineKz extends Command
{
    protected $signature = 'loja:fora-da-vitrine
        {produto : id do produto que sai da vitrine}
        {--para= : id do produto para onde a página antiga redireciona (301)}
        {--somar-avaliacoes= : rótulo das avaliações dele mostradas na página do --para}
        {--desfazer : volta o produto para a vitrine e tira as avaliações dele do --para antigo}';

    protected $description = 'Tira um produto da vitrine da kazakora.com sem desativar (marketplace segue)';

    public function handle(): int
    {
        $produto = Product::query()->findOrFail((int) $this->argument('produto'));
        $destinoId = $this->option('desfazer') ? $produto->vitrine_kz_redireciona_para : (int) $this->option('para');
        $destino = $destinoId ? Product::query()->find($destinoId) : null;

        if (! $this->option('desfazer') && ($destino === null || ! $destino->naKazaKora() || $destino->id === $produto->id)) {
            $this->error('--para precisa ser outro produto ativo na vitrine da KazaKora.');

            return self::FAILURE;
        }

        $arquivo = 'backups/fora-da-vitrine-'.$produto->id.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($arquivo, json_encode(collect([$produto, $destino])->filter()->map(fn (Product $p) => [
            'id' => $p->id,
            'fora_da_vitrine_kz' => $p->fora_da_vitrine_kz,
            'vitrine_kz_redireciona_para' => $p->vitrine_kz_redireciona_para,
            'avaliacoes_de' => $p->avaliacoes_de,
        ])->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->line("Backup: storage/app/{$arquivo}");

        if ($this->option('desfazer')) {
            $produto->forceFill(['fora_da_vitrine_kz' => false, 'vitrine_kz_redireciona_para' => null])->save();
            if ($destino) {
                $destino->forceFill(['avaliacoes_de' => $this->semProduto($destino, $produto->id) ?: null])->save();
            }
            $this->info("Produto {$produto->id} de volta na vitrine.");
        } else {
            $produto->forceFill(['fora_da_vitrine_kz' => true, 'vitrine_kz_redireciona_para' => $destino->id])->save();
            if (filled($this->option('somar-avaliacoes'))) {
                $lista = $this->semProduto($destino, $produto->id);
                $lista[] = ['id' => $produto->id, 'rotulo' => (string) $this->option('somar-avaliacoes')];
                $destino->forceFill(['avaliacoes_de' => $lista])->save();
            }
            $this->info("Produto {$produto->id} fora da vitrine; a página redireciona para {$destino->id} ({$destino->slug}).");
        }

        $this->limparCaches(array_filter([$produto->id, $destino?->id]));

        return self::SUCCESS;
    }

    /** avaliacoes_de do produto sem a linha do $id. */
    private function semProduto(Product $produto, int $id): array
    {
        return array_values(array_filter((array) $produto->avaliacoes_de, fn ($item) => (int) ($item['id'] ?? 0) !== $id));
    }

    private function limparCaches(array $ids): void
    {
        foreach (['home:mais-buscados', 'home:utilidades', 'home:gostar:'.now()->toDateString(), 'loja:departamentos', 'loja:maior-desconto', 'sitemap.xml'] as $chave) {
            Cache::forget($chave);
        }
        foreach ($ids as $id) {
            Cache::forget(CatalogController::chaveCacheDoProduto((int) $id));
            Cache::forget("pagina-produto:{$id}:relacionados");
        }
    }
}
