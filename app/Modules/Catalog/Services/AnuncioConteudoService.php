<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Conteúdo do anúncio da página do produto (pedido 2026-10-09): benefícios
 * curtos (abaixo das avaliações) e a descrição em blocos de título + texto
 * persuasivo de quebra de objeção + imagem, mais comparativo, lista de
 * benefícios e dúvidas.
 *
 * O cadastro só tem a descrição livre, então o Gemini lê a descrição e as
 * fotos e monta a estrutura (escolhendo qual foto acompanha cada bloco). Se
 * ele falhar por qualquer motivo (conexão, cota, JSON ruim), uma regra
 * própria monta a estrutura a partir da própria descrição — gerar() nunca
 * lança exceção, a página do produto nunca fica sem conteúdo.
 */
class AnuncioConteudoService
{
    private const MAX_IMAGENS = 6;

    private const MAX_BYTES_IMAGEM = 1_500_000;

    public function __construct(private readonly GeminiClient $gemini) {}

    /** Muda quando o nome, a descrição ou as fotos mudam. */
    public function hash(Product $product): string
    {
        $product->loadMissing('images');

        return hash('sha256', implode('|', [
            $product->name,
            (string) $product->description,
            $product->images->pluck('id')->implode(','),
        ]));
    }

    public function precisaGerar(Product $product): bool
    {
        $product->loadMissing('adContent');

        return ! $product->adContent || $product->adContent->origem_hash !== $this->hash($product);
    }

    public function gerar(Product $product, bool $forcar = false): ProductAdContent
    {
        $product->loadMissing(['images', 'adContent']);
        $hash = $this->hash($product);

        if (! $forcar && $product->adContent && $product->adContent->origem_hash === $hash) {
            return $product->adContent;
        }

        $dados = null;
        $fonte = ProductAdContent::FONTE_GEMINI;
        $erro = null;

        if ($this->gemini->isConfigured() && filled($product->description)) {
            try {
                $dados = $this->viaGemini($product);
            } catch (Throwable $exception) {
                $erro = Str::limit($exception->getMessage(), 1000);
                Log::warning('anuncio.gemini_falhou', ['product_id' => $product->id, 'erro' => $erro]);
            }
        } else {
            $erro = filled($product->description) ? 'Gemini não configurado.' : 'Produto sem descrição.';
        }

        if ($dados === null) {
            $dados = $this->automatico($product);
            $fonte = ProductAdContent::FONTE_AUTOMATICO;
        }

        $atributos = [...$dados, 'fonte' => $fonte, 'origem_hash' => $hash, 'erro' => $erro, 'gerado_em' => now()];

        try {
            $conteudo = ProductAdContent::query()->updateOrCreate(['product_id' => $product->id], $atributos);
            $product->setRelation('adContent', $conteudo);

            return $conteudo;
        } catch (Throwable $exception) {
            Log::error('anuncio.salvar_falhou', ['product_id' => $product->id, 'erro' => $exception->getMessage()]);

            return new ProductAdContent(['product_id' => $product->id, ...$atributos]);
        }
    }

    /** @return array<string, mixed> */
    private function viaGemini(Product $product): array
    {
        $partes = [];
        $imagens = $product->images->take(self::MAX_IMAGENS)->values();

        foreach ($imagens as $indice => $imagem) {
            $conteudo = rescue(fn () => Storage::disk('public')->get($imagem->thumb_path ?: $imagem->path), null, report: false);

            if (! $conteudo || strlen($conteudo) > self::MAX_BYTES_IMAGEM) {
                continue;
            }

            $mime = Str::endsWith(strtolower((string) ($imagem->thumb_path ?: $imagem->path)), '.png') ? 'image/png'
                : (Str::endsWith(strtolower((string) ($imagem->thumb_path ?: $imagem->path)), '.webp') ? 'image/webp' : 'image/jpeg');
            $partes[] = ['text' => "Imagem {$indice}:"];
            $partes[] = ['inline_data' => ['mime_type' => $mime, 'data' => base64_encode($conteudo)]];
        }

        $partes[] = ['text' => $this->prompt($product, $imagens->count())];

        $resposta = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => $partes]],
            'Você é redator de e-commerce brasileiro. Responde SOMENTE com um JSON válido, sem markdown e sem comentários.',
            6000,
            GeminiUsageLog::PURPOSE_AD_CONTENT,
        );

        return $this->validar($this->extrairJson($resposta), $imagens->count());
    }

    private function prompt(Product $product, int $totalImagens): string
    {
        $ultimaImagem = max(0, $totalImagens - 1);

        return <<<TXT
        Monte o conteúdo da página de venda deste produto da loja KazaKora.

        Produto: {$product->name}
        Descrição cadastrada:
        {$product->description}

        Há {$totalImagens} imagem(ns), numeradas de 0 a {$ultimaImagem} (a 0 é a foto principal).

        Regras:
        - Português do Brasil, tom persuasivo e direto, focado em quebrar objeções de compra.
        - Use SÓ informações da descrição e do que dá pra ver nas imagens. Não invente medidas, materiais, garantias, prazos, brindes nem números.
        - Sem HTML, sem emojis, sem markdown.
        - Em cada bloco escolha a imagem que melhor ilustra o texto (índice), sem repetir imagem entre blocos quando der; use null se nenhuma servir.

        Formato (JSON):
        {
          "destaques": ["3 benefícios curtos, até 60 caracteres cada"],
          "chamada": "uma frase curta com o principal benefício",
          "blocos": [{"titulo": "título forte", "texto": "2 a 3 frases persuasivas", "imagem": 1}],
          "comparativo": {"titulo": "...", "intro": "uma frase", "linhas": [["critério", "limitação da solução comum", "vantagem deste produto"]]},
          "beneficios": [["benefício curto", "explicação em uma linha"]],
          "duvidas": [["pergunta que o cliente faria", "resposta objetiva com base na descrição"]]
        }
        Quantidades: 3 a 5 blocos, 3 a 4 linhas no comparativo, 4 a 6 benefícios, 3 a 4 dúvidas.
        TXT;
    }

    /** @return array<string, mixed> */
    private function extrairJson(string $texto): array
    {
        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');

        if ($inicio === false || $fim === false || $fim < $inicio) {
            throw new RuntimeException('Gemini não devolveu JSON.');
        }

        $dados = json_decode(substr($texto, $inicio, $fim - $inicio + 1), true);

        if (! is_array($dados)) {
            throw new RuntimeException('JSON do Gemini inválido: '.json_last_error_msg());
        }

        return $dados;
    }

    /**
     * Limpa e confere o que veio do Gemini — qualquer coisa fora do formato
     * é descartada; sem blocos, cai na regra automática.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function validar(array $dados, int $totalImagens): array
    {
        $texto = fn ($valor, int $limite) => is_string($valor) ? Str::limit(trim(strip_tags($valor)), $limite, '…') : '';

        $blocos = collect($dados['blocos'] ?? [])
            ->filter(fn ($bloco) => is_array($bloco) && $texto($bloco['titulo'] ?? null, 140) !== '' && $texto($bloco['texto'] ?? null, 900) !== '')
            ->map(function ($bloco) use ($texto, $totalImagens) {
                $imagem = $bloco['imagem'] ?? null;

                return [
                    'titulo' => $texto($bloco['titulo'], 140),
                    'texto' => $texto($bloco['texto'], 900),
                    'imagem' => is_numeric($imagem) && (int) $imagem >= 0 && (int) $imagem < $totalImagens ? (int) $imagem : null,
                ];
            })
            ->take(6)
            ->values()
            ->all();

        if ($blocos === []) {
            throw new RuntimeException('Gemini não devolveu nenhum bloco válido.');
        }

        $pares = fn ($lista, int $limiteA, int $limiteB, int $max) => collect(is_array($lista) ? $lista : [])
            ->filter(fn ($item) => is_array($item) && $texto($item[0] ?? null, $limiteA) !== '')
            ->map(fn ($item) => [$texto($item[0], $limiteA), $texto($item[1] ?? '', $limiteB)])
            ->take($max)
            ->values()
            ->all();

        $comparativo = null;
        $linhas = collect($dados['comparativo']['linhas'] ?? [])
            ->filter(fn ($linha) => is_array($linha) && count($linha) >= 3)
            ->map(fn ($linha) => [$texto($linha[0], 60), $texto($linha[1], 140), $texto($linha[2], 140)])
            ->filter(fn ($linha) => $linha[0] !== '' && $linha[2] !== '')
            ->take(5)
            ->values()
            ->all();

        if ($linhas !== []) {
            $comparativo = [
                'titulo' => $texto($dados['comparativo']['titulo'] ?? null, 140) ?: 'Comparado ao jeito comum',
                'intro' => $texto($dados['comparativo']['intro'] ?? null, 300),
                'linhas' => $linhas,
            ];
        }

        return [
            'destaques' => collect($dados['destaques'] ?? [])->map(fn ($d) => $texto($d, 70))->filter()->take(3)->values()->all(),
            'chamada' => $texto($dados['chamada'] ?? null, 200) ?: null,
            'blocos' => $blocos,
            'comparativo' => $comparativo,
            'beneficios' => $pares($dados['beneficios'] ?? [], 80, 200, 8),
            'duvidas' => $pares($dados['duvidas'] ?? [], 160, 500, 6),
        ];
    }

    /**
     * Regra própria (sem IA): título terminado em ":" vira bloco, "- item" vira
     * benefício, e as fotos (a partir da 2ª) acompanham os blocos em ordem.
     *
     * @return array<string, mixed>
     */
    public function automatico(Product $product): array
    {
        $product->loadMissing('images');
        $secoes = [];
        $atual = ['titulo' => null, 'textos' => [], 'itens' => []];

        foreach (preg_split('/\R/', (string) $product->description) as $linha) {
            $linha = trim($linha);

            if ($linha === '') {
                continue;
            }

            if (Str::endsWith($linha, ':') && mb_strlen($linha) <= 120) {
                $secoes[] = $atual;
                $atual = ['titulo' => rtrim($linha, ':'), 'textos' => [], 'itens' => []];
            } elseif (preg_match('/^[-•]\s*(.+)$/u', $linha, $m)) {
                $atual['itens'][] = $m[1];
            } else {
                $atual['textos'][] = $linha;
            }
        }
        $secoes[] = $atual;

        $totalImagens = $product->images->count();
        $proximaImagem = $totalImagens > 1 ? 1 : 0;
        $chamada = null;
        $blocos = [];
        $itens = [];

        foreach ($secoes as $secao) {
            $itens = [...$itens, ...$secao['itens']];

            if ($secao['textos'] === []) {
                continue;
            }

            if ($secao['titulo'] === null) {
                $chamada ??= Str::limit($secao['textos'][0], 200, '…');
                $secao['titulo'] = $product->name;
            }

            $blocos[] = [
                'titulo' => Str::limit($secao['titulo'], 140, '…'),
                'texto' => Str::limit(implode(' ', $secao['textos']), 900, '…'),
                'imagem' => $totalImagens > 0 ? $proximaImagem : null,
            ];

            if ($totalImagens > 0) {
                $proximaImagem = ($proximaImagem + 1) % $totalImagens;
            }
        }

        $beneficios = collect($itens)
            ->map(fn ($item) => Str::contains($item, ':') ? [trim(Str::before($item, ':')), trim(Str::after($item, ':'))] : [$item, ''])
            ->take(8)
            ->values()
            ->all();

        $destaques = collect($itens)->filter(fn ($item) => mb_strlen($item) <= 70)->take(3)->values()->all();

        if ($destaques === []) {
            $destaques = collect(preg_split('/(?<=[.!?])\s+/u', (string) $chamada))->filter()->map(fn ($f) => Str::limit($f, 70, '…'))->take(3)->values()->all();
        }

        return [
            'destaques' => $destaques,
            'chamada' => $chamada,
            'blocos' => array_slice($blocos, 0, 6),
            'comparativo' => null,
            'beneficios' => $beneficios,
            'duvidas' => [],
        ];
    }
}
