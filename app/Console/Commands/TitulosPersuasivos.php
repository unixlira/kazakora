<?php

namespace App\Console\Commands;

use App\Modules\BeautyKora\Services\CatalogoBeautyKora;
use App\Modules\Catalog\Http\Controllers\CatalogController;
use App\Modules\Catalog\Http\Controllers\GoogleMerchantFeedController;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\FerraKora\Services\CatalogoFerraKora;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Títulos persuasivos da loja KazaKora (pedido do Lira 25/11/2026): troca o
 * name dos produtos pelo título novo de um CSV "id;titulo_novo" (ou
 * "id;nome_atual;titulo_novo": aí a linha só vale se o nome atual ainda for
 * aquele).
 *
 * Sem --aplicar só mostra. Com --aplicar, antes de mexer grava um backup em
 * storage/app/titulos-persuasivos/ e, por produto:
 *  - termos_busca recebe o nome antigo (mais o que já tinha): a busca do
 *    site, do admin, da API e da Manuela continua achando "lixeira";
 *  - nome_interno vazio recebe o nome antigo sem o "+ PDF ...": é o nome
 *    que vai no item do pedido, na NF-e e na separação (nomeParaPedido());
 *  - seo_title igual ao nome antigo vira o título novo;
 *  - o slug NÃO muda (links e anúncios da Meta usam o slug);
 *  - FerraKora/Beauty Kora: o registro do site sem título próprio ganha o
 *    título que publica hoje, para o site irmão não mudar;
 *  - grava sem eventos (saveQuietly): nada de observer, auditoria ou
 *    sincronização. O name não vai para Mercado Livre/Shopee/TikTok/Amazon/
 *    Bling depois do anúncio criado (só no anúncio novo, criado à mão).
 *
 * --desfazer=<backup> volta tudo o que o backup registrou.
 *
 * Fora sempre: travesseiro (515, 516 e qualquer nome com "travesseiro"),
 * Livro Falante (213, 620, 621, 622) e produto só da FerraKora.
 */
class TitulosPersuasivos extends Command
{
    protected $signature = 'produtos:titulos-persuasivos
        {arquivo? : CSV id;titulo_novo (ou id;nome_atual;titulo_novo), separado por ponto e vírgula}
        {--aplicar : Grava; sem a opção só mostra o que mudaria}
        {--desfazer= : Caminho do backup (storage/app/titulos-persuasivos/...) para voltar os nomes}';

    protected $description = 'Troca o nome dos produtos da KazaKora pelos títulos persuasivos de um CSV (com backup e volta)';

    public const PASTA = 'titulos-persuasivos';

    /** Travesseiro e Livro Falante ficam fora (pedido do Lira 25/11/2026). */
    public const FORA = [515, 516, 213, 620, 621, 622];

    public function handle(): int
    {
        if (filled($this->option('desfazer'))) {
            return $this->desfazer((string) $this->option('desfazer'));
        }

        $arquivo = (string) $this->argument('arquivo');
        if ($arquivo === '' || ! is_file($arquivo)) {
            $this->error('Informe o CSV (id;titulo_novo). Arquivo não encontrado: '.$arquivo);

            return self::FAILURE;
        }

        [$trocas, $puladas] = $this->lerCsv($arquivo);

        foreach ($puladas as $linha) {
            $this->warn("Pulado: {$linha}");
        }

        if ($trocas === []) {
            $this->info('Nada para trocar.');

            return self::SUCCESS;
        }

        $this->table(['id', 'nome atual', 'título novo'], array_map(fn (array $t) => [$t['produto']->id, $t['produto']->name, $t['titulo']], $trocas));

        if (! $this->option('aplicar')) {
            $this->info(count($trocas).' produto(s) mudariam. Nada gravado (use --aplicar).');

            return self::SUCCESS;
        }

        $caminho = self::PASTA.'/backup-'.now()->format('Ymd-His').'.json';
        $backup = ['criado_em' => now()->toIso8601String(), 'arquivo' => $arquivo, 'produtos' => []];

        // O backup é gravado dentro da transação: se não der para gravar, nada muda.
        DB::transaction(function () use ($trocas, &$backup, $caminho) {
            foreach ($trocas as $troca) {
                $backup['produtos'][] = $this->aplicar($troca['produto'], $troca['titulo']);
            }

            if (! Storage::disk('local')->put($caminho, json_encode($backup, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))) {
                throw new \RuntimeException("Não consegui gravar o backup {$caminho}; nada foi trocado.");
            }
        });

        $this->limparCaches(array_column($backup['produtos'], 'id'));

        $this->info(count($backup['produtos']).' produto(s) trocados. Backup: '.Storage::disk('local')->path($caminho));
        $this->line("Para voltar: php artisan produtos:titulos-persuasivos --desfazer={$caminho}");

        return self::SUCCESS;
    }

    /**
     * @return array{0: array<int, array{produto: Product, titulo: string}>, 1: array<int, string>}
     */
    private function lerCsv(string $arquivo): array
    {
        $trocas = [];
        $puladas = [];
        $vistos = [];

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
            $nomeEsperado = count($colunas) >= 3 ? $colunas[1] : null;
            $produto = Product::query()->find((int) $id);
            $rotulo = 'linha '.($numero + 1)." (id {$id})";

            $motivo = match (true) {
                $produto === null => 'produto não existe',
                isset($vistos[$id]) => 'id repetido no CSV',
                in_array((int) $id, self::FORA, true) => 'fora do escopo (travesseiro/Livro Falante)',
                Str::contains(Str::lower((string) $produto->name), 'travesseiro') => 'fora do escopo (travesseiro)',
                (bool) $produto->so_ferrakora => 'produto só da FerraKora',
                count($colunas) < 2 || $titulo === '' => 'sem título novo',
                mb_strlen($titulo) > 255 => 'título com mais de 255 caracteres',
                $nomeEsperado !== null && $nomeEsperado !== trim((string) $produto->name) => 'o nome atual não é mais o do CSV',
                $titulo === trim((string) $produto->name) => 'título igual ao nome atual',
                default => null,
            };

            if ($motivo !== null) {
                $puladas[] = "{$rotulo}: {$motivo}";

                continue;
            }

            $vistos[$id] = true;
            $trocas[] = ['produto' => $produto, 'titulo' => $titulo];
        }

        return [$trocas, $puladas];
    }

    /** Troca um produto e devolve a linha do backup. */
    private function aplicar(Product $produto, string $titulo): array
    {
        $antigo = trim((string) $produto->name);
        $linha = [
            'id' => $produto->id,
            'slug' => $produto->slug,
            'name' => $produto->name,
            'seo_title' => $produto->seo_title,
            'termos_busca' => $produto->termos_busca,
            'nome_interno' => $produto->nome_interno,
            'titulo_novo' => $titulo,
            'sites' => [],
        ];

        // Antes de trocar o nome: o site irmão guarda o título que publica hoje.
        foreach (self::catalogos() as $site => $catalogo) {
            $fixado = $catalogo->fixarTituloAtual($produto);
            if ($fixado !== null) {
                $linha['sites'][$site] = $fixado;
            }
        }

        $produto->forceFill([
            'name' => $titulo,
            'termos_busca' => self::juntarTermos($produto->termos_busca, $antigo),
            'nome_interno' => filled($produto->nome_interno) ? $produto->nome_interno : self::semPdf($antigo),
            'seo_title' => trim((string) $produto->seo_title) === $antigo ? $titulo : $produto->seo_title,
            'slug' => $produto->slug,
        ])->saveQuietly();

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
                $produto = Product::query()->find($linha['id']);
                if ($produto === null) {
                    $this->warn("id {$linha['id']}: produto não existe mais.");

                    continue;
                }
                if ($produto->name !== $linha['titulo_novo']) {
                    $this->warn("id {$linha['id']}: o nome mudou depois da troca (\"{$produto->name}\"); não mexi.");

                    continue;
                }

                $produto->forceFill([
                    'name' => $linha['name'],
                    'seo_title' => $linha['seo_title'],
                    'termos_busca' => $linha['termos_busca'],
                    'nome_interno' => $linha['nome_interno'],
                ])->saveQuietly();

                foreach ($linha['sites'] ?? [] as $site => $titulo) {
                    (self::catalogos()[$site] ?? null)?->soltarTitulo($produto->id, $titulo);
                }

                $voltados[] = $produto->id;
            }
        });

        $this->limparCaches($voltados);
        $this->info(count($voltados).' produto(s) voltaram ao nome antigo.');

        return self::SUCCESS;
    }

    /** @return array<string, CatalogoOutroSite> */
    private static function catalogos(): array
    {
        return ['ferrakora' => app(CatalogoFerraKora::class), 'beautykora' => app(CatalogoBeautyKora::class)];
    }

    /** Termos que já existiam + o nome antigo, sem repetir. */
    public static function juntarTermos(?string $existentes, string $nomeAntigo): string
    {
        $termos = collect(explode('|', (string) $existentes))->map(fn ($t) => trim($t))->filter();
        if (! $termos->contains(fn ($t) => Str::lower($t) === Str::lower($nomeAntigo))) {
            $termos->push($nomeAntigo);
        }

        return $termos->implode(' | ');
    }

    /** "Lixeira 12 L + PDF Grátis" -> "Lixeira 12 L": o nome da NF-e não promete PDF. */
    public static function semPdf(string $nome): string
    {
        $limpo = preg_replace('/\s*\+\s*(guia\s+|livro\s+|e-?book\s+)?(pdf|e-?book|livro digital)\b.*$/iu', '', $nome);

        return trim((string) ($limpo !== '' && $limpo !== null ? $limpo : $nome));
    }

    /** @param  array<int, int>  $ids */
    private function limparCaches(array $ids): void
    {
        foreach ($ids as $id) {
            Cache::forget(CatalogController::chaveCacheDoProduto((int) $id));
        }
        foreach (['home:mais-buscados', 'home:utilidades', GoogleMerchantFeedController::CACHE] as $chave) {
            Cache::forget($chave);
        }
    }
}
