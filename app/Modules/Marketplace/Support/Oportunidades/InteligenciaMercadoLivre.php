<?php

namespace App\Modules\Marketplace\Support\Oportunidades;

use App\Modules\Catalog\Models\Product;
use Illuminate\Support\Str;

/**
 * Botão "Inteligência" de Oportunidades › Mercado Livre (pedido do Lira
 * 2026-10-13): passa os mais vendidos da categoria pelo filtro "demanda,
 * concorrência e margem" e separa em Oportunidades, Já vendemos, Evitar
 * (guerra de preço) e Outros.
 *
 * - Demanda: a API do ML não dá unidades vendidas; o sinal é a posição no
 *   ranking "Mais vendidos" da categoria.
 * - Concorrência: vendedores do produto de catálogo, ou marca forte no nome.
 * - Margem: regra do Rafael (preço mínimo ≈ custo ÷ 0,56 deixa ~15%). É uma
 *   aproximação: a decisão final passa pela ContributionMargin.
 *
 * Só leitura: não mexe em preço, anúncio nem nada no ML.
 */
class InteligenciaMercadoLivre
{
    /**
     * Fator da regra do Rafael: vendendo a custo ÷ 0,56 sobram ~15% depois de
     * taxa do ML, frete, imposto e ADS. Usado nos dois sentidos: custo máximo
     * = preço × 0,56 e piso de preço = custo ÷ 0,56.
     */
    public const FATOR_MARGEM_15 = 0.56;

    /** A partir de quantos vendedores no catálogo é guerra de preço. */
    public const VENDEDORES_GUERRA = 20;

    /** Oportunidade: pouca concorrência e ticket que cabe na operação. */
    public const VENDEDORES_OPORTUNIDADE = 5;

    public const PRECO_OPORTUNIDADE_MIN = 20.0;

    public const PRECO_OPORTUNIDADE_MAX = 200.0;

    /** Casamento por nome com o nosso catálogo (Jaccard dos tokens). */
    public const SIMILARIDADE_MINIMA = 0.5;

    /**
     * Marcas fortes: o dono da marca ou revendedor grande segura o preço no
     * chão; para a KazaKora é guerra de preço perdida. Comparação sem acento.
     */
    public const MARCAS_FORTES = [
        'omo', 'ypê', 'ype', 'huggies', 'pampers', 'johnson', 'growth', 'max titanium', 'integralmedica', 'dux',
        'black skull', 'lorenzetti', 'tramontina', 'xiaomi', 'redmi', 'samsung', 'motorola', 'iphone', 'apple',
        'jbl', 'sony', 'playstation', 'xbox', 'nintendo', 'starlink', 'amazon echo', 'alexa', 'makita',
        'vonder', 'bosch', 'dewalt', 'mondial', 'electrolux', 'philips', 'britania', 'arno', 'oster',
        'gift card', 'pokémon', 'pokemon', 'hot wheels', 'mattel', 'barbie', 'lego', 'hasbro', 'panini',
        'nike', 'adidas', 'havaianas', 'natura', 'boticário', 'boticario', 'loreal', "l'oreal", 'nivea',
        'colgate', 'gillette', 'stanley', 'garmin', 'lg', 'multilaser', 'intelbras',
    ];

    /** Palavras que não ajudam a dizer se dois produtos são o mesmo. */
    private const STOPWORDS = [
        'de', 'da', 'do', 'das', 'dos', 'para', 'pra', 'com', 'sem', 'em', 'no', 'na', 'nos', 'nas', 'um', 'uma',
        'por', 'and', 'the', 'cor', 'kit', 'conjunto', 'jogo', 'novo', 'nova', 'original', 'mini', 'super',
        'premium', 'top', 'promocao', 'oferta', 'unidade', 'unidades', 'pecas', 'peca', 'tamanho', 'modelo',
        'azul', 'rosa', 'vermelho', 'vermelha', 'preto', 'preta', 'branco', 'branca', 'verde', 'amarelo',
        'amarela', 'roxo', 'roxa', 'cinza', 'bege', 'lilas', 'laranja', 'colorido', 'colorida', 'sortido',
        'menino', 'menina', 'unissex', 'cm', 'mm', 'ml', 'kg', 'litros', 'litro', 'aro', 'anos',
    ];

    /** @var list<array{id:int, nome:string, preco:float, custo:?float, tokens:list<string>}>|null */
    private ?array $catalogo = null;

    /**
     * Recebe o resultado de MaisVendidosMercadoLivre::analisar() e devolve os
     * grupos, o resumo e os mesmos avisos (ocultos, erro, atualizado_em).
     */
    public function classificar(array $analise): array
    {
        $grupos = ['oportunidade' => [], 'ja_vendemos' => [], 'evitar' => [], 'outros' => []];

        foreach ($analise['produtos'] ?? [] as $produto) {
            $item = $this->item($produto);
            $grupos[$item['grupo']][] = $item;
        }

        foreach ($grupos as &$lista) {
            usort($lista, fn (array $a, array $b) => $a['posicao'] <=> $b['posicao']);
        }
        unset($lista);

        $resumo = array_map('count', $grupos) + ['total' => count($analise['produtos'] ?? [])];
        $resumo['texto'] = $this->texto($resumo);

        return [
            'resumo' => $resumo,
            'grupos' => $grupos,
            'ocultos' => (int) ($analise['ocultos'] ?? 0),
            'atualizado_em' => $analise['atualizado_em'] ?? null,
            'erro' => $analise['erro'] ?? null,
        ];
    }

    private function item(array $p): array
    {
        $precoMin = isset($p['preco_min']) ? (float) $p['preco_min'] : null;
        $precoMax = isset($p['preco_max']) ? (float) $p['preco_max'] : $precoMin;
        $vendedores = isset($p['vendedores']) ? (int) $p['vendedores'] : null;
        $nome = (string) ($p['nome'] ?? '');

        $motivoGuerra = null;
        if ($vendedores !== null && $vendedores >= self::VENDEDORES_GUERRA) {
            $motivoGuerra = "{$vendedores} vendedores";
        } elseif ($marca = $this->marcaForte($nome)) {
            $motivoGuerra = "marca forte: {$marca}";
        }

        $custoMax = $precoMin !== null ? round($precoMin * self::FATOR_MARGEM_15, 2) : null;
        $nosso = $this->nossoProduto($nome, (bool) ($p['voce_vende'] ?? false));
        $veredito = $nosso ? $this->veredito($nosso, $precoMin, $precoMax, $custoMax) : null;

        $grupo = match (true) {
            $nosso !== null || (bool) ($p['voce_vende'] ?? false) => 'ja_vendemos',
            $motivoGuerra !== null => 'evitar',
            $vendedores !== null && $vendedores <= self::VENDEDORES_OPORTUNIDADE
                && $precoMin !== null && $precoMin >= self::PRECO_OPORTUNIDADE_MIN && $precoMin <= self::PRECO_OPORTUNIDADE_MAX => 'oportunidade',
            default => 'outros',
        };

        if ($grupo === 'ja_vendemos' && $nosso === null) {
            $veredito = 'A KazaKora vende no ML, mas não achamos o produto no catálogo pelo nome.';
        }

        $curto = $this->nomeCurto($nome);

        return [
            'id' => (string) ($p['id'] ?? ''),
            'posicao' => (int) ($p['posicao'] ?? 0),
            'nome' => $nome,
            'imagem' => $p['imagem'] ?? null,
            'link' => (string) ($p['link'] ?? ''),
            'preco_min' => $precoMin,
            'preco_max' => $precoMax,
            'vendedores' => $vendedores,
            'voce_vende' => (bool) ($p['voce_vende'] ?? false),
            'custo_max_15' => $custoMax,
            'guerra_de_preco' => $motivoGuerra !== null,
            'motivo_guerra' => $motivoGuerra,
            'grupo' => $grupo,
            'nosso' => $nosso,
            'veredito' => $veredito,
            'videos' => [
                'tiktok' => 'https://www.tiktok.com/search?q='.rawurlencode($curto),
                'youtube' => 'https://www.youtube.com/results?search_query='.rawurlencode($curto).'&sp=EgIYAQ%253D%253D',
            ],
        ];
    }

    private function veredito(array $nosso, ?float $precoMin, ?float $precoMax, ?float $custoMax): string
    {
        if ($nosso['piso_15'] === null) {
            return 'Sem custo cadastrado no produto: não dá para calcular o piso de 15%.';
        }
        if ($precoMin === null) {
            return 'Sem preço de vendedor no catálogo do ML para comparar.';
        }
        $piso = $nosso['piso_15'];
        if ($precoMin >= $piso) {
            return 'Dá margem: o menor preço do ML ('.$this->reais($precoMin).') está acima do nosso piso ('.$this->reais($piso).').';
        }
        if ($precoMax !== null && $precoMax >= $piso) {
            return 'Cabe entre '.$this->reais($piso).' e '.$this->reais($precoMax).'.';
        }

        return 'Não compete: mercado abaixo do nosso piso de '.$this->reais($piso)
            .' (custo precisaria ser ≤ '.$this->reais((float) $custoMax).').';
    }

    /**
     * Produto ativo da KazaKora parecido com o do ML. Pelo nome é só
     * "parecido" (confirmado = false); com `voce_vende` (nossa conta está no
     * catálogo do produto) fica confirmado e pega o nome mais próximo.
     */
    private function nossoProduto(string $nome, bool $voceVende): ?array
    {
        $tokens = $this->tokens($nome);
        if (! $tokens) {
            return null;
        }

        $melhor = null;
        $melhorNota = 0.0;
        foreach ($this->catalogo() as $produto) {
            $nota = $this->similaridade($tokens, $produto['tokens']);
            if ($nota > $melhorNota) {
                [$melhor, $melhorNota] = [$produto, $nota];
            }
        }

        if (! $melhor || (! $voceVende && $melhorNota < self::SIMILARIDADE_MINIMA)) {
            return null;
        }

        return [
            'id' => $melhor['id'],
            'nome' => $melhor['nome'],
            'preco' => $melhor['preco'],
            'custo' => $melhor['custo'],
            'piso_15' => $melhor['custo'] ? round($melhor['custo'] / self::FATOR_MARGEM_15, 2) : null,
            'confirmado' => $voceVende,
            'similaridade' => round($melhorNota, 2),
        ];
    }

    /**
     * Jaccard dos tokens significativos. Como os títulos do ML são longos
     * ("Cozinha Infantil Brinquedo Com Luz 33 Acessórios...") e os nossos
     * curtos, também vale o "mesmo produto principal": a primeira palavra de
     * cada nome aparece no outro e há pelo menos 2 palavras em comum. Nesse
     * caso a nota sobe para o mínimo.
     */
    private function similaridade(array $a, array $b): float
    {
        if (! $a || ! $b) {
            return 0.0;
        }
        $comuns = count(array_intersect($a, $b));
        $jaccard = $comuns / count(array_unique([...$a, ...$b]));

        if ($comuns >= 2 && in_array($a[0], $b, true) && in_array($b[0], $a, true)) {
            return max($jaccard, self::SIMILARIDADE_MINIMA);
        }

        return $jaccard;
    }

    /** @return list<array{id:int, nome:string, preco:float, custo:?float, tokens:list<string>}> */
    private function catalogo(): array
    {
        return $this->catalogo ??= Product::query()
            ->where('is_active', true)
            ->get(['id', 'name', 'price', 'cost_price'])
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'nome' => (string) $p->name,
                'preco' => (float) $p->price,
                'custo' => $p->cost_price !== null && (float) $p->cost_price > 0 ? (float) $p->cost_price : null,
                'tokens' => $this->tokens((string) $p->name),
            ])
            ->filter(fn (array $p) => $p['tokens'])
            ->values()
            ->all();
    }

    /** Tokens significativos, sem acento, sem stopword, sem número/medida, no singular simples. @return list<string> */
    private function tokens(string $nome): array
    {
        $partes = preg_split('/[^a-z0-9]+/', Str::lower(Str::ascii($nome)), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tokens = [];
        foreach ($partes as $t) {
            if (strlen($t) < 3 || preg_match('/\d/', $t) || in_array($t, self::STOPWORDS, true)) {
                continue;
            }
            if (strlen($t) > 4 && str_ends_with($t, 's')) {
                $t = substr($t, 0, -1);
            }
            $tokens[] = $t;
        }

        return array_values(array_unique($tokens));
    }

    private function marcaForte(string $nome): ?string
    {
        $texto = ' '.preg_replace('/[^a-z0-9\']+/', ' ', Str::lower(Str::ascii($nome))).' ';
        foreach (self::MARCAS_FORTES as $marca) {
            $alvo = trim(preg_replace('/[^a-z0-9\']+/', ' ', Str::lower(Str::ascii($marca))));
            if (str_contains($texto, " {$alvo} ")) {
                return Str::title($marca);
            }
        }

        return null;
    }

    /** ~5 primeiras palavras do nome, para buscar vídeos. */
    private function nomeCurto(string $nome): string
    {
        $palavras = preg_split('/\s+/', trim(preg_replace('/[\/|,()\-–]+/u', ' ', $nome))) ?: [];

        return implode(' ', array_slice($palavras, 0, 5));
    }

    private function texto(array $resumo): string
    {
        $plural = fn (int $n, string $um, string $varios) => $n.' '.($n === 1 ? $um : $varios);

        return implode(', ', [
            $plural($resumo['oportunidade'], 'oportunidade', 'oportunidades'),
            $plural($resumo['ja_vendemos'], 'que já vendemos', 'que já vendemos'),
            $plural($resumo['evitar'], 'para evitar', 'para evitar'),
        ]).'.';
    }

    private function reais(float $valor): string
    {
        return 'R$ '.number_format($valor, 2, ',', '.');
    }
}
