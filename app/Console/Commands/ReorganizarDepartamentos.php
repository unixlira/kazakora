<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Enxuga os departamentos da loja (pedido 2026-10-10): de 12 categorias
 * (com nomes em caixa alta, uma vazia e 18 produtos sem categoria) para 5.
 * Sem --aplicar só mostra o que faria. Com --aplicar guarda um backup em
 * storage/app/private/backups antes de mexer, tudo numa transação.
 */
class ReorganizarDepartamentos extends Command
{
    protected $signature = 'loja:reorganizar-departamentos {--aplicar : Aplica de verdade (sem isso é só simulação)}';

    protected $description = 'Reorganiza as categorias da loja em 5 departamentos';

    /** Departamentos finais: chave => nome. */
    private const DEPARTAMENTOS = [
        'eletronicos' => 'Eletrônicos e Acessórios',
        'casa' => 'Casa e Cozinha',
        'ferramentas' => 'Ferramentas e Automotivo',
        'brinquedos' => 'Brinquedos e Games',
        'beleza' => 'Beleza e Bem-Estar',
    ];

    /** Categoria antiga (slug) => departamento novo. */
    private const MAPA = [
        'eletronicos' => 'eletronicos',
        'carregadores' => 'eletronicos',
        'organizacao' => 'casa',
        'organizacao-cozinha' => 'casa',
        'cozinha' => 'casa',
        'utensilios-e-dia-a-dia' => 'casa',
        'pets' => 'casa',
        'ferramentas' => 'ferramentas',
        'brinquedos-e-hobbies' => 'brinquedos',
        'jogos-e-consoles' => 'brinquedos',
        'produtos-de-beleza' => 'beleza',
    ];

    /** Produtos no lugar errado ou sem categoria (por nome, ordem importa). */
    private const PALAVRAS = [
        'casa' => ['lixeira', 'limpador', ' pote', 'cesto', 'saboneteira', 'bebedouro', 'aspirador', 'guarda roupa', 'organizador'],
        'ferramentas' => ['veicular', 'retrovisor', ' moto ', 'lanterna', 'multímetro', 'multimetro', 'ferramenta', 'parafusadeira', 'soquete', 'chave l', 'trena', 'nível laser', 'macaco', 'tarracha', 'soprador'],
        'beleza' => ['pressão arterial', 'alisador', 'barba', 'modeladora', 'ergométrica', 'ergometrica', 'spinning'],
        'eletronicos' => ['câmera', 'camera', 'carregador', 'power bank', 'ring light', 'microfone', 'webcam', 'fone', 'caixa de som', 'interruptor', 'extensão'],
    ];

    public function handle(): int
    {
        $aplicar = (bool) $this->option('aplicar');
        $categorias = Category::query()->get()->keyBy('slug');
        $produtos = Product::query()->orderBy('id')->get(['id', 'name', 'category_id', 'parent_product_id']);
        $slugPorId = $categorias->mapWithKeys(fn (Category $c) => [$c->id => $c->slug]);

        // Destino de cada produto "pai"; variação segue o pai.
        $destino = [];
        foreach ($produtos->whereNull('parent_product_id') as $produto) {
            $destino[$produto->id] = $this->classificar($produto, $slugPorId[$produto->category_id] ?? null);
        }
        foreach ($produtos->whereNotNull('parent_product_id') as $produto) {
            $destino[$produto->id] = $destino[$produto->parent_product_id] ?? $this->classificar($produto, $slugPorId[$produto->category_id] ?? null);
        }

        $linhas = collect($destino)->countBy()->map(fn ($total, $chave) => [self::DEPARTAMENTOS[$chave], $total])->values();
        $this->table(['Departamento', 'Produtos'], $linhas->all());
        foreach ($produtos as $produto) {
            $antes = $categorias->firstWhere('id', $produto->category_id)?->name ?? '(sem categoria)';
            $depois = self::DEPARTAMENTOS[$destino[$produto->id]];
            if ($this->output->isVerbose() || $antes === '(sem categoria)') {
                $this->line("#{$produto->id} ".Str::limit($produto->name, 55)." — {$antes} → {$depois}");
            }
        }

        if (! $aplicar) {
            $this->warn('Simulação: nada foi alterado. Rode com --aplicar para valer.');

            return self::SUCCESS;
        }

        $backup = 'backups/categorias-antes-reorganizar-'.now()->format('Y-m-d-His').'.json';
        Storage::disk('local')->put($backup, json_encode([
            'categorias' => Category::query()->get()->map(fn ($c) => $c->getAttributes())->all(),
            'produtos' => $produtos->map(fn ($p) => ['id' => $p->id, 'category_id' => $p->category_id])->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Backup: '.Storage::disk('local')->path($backup));

        DB::transaction(function () use ($categorias, $destino) {
            // Reaproveita a categoria antiga de mesmo assunto (mantém o id), renomeada.
            $reaproveitar = ['eletronicos' => 'eletronicos', 'casa' => 'cozinha', 'ferramentas' => 'ferramentas', 'brinquedos' => 'brinquedos-e-hobbies', 'beleza' => 'produtos-de-beleza'];
            $ids = [];
            foreach (self::DEPARTAMENTOS as $chave => $nome) {
                $categoria = $categorias->get($reaproveitar[$chave]) ?? new Category;
                $categoria->forceFill(['name' => $nome, 'slug' => Str::slug($nome)])->save();
                $ids[$chave] = $categoria->id;
            }

            foreach ($destino as $produtoId => $chave) {
                Product::query()->whereKey($produtoId)->update(['category_id' => $ids[$chave]]);
            }

            Category::query()->whereNotIn('id', array_values($ids))->get()
                ->each(fn (Category $c) => $c->products()->exists() ? null : $c->delete());
        });

        Cache::forget('loja:departamentos');
        Cache::forget('home:utilidades');
        Cache::forget('home:utilidades-link');
        $this->info('Pronto: '.Category::query()->count().' departamentos.');

        return self::SUCCESS;
    }

    private function classificar(Product $produto, ?string $slugAntigo): string
    {
        $nome = ' '.mb_strtolower($produto->name).' ';

        // Brinquedo nunca vira outra coisa por causa de palavra solta ("carrinhos", "cozinha").
        if ($slugAntigo !== 'brinquedos-e-hobbies' && $slugAntigo !== 'jogos-e-consoles') {
            foreach (self::PALAVRAS as $chave => $palavras) {
                foreach ($palavras as $palavra) {
                    if (str_contains($nome, $palavra)) {
                        return $chave;
                    }
                }
            }
        }

        return self::MAPA[$slugAntigo] ?? 'casa';
    }
}
