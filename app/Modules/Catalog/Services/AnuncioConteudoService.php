<?php

namespace App\Modules\Catalog\Services;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Models\ProductAdContent;
use App\Modules\Catalog\Support\GatilhosAnuncio;
use App\Modules\Catalog\Support\TextoIaSemPreco;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use App\Modules\WhatsApp\Services\GeminiUnavailableException;
use Illuminate\Support\Facades\Cache;
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
 *
 * Desde 2026-10-16 (pedido do Lira) a descrição segue desejo → quebra de
 * objeção → CTA, com um bloco por foto (até 10) e os 3 destaques como
 * promessas com check abaixo do nome.
 *
 * Desde 2026-10-14 também roda só com as fotos (até 6) e o nome, sem
 * descrição cadastrada: o Gemini descreve o que vê. O mesmo conteúdo serve
 * às três lojas do grupo (KazaKora, FerraKora pela API do catálogo e Beauty
 * Kora). descricaoPelasFotos() é o botão do admin que escreve a descrição
 * para quem cadastra revisar antes de salvar.
 *
 * Desde 2026-10-19 (pedido do Lira) os textos da KazaKora usam os gatilhos
 * psicológicos e levam emojis (GatilhosAnuncio). Desde 2026-10-20 nenhum
 * texto (de nenhuma loja) fala de preço ou parcelamento (TextoIaSemPreco).
 *
 * Desde 2026-10-20 (pedido do Lira) o conteúdo traz também o "cta": a
 * chamada para a compra logo depois das dúvidas comuns, com emojis, que
 * desperta desejo e derruba as últimas objeções com as políticas reais da
 * loja (POLITICAS). Conteúdo antigo sem CTA ganha só ele (ctaViaGemini),
 * sem refazer o resto.
 */
class AnuncioConteudoService
{
    private const MAX_IMAGENS = 10;

    private const MAX_BYTES_IMAGEM = 1_500_000;

    /**
     * Políticas reais de cada loja (as mesmas das Perguntas Frequentes da
     * página): é o que o CTA pode usar para quebrar objeção, nada além disso.
     */
    private const POLITICAS = [
        'KazaKora' => [
            'frete grátis acima de R$ 200 no Sul e Sudeste, até a sua casa',
            '7 dias após o recebimento para desistir da compra',
            '30 dias para trocar produto com defeito',
            'compra segura: recebe o produto ou o dinheiro volta',
            'empresa com CNPJ, vendido e enviado de São Paulo/SP',
            'código de rastreio em "Meus Pedidos" assim que o pedido é postado',
        ],
        'Beauty Kora' => [
            'frete grátis para todo o Brasil, até a sua casa',
            '7 dias após o recebimento para desistir da compra',
            '30 dias para trocar produto com defeito',
            'compra segura: recebe o produto ou o dinheiro volta',
            'empresa com CNPJ, vendido e enviado de São Paulo/SP',
            'código de rastreio em "Minhas Compras" assim que o pedido é postado',
        ],
    ];

    /** Prévia do botão do admin guardada para não pagar o Gemini de novo ao salvar. */
    private const PREVIA_HORAS = 6;

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

    /** Conteúdo do Gemini gerado antes do CTA existir (2026-10-20). */
    public function faltaCta(?object $conteudo): bool
    {
        return $conteudo !== null && $conteudo->fonte === ProductAdContent::FONTE_GEMINI && empty($conteudo->cta);
    }

    /**
     * Gera só o CTA de um conteúdo que já existe (chamada leve, sem fotos).
     * $lancarSeIndisponivel como em gerar(); outros erros só ficam no log.
     */
    public function completarCta(Product $product, bool $lancarSeIndisponivel = false): ?ProductAdContent
    {
        $product->loadMissing('adContent');
        $conteudo = $product->adContent;

        if (! $this->faltaCta($conteudo) || ! $this->gemini->isConfigured()) {
            return $conteudo;
        }

        try {
            $cta = $this->ctaViaGemini('KazaKora', $product->name, (string) $product->description, (array) $conteudo->duvidas);
        } catch (GeminiUnavailableException $exception) {
            if ($lancarSeIndisponivel) {
                throw $exception;
            }
            Log::warning('anuncio.cta_falhou', ['product_id' => $product->id, 'erro' => $exception->getMessage()]);

            return $conteudo;
        } catch (Throwable $exception) {
            Log::warning('anuncio.cta_falhou', ['product_id' => $product->id, 'erro' => $exception->getMessage()]);

            return $conteudo;
        }

        $conteudo->update(['cta' => $cta]);

        return $conteudo;
    }

    /**
     * Só o CTA, a partir do nome, da descrição e das dúvidas já geradas.
     * Lança exceção se o Gemini falhar ou não devolver um CTA válido.
     *
     * @param  array<int, array{0: string, 1: string}>  $duvidas
     * @return array{titulo: string, texto: string, garantias: array<int, string>, botao: string}
     */
    public function ctaViaGemini(string $loja, string $nome, string $descricao, array $duvidas): array
    {
        $listaDuvidas = collect($duvidas)
            ->filter(fn ($duvida) => is_array($duvida) && filled($duvida[0] ?? null))
            ->map(fn ($duvida) => '- '.$duvida[0].' '.($duvida[1] ?? ''))
            ->implode("\n") ?: '(nenhuma)';
        $base = trim($descricao) !== '' ? "Descrição cadastrada:\n{$descricao}" : 'Não há descrição cadastrada: use só o nome.';

        $prompt = <<<TXT
        Escreva a chamada final para a compra (CTA) da página deste produto da loja {$loja}. Ela aparece logo depois das dúvidas comuns abaixo, quando o cliente já entendeu o produto e só falta decidir.

        Produto: {$nome}
        {$base}

        Dúvidas comuns já respondidas na página:
        {$listaDuvidas}

        {$this->regrasCta($loja)}

        Formato (JSON):
        {{$this->formatoCta()}}
        TXT;

        $resposta = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => [['text' => $prompt]]]],
            'Você é redator de e-commerce brasileiro. Responde SOMENTE com um JSON válido, sem markdown e sem comentários.',
            // Folga para o raciocínio do modelo: com 1500 a resposta vinha cortada.
            4000,
            GeminiUsageLog::PURPOSE_AD_CONTENT,
        );

        $json = $this->extrairJson($resposta);
        $cta = $this->validarCta($json['cta'] ?? $json);

        if ($cta === null) {
            throw new RuntimeException('Gemini não devolveu um CTA válido.');
        }

        return $cta;
    }

    private function regrasCta(string $loja): string
    {
        // A Beauty Kora chega como "Beauty Kora (beleza e skincare)".
        $chave = collect(array_keys(self::POLITICAS))->first(fn ($nome) => str_starts_with($loja, $nome)) ?? 'KazaKora';
        $politicas = collect(self::POLITICAS[$chave])->map(fn ($p) => "  - {$p}")->implode("\n");
        $semPreco = TextoIaSemPreco::regra();

        return <<<TXT
        Regras do CTA ("cta"):
        - Português do Brasil, tom confiante e caloroso, frases curtas. Desperte desejo pelo resultado que o produto entrega e derrube as últimas objeções (vale a pena? chega bem? e se não gostar? é seguro comprar?).
        - Emojis pertinentes são obrigatórios no CTA, mesmo que o resto do conteúdo não leve: 1 ou 2 no título, 1 a 3 no texto, 1 no começo de cada garantia e 1 no botão.
        - Use gatilhos de vontade, urgência e segurança, sem inventar prazo, contador, quantidade em estoque, desconto, brinde, número de vendas, nota ou depoimento.
        - Nas garantias use SÓ estas políticas reais da loja (escolha as 3 ou 4 que mais quebram objeção para este produto, reescritas de forma curta e forte, falando com o cliente por "você"):
        {$politicas}
        - {$semPreco}
        - Sem HTML e sem markdown.
        TXT;
    }

    private function formatoCta(): string
    {
        return <<<'TXT'
        "cta": {"titulo": "frase de impacto até 80 caracteres", "texto": "2 a 3 frases que fazem o cliente se imaginar usando e decidir agora", "garantias": ["3 a 4 itens curtos (até 70 caracteres)"], "botao": "convite para comprar, até 28 caracteres"}
        TXT;
    }

    /**
     * @return array{titulo: string, texto: string, garantias: array<int, string>, botao: string}|null
     */
    private function validarCta(mixed $cta): ?array
    {
        if (! is_array($cta)) {
            return null;
        }

        $texto = fn ($valor, int $limite) => is_string($valor) ? Str::limit(TextoIaSemPreco::semPreco(trim(strip_tags($valor))), $limite, '…') : '';
        $titulo = $texto($cta['titulo'] ?? null, 120);
        $corpo = $texto($cta['texto'] ?? null, 500);

        if ($titulo === '' || $corpo === '') {
            return null;
        }

        // Emoji é obrigatório no CTA: o que vier sem ganha um padrão.
        $comEmoji = fn (string $valor, string $emoji, bool $noFim = true) => preg_match('/\p{Extended_Pictographic}/u', $valor)
            ? $valor : ($noFim ? "{$valor} {$emoji}" : "{$emoji} {$valor}");
        $botao = $texto($cta['botao'] ?? null, 40);

        return [
            'titulo' => $comEmoji($titulo, '✨'),
            'texto' => $comEmoji($corpo, '💛'),
            'garantias' => collect(is_array($cta['garantias'] ?? null) ? $cta['garantias'] : [])
                ->map(fn ($g) => $texto($g, 90))->filter()->map(fn ($g) => $comEmoji($g, '✅', false))->take(4)->values()->all(),
            // Botão vazio ou comprido demais vira o padrão.
            'botao' => $botao !== '' && mb_strlen($botao) <= 36 ? $comEmoji($botao, '🛒', false) : '🛒 Quero o meu agora',
        ];
    }

    /**
     * $lancarSeIndisponivel (lote do comando): quando o Gemini responde
     * 429/5xx, lança GeminiUnavailableException em vez de gravar a regra
     * automática, para quem chama esperar e tentar de novo.
     */
    public function gerar(Product $product, bool $forcar = false, bool $lancarSeIndisponivel = false): ProductAdContent
    {
        $product->loadMissing(['images', 'adContent']);
        $hash = $this->hash($product);

        if (! $forcar && $product->adContent && $product->adContent->origem_hash === $hash) {
            return $product->adContent;
        }

        $dados = null;
        $fonte = ProductAdContent::FONTE_GEMINI;
        $erro = null;
        $temBase = filled($product->description) || $product->images->isNotEmpty();

        // Descrição que veio do botão "Gerar descrição com IA pelas fotos" e
        // foi salva sem mudança: o conteúdo já foi gerado junto.
        $previa = $forcar ? null : Cache::pull($this->chavePrevia($hash));

        if (is_array($previa) && $previa !== []) {
            $dados = $previa;
        } elseif ($this->gemini->isConfigured() && $temBase) {
            try {
                $dados = $this->viaGemini($product);
            } catch (GeminiUnavailableException $exception) {
                if ($lancarSeIndisponivel) {
                    throw $exception;
                }
                $erro = Str::limit($exception->getMessage(), 1000);
                Log::warning('anuncio.gemini_falhou', ['product_id' => $product->id, 'erro' => $erro]);
            } catch (Throwable $exception) {
                $erro = Str::limit($exception->getMessage(), 1000);
                Log::warning('anuncio.gemini_falhou', ['product_id' => $product->id, 'erro' => $erro]);
            }
        } else {
            $erro = $temBase ? 'Gemini não configurado.' : 'Produto sem descrição e sem fotos.';
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

    /**
     * Botão do admin (2026-10-14): o Gemini olha as fotos (até 6) e o nome e
     * escreve a descrição do produto, mais o conteúdo da página. Nada é
     * gravado no produto: quem cadastra revisa, edita e salva. O conteúdo
     * fica guardado algumas horas e é usado se a descrição for salva igual.
     * Lança exceção se o Gemini falhar (o admin mostra a mensagem).
     *
     * @return array{descricao: string, conteudo: array<string, mixed>}
     */
    public function descricaoPelasFotos(Product $product): array
    {
        $product->loadMissing('images');

        if ($product->images->isEmpty()) {
            throw new RuntimeException('Cadastre pelo menos uma foto antes de gerar a descrição.');
        }

        if (! $this->gemini->isConfigured()) {
            throw new RuntimeException('Gemini não configurado.');
        }

        $dados = $this->gerarViaGemini('KazaKora', $product->name, '', $this->imagensDoProduto($product), min(self::MAX_IMAGENS, $product->images->count()), comDescricao: true);
        $descricao = (string) ($dados['descricao'] ?? '');
        unset($dados['descricao']);

        if ($descricao === '') {
            throw new RuntimeException('O Gemini não devolveu a descrição. Tente de novo.');
        }

        $hashFuturo = hash('sha256', implode('|', [$product->name, $descricao, $product->images->pluck('id')->implode(',')]));
        Cache::put($this->chavePrevia($hashFuturo), $dados, now()->addHours(self::PREVIA_HORAS));

        return ['descricao' => $descricao, 'conteudo' => $dados];
    }

    private function chavePrevia(string $hash): string
    {
        return "anuncio:previa:{$hash}";
    }

    /** @return array<int, string|null> índice => bytes da foto (miniatura quando existe) */
    private function imagensDoProduto(Product $product): array
    {
        $imagens = [];

        foreach ($product->images->take(self::MAX_IMAGENS)->values() as $indice => $imagem) {
            $caminho = (string) ($imagem->thumb_path ?: $imagem->path);
            $imagens[$indice] = rescue(fn () => Storage::disk('public')->get($caminho), null, report: false);
        }

        return $imagens;
    }

    /** @return array<string, mixed> */
    private function viaGemini(Product $product): array
    {
        $imagens = $this->imagensDoProduto($product);

        return $this->gerarViaGemini('KazaKora', $product->name, (string) $product->description, $imagens, count($imagens));
    }

    /**
     * Mesma geração para outra loja do grupo (Beauty Kora, 2026-10-12), que
     * não tem o produto no banco: recebe nome, descrição e o conteúdo das
     * fotos (índice => bytes, null quando não deu para baixar). Lança exceção
     * quando o Gemini falha; quem chama decide o plano B (automaticoDe).
     * Descrição vazia = o Gemini trabalha só com o nome e as fotos.
     * $comDescricao devolve também "descricao" (texto para o cadastro).
     *
     * @param  array<int, string|null>  $imagens
     * @return array<string, mixed>
     */
    public function gerarViaGemini(string $loja, string $nome, string $descricao, array $imagens, int $totalImagens, bool $comDescricao = false): array
    {
        $partes = [];

        foreach ($imagens as $indice => $conteudo) {
            if (! $conteudo || strlen($conteudo) > self::MAX_BYTES_IMAGEM) {
                continue;
            }

            $partes[] = ['text' => "Imagem {$indice}:"];
            $partes[] = ['inline_data' => ['mime_type' => $this->mime($conteudo), 'data' => base64_encode($conteudo)]];
        }

        if ($partes === [] && trim($descricao) === '') {
            throw new RuntimeException('Sem descrição e nenhuma foto pôde ser lida.');
        }

        $partes[] = ['text' => $this->prompt($loja, $nome, $descricao, $totalImagens, $comDescricao)];

        $resposta = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => $partes]],
            'Você é redator de e-commerce brasileiro. Responde SOMENTE com um JSON válido, sem markdown e sem comentários.',
            6000,
            GeminiUsageLog::PURPOSE_AD_CONTENT,
        );

        $json = $this->extrairJson($resposta);
        $dados = $this->validar($json, $totalImagens);

        if ($comDescricao) {
            $dados['descricao'] = $this->descricaoLimpa($json['descricao'] ?? null);
        }

        return $dados;
    }

    /** Texto puro, uma ideia por linha, sem linhas em branco repetidas. */
    private function descricaoLimpa(mixed $valor): string
    {
        if (! is_string($valor)) {
            return '';
        }

        $texto = str_replace(['**', '__', '#'], '', strip_tags($valor));
        $texto = preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto));

        return Str::limit(TextoIaSemPreco::semPreco(trim((string) $texto)), 4000, '');
    }

    /** Tipo da imagem pelos primeiros bytes (a URL da Hostinger nem sempre tem extensão). */
    private function mime(string $conteudo): string
    {
        return match (true) {
            str_starts_with($conteudo, "\x89PNG") => 'image/png',
            str_starts_with($conteudo, 'RIFF') && substr($conteudo, 8, 4) === 'WEBP' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    private function prompt(string $loja, string $nome, string $descricao, int $totalImagens, bool $comDescricao = false): string
    {
        $ultimaImagem = max(0, $totalImagens - 1);
        $temDescricao = trim($descricao) !== '';
        $base = $temDescricao
            ? "Descrição cadastrada:\n{$descricao}"
            : 'Não há descrição cadastrada: escreva a partir do nome e do que dá pra ver nas imagens (olhe todas, inclusive textos e medidas impressos nelas).';
        $fonte = $temDescricao ? 'da descrição e do que dá pra ver nas imagens' : 'do nome e do que dá pra ver nas imagens (incluindo textos escritos nelas)';
        $campoDescricao = $comDescricao
            ? "\n          \"descricao\": \"descrição completa para o cadastro do produto, em texto puro: um parágrafo de abertura, depois seções com um título terminado em ':' numa linha e o texto abaixo, e uma seção 'Características:' com itens começando por '- '\","
            : '';
        // Gatilhos e emojis valem só para a KazaKora (pedido do Lira, 2026-10-19);
        // a Beauty Kora usa o mesmo serviço e continua sem emoji.
        $kazakora = $loja === 'KazaKora';
        $semPreco = TextoIaSemPreco::regra();
        $estilo = $kazakora ? 'Sem HTML e sem markdown; emojis pertinentes em todos os textos (regra abaixo).' : 'Sem HTML, sem emojis, sem markdown.';
        $gatilhos = $kazakora ? "\n".GatilhosAnuncio::regras()."\n" : '';
        $etapas = $kazakora
            ? [' Gatilhos: necessidade, vontade e curiosidade.', ' Gatilhos: autoridade, prova social e reciprocidade.', ' Gatilhos: urgência e escassez.']
            : ['', '', ''];

        return <<<TXT
        Monte o conteúdo da página de venda deste produto da loja {$loja}.

        Produto: {$nome}
        {$base}

        Há {$totalImagens} imagem(ns), numeradas de 0 a {$ultimaImagem} (a 0 é a foto principal).

        Regras:
        - Português do Brasil, tom persuasivo e direto, focado em quebrar objeções de compra.
        - Use SÓ informações {$fonte}. Não invente medidas, materiais, garantias, prazos, brindes nem números.
        - {$estilo}
        - {$semPreco}
        - Em cada bloco escolha a imagem que melhor ilustra o texto (índice), sem repetir imagem entre blocos quando der; use null se nenhuma servir.
        - Use todas as imagens: um bloco para cada foto (até 10), na ordem que conta a história de venda, não na ordem das fotos.
        - "destaques" são as 3 promessas que aparecem com um check logo abaixo do nome do produto: o principal resultado, a principal facilidade e o que acompanha o produto.
        - Ordem dos blocos (estrutura de venda):
          1) Desejo: 2 a 3 blocos que mostram o resultado e a cena de uso (antes e depois, o problema resolvido no dia a dia).{$etapas[0]}
          2) Quebra de objeção: os blocos do meio respondem, cada um, a uma dúvida real de quem está para comprar (funciona mesmo, potência, tamanho, manutenção, durabilidade), com título em forma de pergunta ou de resposta.{$etapas[1]}
          3) CTA: o último bloco fecha a venda com o que vem na caixa e um convite direto para comprar, sem prometer prazo, brinde, garantia ou desconto que não esteja nas informações acima.{$etapas[2]}
        {$gatilhos}
        {$this->regrasCta($loja)}

        Formato (JSON):
        {{$campoDescricao}
          "destaques": ["3 benefícios curtos, até 60 caracteres cada"],
          "chamada": "uma frase curta com o principal benefício",
          "blocos": [{"titulo": "título forte", "texto": "2 a 3 frases persuasivas", "imagem": 1}],
          "comparativo": {"titulo": "...", "intro": "uma frase", "linhas": [["critério", "limitação da solução comum", "vantagem deste produto"]]},
          "beneficios": [["benefício curto", "explicação em uma linha"]],
          "duvidas": [["pergunta que o cliente faria", "resposta objetiva com base no que se sabe do produto"]],
          {$this->formatoCta()}
        }
        Quantidades: um bloco por imagem (mínimo 3, máximo 10, o último é o CTA), 3 a 4 linhas no comparativo, 4 a 6 benefícios, 3 a 4 dúvidas.
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
        $texto = fn ($valor, int $limite) => is_string($valor) ? Str::limit(TextoIaSemPreco::semPreco(trim(strip_tags($valor))), $limite, '…') : '';

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
            ->take(10)
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
            'cta' => $this->validarCta($dados['cta'] ?? null),
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

        return $this->automaticoDe($product->name, (string) $product->description, $product->images->count());
    }

    /** @return array<string, mixed> */
    public function automaticoDe(string $nome, string $descricao, int $totalImagens): array
    {
        $secoes = [];
        $atual = ['titulo' => null, 'textos' => [], 'itens' => []];

        foreach (preg_split('/\R/', $descricao) as $linha) {
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
                $secao['titulo'] = $nome;
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
            'cta' => null,
        ];
    }
}
