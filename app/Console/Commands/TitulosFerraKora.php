<?php

namespace App\Console\Commands;

use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Models\Product;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Títulos da FerraKora (pedido do Lira 26/11/2026): frase que vende + as
 * palavras técnicas que o cliente digita na busca, sem "+ PDF ..." no nome
 * (o PDF continua sendo entregue). Lê um CSV "id;titulo_novo" (id do
 * produto do KazaKora) e troca o título do produto no site FerraKora.
 *
 * Com --seco só mostra título e endereço antes/depois. Sem ele, grava um
 * backup em storage/app/titulos-ferrakora/ e, por produto:
 *  - ferrakora_produtos.titulo = título novo, salvo COM eventos: o slug
 *    que o site publicava vai para outro_site_slugs_antigos e o link
 *    antigo (anúncio da Meta) responde 301 para o novo, com a query;
 *  - slug fixo com "pdf" é solto (o endereço passa a sair do título);
 *  - produto só da FerraKora: o name também vira o título novo (é o nome
 *    no checkout do site), termos_busca recebe o nome antigo, seo_title
 *    igual ao nome antigo acompanha e nome_interno vazio recebe o nome
 *    antigo sem o "+ PDF" (nome da NF-e). Grava sem eventos (saveQuietly).
 *  - produto que também é da KazaKora: o name (título da loja) NÃO muda.
 *
 * Preço, layout, fotos e PDF não são tocados. --desfazer=<backup> volta.
 */
class TitulosFerraKora extends Command
{
    protected $signature = 'ferrakora:titulos
        {arquivo? : CSV id;titulo_novo, separado por ponto e vírgula}
        {--seco : Só mostra o antes/depois, não grava}
        {--desfazer= : Caminho do backup (titulos-ferrakora/...) para voltar}';

    protected $description = 'Troca os títulos (e endereços) dos produtos da FerraKora por um CSV, com backup e volta';

    public const PASTA = 'titulos-ferrakora';

    public function handle(CatalogoFerraKora $catalogo): int
    {
        if (filled($this->option('desfazer'))) {
            return $this->desfazer((string) $this->option('desfazer'));
        }

        $arquivo = (string) $this->argument('arquivo');
        if ($arquivo === '' || ! is_file($arquivo)) {
            $this->error('Informe o CSV (id;titulo_novo). Arquivo não encontrado: '.$arquivo);

            return self::FAILURE;
        }

        [$trocas, $puladas] = $this->lerCsv($arquivo, $catalogo);

        foreach ($puladas as $linha) {
            $this->warn("Pulado: {$linha}");
        }

        if ($trocas === []) {
            $this->info('Nada para trocar.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'título atual', 'título novo', 'endereço atual', 'endereço novo'],
            array_map(fn (array $t) => [$t['registro']->product_id, $t['titulo_atual'], $t['titulo'], $t['slug_atual'], $t['slug_novo']], $trocas),
        );

        if ($this->option('seco')) {
            $this->info(count($trocas).' produto(s) mudariam. Nada gravado (--seco).');

            return self::SUCCESS;
        }

        $caminho = self::PASTA.'/backup-'.now()->format('Ymd-His').'.json';
        $backup = ['criado_em' => now()->toIso8601String(), 'arquivo' => $arquivo, 'produtos' => []];

        // O backup é gravado dentro da transação: se não der para gravar, nada muda.
        DB::transaction(function () use ($trocas, &$backup, $caminho) {
            foreach ($trocas as $troca) {
                $backup['produtos'][] = $this->aplicar($troca);
            }

            if (! Storage::disk('local')->put($caminho, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
                throw new \RuntimeException("Não consegui gravar o backup {$caminho}; nada foi trocado.");
            }
        });

        $this->limparCaches(array_column($backup['produtos'], 'id'));

        $this->info(count($backup['produtos']).' produto(s) trocados. Backup: '.Storage::disk('local')->path($caminho));
        $this->line("Para voltar: php artisan ferrakora:titulos --desfazer={$caminho}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, string>}
     */
    private function lerCsv(string $arquivo, CatalogoFerraKora $catalogo): array
    {
        $trocas = [];
        $puladas = [];
        $novos = [];

        $ocupados = FerraKoraProduto::query()->get()
            ->mapWithKeys(fn (FerraKoraProduto $r) => [(string) $catalogo->slugPublicado($r) => $r->product_id]);

        $linhas = preg_split('/\r\n|\n|\r/', (string) file_get_contents($arquivo));
        foreach ($linhas as $numero => $texto) {
            $texto = $numero === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $texto) : $texto;
            if (trim((string) $texto) === '') {
                continue;
            }

            $colunas = array_map('trim', str_getcsv((string) $texto, ';'));
            $id = $colunas[0] ?? '';
            if (! ctype_digit($id)) {
                continue; // cabeçalho
            }

            $titulo = trim((string) end($colunas));
            $registro = FerraKoraProduto::query()->where('product_id', (int) $id)->first();
            $produto = $registro ? Product::query()->find($registro->product_id) : null;
            $rotulo = 'linha '.($numero + 1)." (id {$id})";

            $tituloAtual = $registro ? ($registro->titulo ?: (string) $produto?->name) : '';
            $slugAtual = $registro ? (string) $catalogo->slugPublicado($registro) : '';
            $slugFixo = $registro && filled($registro->slug) && ! Str::contains((string) $registro->slug, 'pdf') ? $registro->slug : null;
            $slugNovo = CatalogoFerraKora::slugDoTitulo($slugFixo, $titulo);
            $dono = $ocupados[$slugNovo] ?? null;

            $motivo = match (true) {
                $registro === null || $produto === null => 'produto não está na FerraKora',
                $produto->parent_product_id !== null => 'é variação, não produto pai',
                isset($novos[$id]) => 'id repetido no CSV',
                count($colunas) < 2 || $titulo === '' => 'sem título novo',
                mb_strlen($titulo) > 150 => 'título com mais de 150 caracteres (Google Shopping)',
                (bool) preg_match('/\bpdf\b/iu', $titulo) => 'título novo ainda fala em PDF',
                $titulo === trim($tituloAtual) => 'título igual ao atual',
                $slugNovo === '' => 'título sem endereço',
                $dono !== null && $dono !== (int) $id => "endereço {$slugNovo} já é de outro produto ({$dono})",
                in_array($slugNovo, array_column($trocas, 'slug_novo'), true) => "endereço {$slugNovo} repetido no CSV",
                default => null,
            };

            if ($motivo !== null) {
                $puladas[] = "{$rotulo}: {$motivo}";

                continue;
            }

            $novos[$id] = true;
            $trocas[] = [
                'registro' => $registro,
                'produto' => $produto,
                'titulo' => $titulo,
                'titulo_atual' => $tituloAtual,
                'slug_atual' => $slugAtual,
                'slug_novo' => $slugNovo,
                'soltar_slug' => filled($registro->slug) && $slugFixo === null,
            ];
        }

        return [$trocas, $puladas];
    }

    /** Troca um produto e devolve a linha do backup. */
    private function aplicar(array $troca): array
    {
        /** @var FerraKoraProduto $registro */
        $registro = $troca['registro'];
        /** @var Product $produto */
        $produto = $troca['produto'];
        $titulo = $troca['titulo'];
        $nomeAntigo = trim((string) $produto->name);

        $linha = [
            'id' => $produto->id,
            'registro' => ['titulo' => $registro->titulo, 'slug' => $registro->slug],
            'slug_antes' => $troca['slug_atual'],
            'slug_depois' => $troca['slug_novo'],
            'titulo_novo' => $titulo,
            'so_ferrakora' => (bool) $produto->so_ferrakora,
            'produto' => null,
        ];

        // Com eventos: GuardaSlugAntigoDoSite guarda o endereço que sai.
        $registro->forceFill([
            'titulo' => $titulo,
            'slug' => $troca['soltar_slug'] ? null : $registro->slug,
        ])->save();

        if ($produto->so_ferrakora) {
            $linha['produto'] = [
                'name' => $produto->name,
                'seo_title' => $produto->seo_title,
                'termos_busca' => $produto->termos_busca,
                'nome_interno' => $produto->nome_interno,
            ];

            $produto->forceFill([
                'name' => $titulo,
                'termos_busca' => TitulosPersuasivos::juntarTermos($produto->termos_busca, $nomeAntigo),
                'nome_interno' => filled($produto->nome_interno) ? $produto->nome_interno : TitulosPersuasivos::semPdf($nomeAntigo),
                'seo_title' => trim((string) $produto->seo_title) === $nomeAntigo ? $titulo : $produto->seo_title,
            ])->saveQuietly();
        }

        return $linha;
    }

    private function desfazer(string $caminho): int
    {
        $relativo = Str::startsWith($caminho, '/') ? null : $caminho;
        $conteudo = $relativo !== null && Storage::disk('local')->exists($relativo)
            ? Storage::disk('local')->get($relativo)
            : (is_file($caminho) ? file_get_contents($caminho) : null);

        $backup = $conteudo ? json_decode((string) $conteudo, true) : null;
        if (! is_array($backup) || ! isset($backup['produtos'])) {
            $this->error("Backup não encontrado ou inválido: {$caminho}");

            return self::FAILURE;
        }

        $voltados = [];
        DB::transaction(function () use ($backup, &$voltados) {
            foreach ($backup['produtos'] as $linha) {
                $registro = FerraKoraProduto::query()->where('product_id', $linha['id'])->first();
                if ($registro === null || $registro->titulo !== $linha['titulo_novo']) {
                    $this->warn("id {$linha['id']}: o título mudou depois da troca ou o produto saiu do site; não mexi.");

                    continue;
                }

                // Com eventos: o endereço novo vira "antigo" e leva ao de antes.
                $registro->forceFill($linha['registro'])->save();

                $produto = Product::query()->find($linha['id']);
                if ($produto !== null && is_array($linha['produto']) && $produto->name === $linha['titulo_novo']) {
                    $produto->forceFill($linha['produto'])->saveQuietly();
                }

                $voltados[] = (int) $linha['id'];
            }
        });

        $this->limparCaches($voltados);
        $this->info(count($voltados).' produto(s) voltaram ao título antigo.');

        return self::SUCCESS;
    }

    /** @param  array<int, int>  $ids */
    private function limparCaches(array $ids): void
    {
        foreach ($ids as $id) {
            Cache::forget(CatalogController::chaveCacheDoProduto((int) $id));
        }
    }
}
