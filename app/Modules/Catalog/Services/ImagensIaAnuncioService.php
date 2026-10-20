<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductIaImage;
use App\Modules\Catalog\Support\ProductImageOptimizer;
use App\Modules\Marketplace\Support\SequenciaPsicologicaImagens as Sequencia;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Imagens novas do anúncio pelo Gemini (pedido do Lira 2026-10-19): na
 * edição do produto, além da descrição, gerar de 3 a 5 imagens seguindo a
 * sequência dele (despertar desejo → quebrar objeções → CTA poderoso).
 *
 * Em duas etapas, para cada chamada caber no tempo de uma requisição (o
 * homolog não tem fila rodando): planejar() pede ao modelo de texto uma
 * cena por imagem, olhando as fotos e a descrição, e grava os rascunhos
 * pendentes; gerar() desenha uma imagem por vez com as fotos reais como
 * referência. Nada vai para a loja sozinho: aprovar() coloca a imagem no
 * fim da galeria do produto, na ordem da sequência.
 */
class ImagensIaAnuncioService
{
    public const MIN = 3;

    public const MAX = 5;

    /** Fotos reais mandadas como referência do produto. */
    private const MAX_REFERENCIAS = 3;

    private const MAX_BYTES_REFERENCIA = 4_000_000;

    /** Etapa de cada imagem pela quantidade pedida: desejo primeiro, CTA sempre por último. */
    private const ETAPAS = [
        3 => [Sequencia::DESEJO, Sequencia::OBJECAO, Sequencia::CTA],
        4 => [Sequencia::DESEJO, Sequencia::DESEJO, Sequencia::OBJECAO, Sequencia::CTA],
        5 => [Sequencia::DESEJO, Sequencia::DESEJO, Sequencia::OBJECAO, Sequencia::OBJECAO, Sequencia::CTA],
    ];

    private const ORIENTACAO = [
        Sequencia::DESEJO => 'Despertar desejo: o resultado e a cena de uso real, bonita e verossímil, fazendo o comprador se imaginar com o produto.',
        Sequencia::OBJECAO => 'Quebrar objeção: responder visualmente a uma dúvida real de quem está para comprar (tamanho, encaixe, material, como funciona, o que vem), só com o que é verdade.',
        Sequencia::CTA => 'CTA poderoso: imagem de fechamento, produto protagonista e uma chamada curta para comprar agora, sem cupom, preço, frete ou promoção.',
    ];

    public function __construct(private readonly GeminiClient $gemini) {}

    /** @return Collection<int, ProductIaImage> */
    public function planejar(Product $product, int $quantidade): Collection
    {
        $product->loadMissing('images');
        $quantidade = max(self::MIN, min(self::MAX, $quantidade));

        if ($product->images->isEmpty()) {
            throw new RuntimeException('Cadastre pelo menos uma foto do produto: ela é a referência das imagens novas.');
        }

        if (! $this->gemini->isConfigured()) {
            throw new RuntimeException('Gemini não configurado.');
        }

        $etapas = self::ETAPAS[$quantidade];
        $partes = $this->referencias($product);
        $partes[] = ['text' => $this->promptPlano($product, $etapas)];

        $resposta = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => $partes]],
            'Você é diretor de arte de e-commerce brasileiro. Responde SOMENTE com um JSON válido, sem markdown e sem comentários.',
            4000,
            GeminiUsageLog::PURPOSE_AD_IMAGE,
        );

        $cenas = $this->cenas($resposta, count($etapas));
        $lote = (string) Str::ulid();

        return DB::transaction(fn () => collect($etapas)->map(fn (string $etapa, int $i) => $product->iaImages()->create([
            'lote' => $lote,
            'ordem' => $i,
            'etapa' => $etapa,
            'titulo' => $cenas[$i]['titulo'],
            'cena' => $cenas[$i]['cena'],
            'status' => ProductIaImage::STATUS_PENDENTE,
        ])));
    }

    /** Desenha (ou refaz) uma imagem do lote. Erro fica gravado no rascunho e é relançado. */
    public function gerar(ProductIaImage $rascunho): ProductIaImage
    {
        if ($rascunho->status === ProductIaImage::STATUS_APROVADA) {
            throw new RuntimeException('Esta imagem já foi aprovada e está na galeria do produto.');
        }

        $product = $rascunho->product()->with('images')->firstOrFail();
        $modelo = (string) config('services.gemini.image_model');

        try {
            $partes = $this->referencias($product);
            $partes[] = ['text' => $this->promptImagem($product, $rascunho)];

            [$bytes, $mime] = $this->gemini->image($modelo, $partes);

            $extensao = match ($mime) {
                'image/jpeg' => 'jpg',
                'image/webp' => 'webp',
                default => 'png',
            };
            $caminho = "products/{$product->id}/ia/".Str::ulid().".{$extensao}";
            Storage::disk('public')->put($caminho, $bytes);
            $caminho = ProductImageOptimizer::optimize($caminho) ?? $caminho;

            if ($rascunho->path && $rascunho->path !== $caminho) {
                Storage::disk('public')->delete($rascunho->path);
            }

            $rascunho->update(['status' => ProductIaImage::STATUS_PRONTA, 'path' => $caminho, 'modelo' => $modelo, 'erro' => null]);
        } catch (Throwable $exception) {
            $rascunho->update(['status' => ProductIaImage::STATUS_ERRO, 'erro' => Str::limit($exception->getMessage(), 1000)]);

            throw $exception;
        }

        return $rascunho;
    }

    /** A imagem entra no fim da galeria do produto (vai para a loja com o produto). */
    public function aprovar(ProductIaImage $rascunho): ProductIaImage
    {
        if ($rascunho->status !== ProductIaImage::STATUS_PRONTA || ! $rascunho->path) {
            throw new RuntimeException('Só dá para aprovar uma imagem já gerada.');
        }

        return DB::transaction(function () use ($rascunho) {
            $product = $rascunho->product;
            $total = $product->images()->count();

            $imagem = $product->images()->create([
                'path' => $rascunho->path,
                'position' => $total,
                'is_primary' => $total === 0,
            ]);

            $rascunho->update([
                'status' => ProductIaImage::STATUS_APROVADA,
                'product_image_id' => $imagem->id,
                'aprovada_em' => now(),
            ]);

            return $rascunho;
        });
    }

    public function descartar(ProductIaImage $rascunho): void
    {
        if ($rascunho->status === ProductIaImage::STATUS_APROVADA) {
            throw new RuntimeException('Imagem já aprovada: remova pela galeria de fotos.');
        }

        if ($rascunho->path) {
            Storage::disk('public')->delete($rascunho->path);
        }

        $rascunho->delete();
    }

    /** @return array<int, array<string, mixed>> */
    private function referencias(Product $product): array
    {
        $partes = [];

        foreach ($product->images->sortBy('position')->take(self::MAX_REFERENCIAS)->values() as $indice => $imagem) {
            $caminho = (string) ($imagem->medium_path ?: $imagem->path);
            $conteudo = rescue(fn () => Storage::disk('public')->get($caminho), null, report: false);

            if (! $conteudo || strlen($conteudo) > self::MAX_BYTES_REFERENCIA) {
                continue;
            }

            $partes[] = ['text' => "Foto real do produto {$indice}:"];
            $partes[] = ['inline_data' => ['mime_type' => $this->mime($conteudo), 'data' => base64_encode($conteudo)]];
        }

        if ($partes === []) {
            throw new RuntimeException('Nenhuma foto do produto pôde ser lida para servir de referência.');
        }

        return $partes;
    }

    /** @param  array<int, string>  $etapas */
    private function promptPlano(Product $product, array $etapas): string
    {
        $descricao = trim((string) $product->description) ?: '(sem descrição cadastrada: use o nome e o que dá para ver nas fotos)';
        $lista = collect($etapas)
            ->map(fn (string $etapa, int $i) => ($i + 1).') '.Sequencia::rotulo($etapa).': '.self::ORIENTACAO[$etapa])
            ->implode("\n");
        $total = count($etapas);

        return <<<TXT
        Planeje {$total} imagens novas para o anúncio deste produto da loja KazaKora (casa, utilidades e ferramentas).

        Produto: {$product->name}
        Descrição:
        {$descricao}

        As fotos acima são o produto real. As imagens vão seguir esta sequência psicológica, nesta ordem:
        {$lista}

        Regras:
        - Português do Brasil.
        - Use SÓ informações da descrição e do que dá pra ver nas fotos. Não invente medidas, materiais, acessórios, garantias, prêmios, avaliações nem números.
        - Cada imagem com função própria, sem repetir a cena de outra.
        - "cena" descreve a arte para o ilustrador: ambiente, enquadramento, luz, pessoa ou mão quando fizer sentido, e o texto curto que aparece na arte entre aspas (até 6 palavras; na de CTA, uma chamada para comprar).
        - Sem preço, cupom, desconto, frete, selo, logo de marketplace, número de sequência, colagem ou grade.

        Formato (JSON), exatamente {$total} itens, na ordem da sequência:
        {"imagens": [{"titulo": "nome curto da imagem", "cena": "descrição da arte em 2 a 4 frases"}]}
        TXT;
    }

    private function promptImagem(Product $product, ProductIaImage $rascunho): string
    {
        $orientacao = self::ORIENTACAO[$rascunho->etapa] ?? '';

        return <<<TXT
        Crie uma imagem comercial quadrada para o anúncio do produto "{$product->name}" (loja KazaKora), usando as fotos acima como referência do produto real.

        Objetivo desta imagem: {$orientacao}
        Cena: {$rascunho->cena}

        Regras obrigatórias:
        - O produto tem que ser idêntico ao das fotos: mesma cor, formato, proporção, material, rótulo e detalhes. Não acrescente peças nem acessórios.
        - Fotografia realista de alta qualidade, produto nítido e protagonista, pensada para a tela do celular.
        - Texto na arte só se a cena pedir: português do Brasil, curto, legível e sem erro de grafia.
        - Sem preço, cupom, desconto, frete grátis, garantia, selo, avaliação, logo de marketplace, marca d'água, número de sequência, colagem ou grade.
        TXT;
    }

    /** @return array<int, array{titulo: string, cena: string}> */
    private function cenas(string $resposta, int $total): array
    {
        $inicio = strpos($resposta, '{');
        $fim = strrpos($resposta, '}');
        $dados = $inicio === false || $fim === false ? null : json_decode(substr($resposta, $inicio, $fim - $inicio + 1), true);

        $cenas = collect(is_array($dados) ? ($dados['imagens'] ?? []) : [])
            ->filter(fn ($item) => is_array($item) && is_string($item['cena'] ?? null) && trim($item['cena']) !== '')
            ->map(fn (array $item) => [
                'titulo' => Str::limit(trim(strip_tags((string) ($item['titulo'] ?? ''))) ?: 'Imagem', 150, '…'),
                'cena' => Str::limit(trim(strip_tags($item['cena'])), 1500, '…'),
            ])
            ->values();

        if ($cenas->count() < $total) {
            throw new RuntimeException('A IA não devolveu as cenas das imagens. Tente de novo.');
        }

        return $cenas->take($total)->all();
    }

    private function mime(string $conteudo): string
    {
        return match (true) {
            str_starts_with($conteudo, "\x89PNG") => 'image/png',
            str_starts_with($conteudo, 'RIFF') && substr($conteudo, 8, 4) === 'WEBP' => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
