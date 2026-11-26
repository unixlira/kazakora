<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Models\ProductImage;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use App\Modules\Catalog\Support\EscadaDeKits;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Monta uma oferta em escada de kits a partir de database/escadas-kits/<nome>.json
 * (pedido do Lira 26/11/2026, Compressor 4 em 1 em 3 kits). O arquivo fica no git:
 *
 *   criar    produtos novos (kit montado com outros produtos), achados pelo SKU: nome, slug,
 *            preço "de" (price) e "por" (vira discount_amount), custo, estoque (entrada registrada
 *            no StockManager), fiscal copiado de outro produto, fotos copiadas (arquivo do git ou
 *            foto de outro produto, sempre um arquivo novo), conteúdo da página, avaliacoes_de e o
 *            registro no site FerraKora.
 *   ajustar  produtos que já existem, pelo SKU: nome, slug, preço "de"/"por", estoque (entrada
 *            ou saída registrada no StockManager, só a diferença) e vídeo (arquivo já no disco público).
 *            Tudo opcional: só muda o que o item traz.
 *   escada   o JSON dos cartões (itens por SKU), gravado em products.escada_kits de cada kit
 *            (opcional: um arquivo só com ajustes, como os vídeos, não mexe na escada).
 *
 * Sem --aplicar só mostra o que faria. Com --aplicar guarda antes um backup JSON dos produtos
 * tocados em storage/app/backups/escada-kits-<nome>-<data>.json.
 *
 *   php artisan kits:escada compressor-4-em-1
 *   php artisan kits:escada compressor-4-em-1 --aplicar
 */
class EscadaDeKitsAplicar extends Command
{
    protected $signature = 'kits:escada {nome : Arquivo em database/escadas-kits, sem .json} {--aplicar : Grava (sem ele, só mostra).}';

    protected $description = 'Cria/ajusta os produtos de uma oferta em escada de kits e grava os cartões (database/escadas-kits).';

    public function handle(StockManager $estoque, AnuncioConteudoService $conteudos): int
    {
        $nome = (string) $this->argument('nome');
        $arquivo = database_path("escadas-kits/{$nome}.json");
        $dados = is_file($arquivo) ? json_decode((string) file_get_contents($arquivo), true) : null;
        if (! is_array($dados)) {
            $this->error("Arquivo inválido ou ausente: {$arquivo}");

            return self::FAILURE;
        }

        $aplicar = (bool) $this->option('aplicar');
        $skus = collect($dados['criar'] ?? [])->pluck('sku')
            ->merge(collect($dados['ajustar'] ?? [])->pluck('sku'))
            ->merge(collect($dados['escada']['itens'] ?? [])->pluck('sku'))
            ->filter()->unique()->values();
        $existentes = Product::withTrashed()->whereIn('sku', $skus)->get()->keyBy('sku');

        // Confere antes de tudo: SKUs da escada que não existem e não serão criados.
        $criados = collect($dados['criar'] ?? [])->pluck('sku');
        foreach ((array) ($dados['escada']['itens'] ?? []) as $item) {
            if (! $existentes->has($item['sku'] ?? '') && ! $criados->contains($item['sku'] ?? '')) {
                $this->error('SKU da escada não existe: '.($item['sku'] ?? '?'));

                return self::FAILURE;
            }
        }
        $escadaTeste = $this->escadaComIds($dados['escada'] ?? [], fn ($sku) => $existentes->get($sku)?->id ?? 999999);
        if (isset($dados['escada']) && $problemas = EscadaDeKits::problemas($escadaTeste)) {
            $this->error('Escada com problemas: '.implode('; ', $problemas));

            return self::FAILURE;
        }

        if (! $aplicar) {
            foreach ($dados['criar'] ?? [] as $kit) {
                $this->line(($existentes->has($kit['sku']) ? 'atualizar' : 'criar').": {$kit['sku']} {$kit['name']} de {$kit['price']} por {$kit['por']}, estoque {$kit['estoque']}");
            }
            foreach ($dados['ajustar'] ?? [] as $ajuste) {
                $p = $existentes->get($ajuste['sku']);
                $this->line("ajustar: {$ajuste['sku']} (id ".($p?->id ?? '?').') de '.($ajuste['price'] ?? '-').' por '.($ajuste['por'] ?? '-').', estoque '.($p?->stock ?? '?').' -> '.($ajuste['estoque'] ?? '-'));
                if (! empty($ajuste['video']['arquivo'])) {
                    $this->line('  vídeo: '.($p?->video_path ?: '(nenhum)').' -> '.$ajuste['video']['arquivo'].(Storage::disk('public')->exists($ajuste['video']['arquivo']) ? '' : ' (ARQUIVO NÃO ENCONTRADO)'));
                }
            }
            $this->info('Nada gravado. Rode com --aplicar.');

            return self::SUCCESS;
        }

        $this->backup($nome, $existentes->pluck('id')->all());

        DB::transaction(function () use ($dados, $estoque, $conteudos) {
            foreach ($dados['criar'] ?? [] as $kit) {
                $this->criarKit($kit, $estoque, $conteudos);
            }
            foreach ($dados['ajustar'] ?? [] as $ajuste) {
                $this->ajustar($ajuste, $estoque, $conteudos);
            }

            if (! isset($dados['escada'])) {
                return;
            }
            $porSku = Product::whereIn('sku', collect($dados['escada']['itens'] ?? [])->pluck('sku'))->pluck('id', 'sku');
            $escada = $this->escadaComIds($dados['escada'], fn ($sku) => $porSku[$sku] ?? null);
            Product::whereIn('id', EscadaDeKits::ids($escada))->get()->each(fn (Product $p) => $p->forceFill(['escada_kits' => $escada])->saveQuietly());
            $this->info('Escada gravada nos produtos '.implode(', ', EscadaDeKits::ids($escada)).'.');
        });

        return self::SUCCESS;
    }

    private function escadaComIds(array $escada, callable $id): array
    {
        $escada['itens'] = array_map(function (array $item) use ($id) {
            $item['id'] = $id($item['sku'] ?? '');
            unset($item['sku']);

            return $item;
        }, (array) ($escada['itens'] ?? []));

        return $escada;
    }

    private function criarKit(array $kit, StockManager $estoque, AnuncioConteudoService $conteudos): void
    {
        $modelo = Product::with(['fiscalData', 'images'])->findOrFail((int) $kit['copiar_de']);
        $produto = Product::withTrashed()->where('sku', $kit['sku'])->first();
        $novo = $produto === null;

        $produto ??= new Product;
        $produto->fill([
            'sku' => $kit['sku'],
            'name' => $kit['name'],
            'nome_interno' => $kit['nome_interno'] ?? null,
            'slug' => $kit['slug'],
            'description' => $kit['description'],
            'category_id' => $modelo->category_id,
            'price' => $kit['price'],
            'discount_percentage' => null,
            'discount_amount' => round((float) $kit['price'] - (float) $kit['por'], 2),
            'cost_price' => $kit['cost_price'],
            'categoria_frete' => $kit['categoria_frete'] ?? $modelo->categoria_frete,
            'checkout_discount_percentage' => $modelo->checkout_discount_percentage,
            'so_ferrakora' => (bool) $modelo->so_ferrakora,
            'is_active' => true,
            'avaliacoes_de' => $kit['avaliacoes_de'] ?? null,
        ]);
        if ($novo) {
            $produto->stock = 0;
        }
        $produto->save();
        if ($produto->trashed()) {
            $produto->restore();
        }
        $this->info(($novo ? 'Criado' : 'Atualizado')." o kit {$produto->sku}: id {$produto->id}.");

        $this->estoqueAte($produto, (int) $kit['estoque'], $estoque, $novo ? 'Estoque inicial do kit (escada de kits, pedido do Lira 26/11/2026)' : 'Ajuste do estoque do kit (escada de kits)');

        // Fiscal: o do produto principal, com peso, medidas e a composição do kit.
        if ($modelo->fiscalData) {
            $fiscal = collect($modelo->fiscalData->getAttributes())->except(['id', 'product_id', 'created_at', 'updated_at'])->all();
            $produto->fiscalData()->updateOrCreate([], [...$fiscal, ...collect($kit['fiscal'] ?? [])->except('copiar_de')->all()]);
        }

        if ($novo || $produto->images()->doesntExist()) {
            $this->copiarFotos($produto, (array) ($kit['fotos'] ?? []));
        }

        if (! empty($kit['conteudo'])) {
            $produto->load('images');
            ProductAdContent::updateOrCreate(['product_id' => $produto->id], [
                ...$kit['conteudo'],
                'fonte' => ProductAdContent::FONTE_CLAUDE,
                'origem_hash' => $conteudos->hash($produto),
                'erro' => null,
                'gerado_em' => now(),
            ]);
        }

        if (! empty($kit['ferrakora'])) {
            FerraKoraProduto::updateOrCreate(['product_id' => $produto->id], [
                'categoria' => $kit['ferrakora']['categoria'] ?? null,
                'ordem' => (int) ($kit['ferrakora']['ordem'] ?? 999),
                'ativo' => true,
            ]);
        }
    }

    private function ajustar(array $ajuste, StockManager $estoque, AnuncioConteudoService $conteudos): void
    {
        $produto = Product::with('adContent')->where('sku', $ajuste['sku'])->firstOrFail();
        // Conteúdo da página escrito à mão: renomear não pode fazer a IA gerar outro por cima.
        $conteudoEmDia = $produto->adContent && $produto->adContent->origem_hash === $conteudos->hash($produto);

        $campos = [];
        foreach (['name', 'slug'] as $campo) {
            if (filled($ajuste[$campo] ?? null)) {
                $campos[$campo] = $ajuste[$campo];
            }
        }
        if (isset($ajuste['price'], $ajuste['por'])) {
            $campos['price'] = $ajuste['price'];
            $campos['discount_percentage'] = null;
            $campos['discount_amount'] = round((float) $ajuste['price'] - (float) $ajuste['por'], 2);
        }
        $produto->fill($campos)->save();
        $this->info("Ajustado {$produto->sku} (id {$produto->id}).");

        if ($conteudoEmDia) {
            $produto->unsetRelation('images');
            $produto->adContent->forceFill(['origem_hash' => $conteudos->hash($produto)])->saveQuietly();
        }

        // Vídeo do produto (o mesmo campo do upload do admin): arquivo já enviado ao disco público.
        // O vídeo anterior fica no disco, para voltar se precisar.
        if (! empty($ajuste['video']['arquivo'])) {
            if (! Storage::disk('public')->exists($ajuste['video']['arquivo'])) {
                throw new \RuntimeException("Vídeo não encontrado no disco público: {$ajuste['video']['arquivo']}");
            }
            $this->info("Vídeo de {$produto->sku}: ".($produto->video_path ?: '(nenhum)')." -> {$ajuste['video']['arquivo']}");
            $produto->forceFill(['video_path' => $ajuste['video']['arquivo'], 'video_duration_seconds' => (int) ($ajuste['video']['segundos'] ?? 0) ?: null])->save();
        }

        if (isset($ajuste['estoque'])) {
            $this->estoqueAte($produto, (int) $ajuste['estoque'], $estoque, $ajuste['motivo_estoque'] ?? 'Ajuste da escada de kits');
        }
    }

    private function estoqueAte(Product $produto, int $alvo, StockManager $estoque, string $motivo): void
    {
        $atual = (int) $produto->fresh()->stock;
        if ($alvo === $atual) {
            return;
        }
        $tipo = $alvo > $atual ? StockMovement::TYPE_RESTOCK : StockMovement::TYPE_ADJUSTMENT;
        $estoque->adjust($produto, $alvo - $atual, $tipo, reason: $motivo);
        $this->info("Estoque de {$produto->sku}: {$atual} -> {$alvo}.");
    }

    private function copiarFotos(Product $produto, array $fotos): void
    {
        $disco = Storage::disk('public');
        foreach (array_values($fotos) as $posicao => $foto) {
            if (! empty($foto['arquivo'])) {
                $origem = database_path('escadas-kits/'.$foto['arquivo']);
                $destino = "products/{$produto->id}/kit/".basename($foto['arquivo']);
                $disco->put($destino, (string) file_get_contents($origem));
            } else {
                $imagem = ProductImage::where('product_id', (int) $foto['produto'])->orderBy('position')->orderBy('id')->skip((int) $foto['indice'])->first();
                if ($imagem === null || ! $disco->exists($imagem->path)) {
                    $this->warn("Foto {$foto['indice']} do produto {$foto['produto']} não encontrada; pulada.");

                    continue;
                }
                $destino = "products/{$produto->id}/kit/{$foto['produto']}-".basename($imagem->path);
                $disco->copy($imagem->path, $destino);
            }
            ProductImage::create([
                'product_id' => $produto->id,
                'path' => $destino,
                'position' => $posicao,
                'is_primary' => $posicao === 0,
                'is_variant' => false,
            ]);
        }
    }

    /** @param  list<int>  $ids */
    private function backup(string $nome, array $ids): void
    {
        $produtos = Product::withTrashed()->with(['fiscalData', 'images', 'adContent'])->whereIn('id', $ids)->get()
            ->map(fn (Product $p) => [
                'produto' => $p->getAttributes(),
                'fiscal' => $p->fiscalData?->getAttributes(),
                'fotos' => $p->images->map->getAttributes()->all(),
                'conteudo' => $p->adContent?->getAttributes(),
                'ferrakora' => FerraKoraProduto::where('product_id', $p->id)->first()?->getAttributes(),
            ]);
        $caminho = 'backups/escada-kits-'.$nome.'-'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($caminho, json_encode($produtos, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->info('Backup: '.Storage::disk('local')->path($caminho));
    }
}
