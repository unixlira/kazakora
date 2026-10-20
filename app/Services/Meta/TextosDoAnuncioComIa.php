<?php

namespace App\Services\Meta;

use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Support\TextoIaSemPreco;
use App\Modules\WhatsApp\Models\GeminiUsageLog;
use App\Modules\WhatsApp\Services\GeminiClient;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Botão "Gerar textos com IA" da tela de campanha Meta (pedido 2026-10-19):
 * o Gemini lê o produto (nome, descrição e o conteúdo da página, quando já
 * existe) e escreve 1 texto principal, 1 título e 1 descrição, mais 5
 * opções de cada. Nada é gravado: a tela preenche os campos e quem monta a
 * campanha revisa e salva. Lança exceção se o Gemini falhar (a tela mostra).
 */
class TextosDoAnuncioComIa
{
    public const ALTERNATIVOS = 5;

    /** Limites da tela (validação do MetaAdsController). */
    private const LIMITES = ['texto' => 2000, 'titulo' => 255, 'descricao' => 255];

    public function __construct(private readonly GeminiClient $gemini) {}

    /**
     * @return array{texto_principal: string, titulo: string, descricao: string, textos: list<string>, titulos: list<string>, descricoes: list<string>}
     */
    public function gerar(Product $product, string $campanha = ''): array
    {
        if (! $this->gemini->isConfigured()) {
            throw new RuntimeException('Gemini não configurado.');
        }

        $product->loadMissing(['adContent', 'category']);

        $resposta = $this->gemini->generate(
            (string) config('services.gemini.chat_model'),
            [['role' => 'user', 'parts' => [['text' => $this->prompt($product, $campanha)]]]],
            'Você é redator de anúncios de Facebook e Instagram para e-commerce brasileiro. Responde SOMENTE com um JSON válido, sem markdown e sem comentários.',
            4000,
            GeminiUsageLog::PURPOSE_META_ADS,
        );

        return $this->validar($this->extrairJson($resposta));
    }

    private function prompt(Product $product, string $campanha): string
    {
        $conteudo = $product->adContent;
        $contexto = array_filter([
            'Produto: '.$product->name,
            $product->category?->name ? 'Categoria: '.$product->category->name : null,
            filled($product->description) ? "Descrição cadastrada:\n".Str::limit(strip_tags((string) $product->description), 3000) : null,
            $conteudo?->chamada ? 'Frase principal da página: '.$conteudo->chamada : null,
            $conteudo?->destaques ? 'Destaques: '.implode('; ', $conteudo->destaques) : null,
            $conteudo?->beneficios ? 'Benefícios: '.implode('; ', array_map(fn ($b) => trim(implode(': ', array_filter((array) $b))), $conteudo->beneficios)) : null,
            filled($campanha) ? 'Nome da campanha (dá o tom da época, não precisa aparecer no texto): '.$campanha : null,
        ]);
        $contexto = implode("\n", $contexto);
        $n = self::ALTERNATIVOS;
        $semPreco = TextoIaSemPreco::regra();

        return <<<TXT
        Escreva os textos de um anúncio da loja KazaKora (kazakora.com) no Facebook e no Instagram para este produto.

        {$contexto}

        Regras:
        - Português do Brasil, tom direto, casual e persuasivo, falando com quem rola o feed.
        - Use SÓ as informações acima. Não invente medidas, materiais, garantia, prazo de entrega, frete grátis, brinde, preço, desconto nem selo.
        - {$semPreco}
        - Sem HTML, sem markdown, sem hashtags, sem travessão. No máximo 2 emojis no texto principal; nenhum no título e na descrição.
        - Texto principal: 2 a 4 frases curtas, gancho na primeira linha (até 125 caracteres), quebra uma objeção e termina com convite para comprar.
        - Título: até 40 caracteres, o benefício principal.
        - Descrição: até 30 caracteres, reforça o título.
        - As {$n} opções de cada um usam ângulos DIFERENTES entre si e do principal (resultado, dor resolvida, praticidade, prova pelo detalhe do produto, presente/ocasião). Não repita frase.

        Formato (JSON):
        {
          "texto_principal": "...",
          "titulo": "...",
          "descricao": "...",
          "textos": ["{$n} textos principais alternativos"],
          "titulos": ["{$n} títulos alternativos"],
          "descricoes": ["{$n} descrições alternativas"]
        }
        TXT;
    }

    /** @return array<string, mixed> */
    private function extrairJson(string $texto): array
    {
        $inicio = strpos($texto, '{');
        $fim = strrpos($texto, '}');

        if ($inicio === false || $fim === false || $fim < $inicio) {
            throw new RuntimeException('A IA não devolveu os textos. Tente de novo.');
        }

        $dados = json_decode(substr($texto, $inicio, $fim - $inicio + 1), true);

        if (! is_array($dados)) {
            throw new RuntimeException('A IA devolveu um formato inválido. Tente de novo.');
        }

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array{texto_principal: string, titulo: string, descricao: string, textos: list<string>, titulos: list<string>, descricoes: list<string>}
     */
    private function validar(array $dados): array
    {
        $limpo = function ($valor, int $limite): string {
            if (! is_string($valor)) {
                return '';
            }
            $texto = str_replace(['**', '__', '—', '–'], ['', '', ',', ','], strip_tags($valor));

            return Str::limit(TextoIaSemPreco::semPreco(trim((string) preg_replace("/\n{3,}/", "\n\n", str_replace("\r", '', $texto)))), $limite, '');
        };
        $lista = fn ($valores, string $principal, int $limite) => array_values(array_slice(array_unique(array_filter(
            array_map(fn ($v) => $limpo($v, $limite), is_array($valores) ? $valores : []),
            fn (string $t) => $t !== '' && $t !== $principal,
        )), 0, self::ALTERNATIVOS));

        $texto = $limpo($dados['texto_principal'] ?? null, self::LIMITES['texto']);
        $titulo = $limpo($dados['titulo'] ?? null, self::LIMITES['titulo']);
        $descricao = $limpo($dados['descricao'] ?? null, self::LIMITES['descricao']);

        if ($texto === '' || $titulo === '') {
            throw new RuntimeException('A IA não devolveu o texto principal e o título. Tente de novo.');
        }

        return [
            'texto_principal' => $texto,
            'titulo' => $titulo,
            'descricao' => $descricao,
            'textos' => $lista($dados['textos'] ?? [], $texto, self::LIMITES['texto']),
            'titulos' => $lista($dados['titulos'] ?? [], $titulo, self::LIMITES['titulo']),
            'descricoes' => $lista($dados['descricoes'] ?? [], $descricao, self::LIMITES['descricao']),
        ];
    }
}
