<?php

namespace App\Modules\BeautyKora\Services;

use App\Modules\BeautyKora\Models\BeautyKoraProdutoConteudo;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Services\AnuncioConteudoService;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Página de produto do Beauty Kora com a mesma inteligência da KazaKora
 * (pedido 2026-10-12): o produto mora na loja da Hostinger (API pública da
 * vitrine), então lemos nome, descrição e fotos de lá e passamos pelo mesmo
 * AnuncioConteudoService (Gemini lendo texto + imagens; regra automática se
 * ele falhar). O resultado fica em beautykora_produto_conteudos e só é
 * refeito quando o nome, a descrição ou as fotos mudam.
 */
class ConteudoProdutoBeautyKora
{
    private const MAX_IMAGENS = 6;

    public function __construct(
        private readonly AnuncioConteudoService $anuncio,
        private readonly GeminiClient $gemini,
    ) {}

    /**
     * Produto da vitrine da Hostinger (10 min de cache). null = não existe.
     *
     * @return array{id: string, nome: string, descricao: string, imagens: array<int, string>}|null
     */
    public function produto(string $id): ?array
    {
        // Produto do catálogo vindo do KazaKora (2026-10-12): mesmo formato da vitrine.
        if (Str::startsWith($id, CatalogoBeautyKora::PREFIXO_PRODUTO)) {
            $produto = app(CatalogoBeautyKora::class)->produto($id);

            return $produto === null ? null : $this->normalizar($produto);
        }

        return Cache::remember("beautykora:produto:{$id}", now()->addMinutes(10), function () use ($id) {
            $resposta = Http::timeout(15)->acceptJson()->get($this->urlLoja()."/products/{$id}");

            if ($resposta->status() === 404 || ! is_array($resposta->json('product'))) {
                return null;
            }

            $resposta->throw();

            return $this->normalizar($resposta->json('product'));
        });
    }

    /** @return array<int, string> ids dos produtos publicados na vitrine */
    public function idsDaLoja(): array
    {
        return collect(Http::timeout(20)->acceptJson()->get($this->urlLoja().'/products', ['limit' => 200])->throw()->json('products') ?? [])
            ->merge(app(CatalogoBeautyKora::class)->produtos())
            ->pluck('id')
            ->filter()
            ->values()
            ->all();
    }

    public function hash(array $produto): string
    {
        return hash('sha256', implode('|', [$produto['nome'], $produto['descricao'], implode(',', $produto['imagens'])]));
    }

    /**
     * Conteúdo pronto (gera na hora se faltar ou estiver velho). null = o
     * produto não existe na loja. Nunca lança exceção por causa do Gemini.
     */
    public function conteudo(string $id, bool $forcar = false): ?BeautyKoraProdutoConteudo
    {
        $produto = $this->produto($id);

        if ($produto === null) {
            return null;
        }

        $hash = $this->hash($produto);
        $atual = BeautyKoraProdutoConteudo::query()->where('produto_id', $id)->first();

        if (! $forcar && $atual && $atual->origem_hash === $hash) {
            return $atual;
        }

        // Duas visitas ao mesmo tempo não pagam o Gemini duas vezes: quem
        // chega com a geração em andamento leva o conteúdo antigo (ou nada).
        $trava = Cache::lock("beautykora:conteudo:{$id}", 120);

        if (! $trava->get()) {
            return $atual;
        }

        try {
            return $this->gerar($produto, $hash);
        } finally {
            $trava->release();
        }
    }

    private function gerar(array $produto, string $hash): BeautyKoraProdutoConteudo
    {
        $dados = null;
        $fonte = ProductAdContent::FONTE_GEMINI;
        $erro = null;

        if ($this->gemini->isConfigured() && filled($produto['descricao'])) {
            try {
                $imagens = collect($produto['imagens'])->take(self::MAX_IMAGENS)
                    ->map(fn (string $url) => rescue(fn () => Http::timeout(15)->get($url)->throw()->body(), null, report: false))
                    ->all();
                $dados = $this->anuncio->gerarViaGemini('Beauty Kora (beleza e skincare)', $produto['nome'], $produto['descricao'], $imagens, count($imagens));
            } catch (Throwable $exception) {
                $erro = Str::limit($exception->getMessage(), 1000);
                Log::warning('beautykora.conteudo.gemini_falhou', ['produto_id' => $produto['id'], 'erro' => $erro]);
            }
        } else {
            $erro = filled($produto['descricao']) ? 'Gemini não configurado.' : 'Produto sem descrição.';
        }

        if ($dados === null) {
            $dados = $this->anuncio->automaticoDe($produto['nome'], $produto['descricao'], count($produto['imagens']));
            $fonte = ProductAdContent::FONTE_AUTOMATICO;
        }

        return BeautyKoraProdutoConteudo::query()->updateOrCreate(
            ['produto_id' => $produto['id']],
            [...$dados, 'fonte' => $fonte, 'origem_hash' => $hash, 'erro' => $erro, 'gerado_em' => now()],
        );
    }

    /** @return array{id: string, nome: string, descricao: string, imagens: array<int, string>} */
    private function normalizar(array $produto): array
    {
        $imagens = collect($produto['media'] ?? [])
            ->filter(fn ($midia) => ($midia['type'] ?? 'image') === 'image' && filled($midia['url'] ?? null))
            ->sortBy('order')
            ->pluck('url')
            ->values()
            ->all();

        if ($imagens === [] && filled($produto['thumbnail'] ?? null)) {
            $imagens = [$produto['thumbnail']];
        }

        return [
            'id' => (string) $produto['id'],
            'nome' => trim((string) ($produto['title'] ?? '')),
            'descricao' => self::textoDoHtml((string) ($produto['description'] ?? '')),
            'imagens' => $imagens,
        ];
    }

    /** Descrição da Hostinger vem em HTML: vira texto com uma ideia por linha e "- " nos itens. */
    public static function textoDoHtml(string $html): string
    {
        $texto = preg_replace(['/<li[^>]*>/i', '/<\/(p|div|h[1-6]|li|ul|ol)>|<br\s*\/?>/i'], ["\n- ", "\n"], $html);
        $texto = html_entity_decode(strip_tags((string) $texto), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $linhas = array_map(fn ($linha) => trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', $linha)), preg_split('/\R/', $texto));

        return trim(implode("\n", array_filter($linhas, fn ($linha) => $linha !== '' && $linha !== '-')));
    }

    private function urlLoja(): string
    {
        $config = config('services.beautykora.vitrine');

        return rtrim($config['url'], '/').'/store/'.$config['store_id'];
    }
}
