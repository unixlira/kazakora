<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\PaginaBrinquedo;
use Illuminate\Console\Command;

/**
 * Grava o conteúdo da página de brinquedo (products.pagina_brinquedo) a partir dos
 * arquivos de database/paginas-brinquedo/<slug>.json (pedido do Lira 25/11/2026).
 * O arquivo fica no git: editar o texto é editar o arquivo e rodar o comando de novo.
 * Antes de gravar confere palavras proibidas, ícones e fotos (PaginaBrinquedo::problemas).
 *
 *   php artisan brinquedo:pagina                 todos os arquivos
 *   php artisan brinquedo:pagina --slug=abc      só este produto
 *   php artisan brinquedo:pagina --limpar --slug=abc   apaga (a página volta ao conteúdo do anúncio)
 *   php artisan brinquedo:pagina --conferir      só confere, não grava
 */
class PaginaBrinquedoImportar extends Command
{
    protected $signature = 'brinquedo:pagina
        {--slug=* : Só estes produtos (slug do produto pai).}
        {--limpar : Apaga o conteúdo destes produtos.}
        {--conferir : Só confere os arquivos, sem gravar.}';

    protected $description = 'Grava o conteúdo da página de brinquedo de cada produto a partir de database/paginas-brinquedo.';

    public function handle(): int
    {
        $slugs = $this->option('slug');

        if ($this->option('limpar')) {
            if (! $slugs) {
                $this->error('Use --slug com --limpar.');

                return self::FAILURE;
            }
            $n = Product::whereIn('slug', $slugs)->update(['pagina_brinquedo' => null]);
            $this->info("{$n} produto(s) sem conteúdo próprio.");

            return self::SUCCESS;
        }

        $pasta = database_path('paginas-brinquedo');
        $arquivos = glob($pasta.'/*.json') ?: [];
        $icones = $this->icones();
        $falhas = 0;
        $gravados = 0;

        foreach ($arquivos as $arquivo) {
            $slug = basename($arquivo, '.json');
            if ($slugs && ! in_array($slug, $slugs, true)) {
                continue;
            }
            $pagina = json_decode((string) file_get_contents($arquivo), true);
            if (! is_array($pagina)) {
                $this->error("{$slug}: JSON inválido.");
                $falhas++;

                continue;
            }
            $product = Product::where('slug', $slug)->whereNull('parent_product_id')->withCount('images')->first();
            if (! $product) {
                $this->warn("{$slug}: produto não encontrado, pulado.");

                continue;
            }
            $problemas = PaginaBrinquedo::problemas($pagina, $icones, (int) $product->images_count);
            if ($problemas) {
                $this->error("{$slug}: ".implode('; ', $problemas));
                $falhas++;

                continue;
            }
            // "avaliacoes_de": [{slug, rotulo}] junta as avaliações reais de um produto irmão (o mesmo
            // brinquedo em outro anúncio). Vai pela slug para valer igual em homologação e produção.
            $avaliacoesDe = null;
            if (isset($pagina['avaliacoes_de']) && is_array($pagina['avaliacoes_de'])) {
                $avaliacoesDe = [];
                foreach ($pagina['avaliacoes_de'] as $irmao) {
                    $id = Product::where('slug', $irmao['slug'] ?? '')->value('id');
                    if ($id) {
                        $avaliacoesDe[] = ['id' => $id, 'rotulo' => (string) ($irmao['rotulo'] ?? '')];
                    } else {
                        $this->warn("{$slug}: irmão {$irmao['slug']} não encontrado.");
                    }
                }
                unset($pagina['avaliacoes_de']);
            }
            if (! $this->option('conferir')) {
                $dados = ['pagina_brinquedo' => $pagina];
                if ($avaliacoesDe !== null) {
                    $dados['avaliacoes_de'] = $avaliacoesDe ?: null;
                }
                $product->forceFill($dados)->save();
            }
            $gravados++;
        }

        $this->info(($this->option('conferir') ? 'Conferidos' : 'Gravados').": {$gravados}. Com problema: {$falhas}.");

        return $falhas ? self::FAILURE : self::SUCCESS;
    }

    /** Nomes de ícone que a página conhece (resources/js/Modules/Catalog/brinquedo/icones.js). */
    private function icones(): array
    {
        $js = (string) @file_get_contents(resource_path('js/Modules/Catalog/brinquedo/icones.js'));
        preg_match_all('/^\s+"([a-z0-9-]+)":/m', $js, $m);

        return $m[1];
    }
}
