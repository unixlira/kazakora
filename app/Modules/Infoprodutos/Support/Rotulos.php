<?php

namespace App\Modules\Infoprodutos\Support;

/**
 * Texto legível das interações (tela Comportamento). O nome estável vem do
 * atributo data-kzm do elemento (ou de window.kzm.acao), ex.:
 * "comprar", "galeria:3", "variacao:kit-2". Sem nome, usa a seção e o texto
 * curto do elemento clicado.
 */
class Rotulos
{
    /** Nome (antes dos dois-pontos) => texto do clique. "%s" = o que vem depois. */
    private const CLIQUE = [
        'menu' => 'clicou no menu',
        'foto' => 'clicou na foto (ampliar)',
        'galeria-seta' => 'passou a foto pela seta',
        'favorito' => 'clicou em favoritar',
        'galeria' => 'clicou na %sª imagem da galeria',
        'descricao' => 'abriu a descrição',
        'variacao' => 'escolheu a variação %s',
        'frete' => 'calculou frete',
        'cupom' => 'aplicou cupom',
        'comprar' => 'clicou em Comprar',
        'comprar:hero' => 'clicou em Comprar (topo)',
        'comprar:meio' => 'clicou em Comprar (meio)',
        'comprar:final' => 'clicou em Comprar (final)',
        'comprar:barra' => 'clicou em Comprar (barra fixa)',
        'oferta:aceitar' => 'aceitou a oferta (upsell/downsell)',
        'oferta:recusar' => 'recusou a oferta (upsell/downsell)',
        'idioma' => 'trocou o idioma para %s',
        'equipe' => 'clicou na equipe/especialista %s',
        'carrinho' => 'clicou em Adicionar ao carrinho',
        'whatsapp' => 'clicou no WhatsApp',
        'avaliacoes' => 'abriu avaliações',
        'brinde' => 'clicou no selo do brinde',
        'previa' => 'abriu as páginas grátis (prévia)',
        'faq' => 'abriu a pergunta %s do FAQ',
        'video' => 'deu play no vídeo',
        'complemento' => 'clicou no complemento %s',
        'oferta' => 'oferta: %s',
        'quantidade' => 'mudou a quantidade',
        'ficha' => 'abriu a ficha técnica',
        'parcelas' => 'viu as parcelas',
    ];

    /** Nome => texto da ação sem clique (window.kzm.acao). */
    private const ACAO = [
        'galeria' => 'trocou para a %sª imagem',
        'variacao' => 'escolheu a variação %s',
        'frete' => 'calculou frete',
        'cupom' => 'aplicou cupom',
    ];

    private const TIPO = [
        'pageview' => 'entrou na página',
        'play' => 'deu play no vídeo',
        'preview' => 'abriu a prévia grátis',
        'recusa' => 'recusou a oferta',
    ];

    public static function evento(object $e): string
    {
        return match ($e->tipo) {
            'clk' => self::clique($e),
            'acao' => self::nome((string) $e->valor, self::ACAO + self::CLIQUE),
            'scroll' => "rolou {$e->valor}% da página",
            'video' => "assistiu {$e->valor}% do vídeo",
            'buy' => 'clicou em comprar ('.$e->valor.')',
            'faq' => 'abriu a pergunta '.((int) $e->valor + 1).' do FAQ',
            'exit' => 'saiu depois de '.(int) $e->valor.' s'.($e->rolagem_max !== null ? " (viu {$e->rolagem_max}% da página)" : ''),
            default => self::TIPO[$e->tipo] ?? $e->tipo,
        };
    }

    /** Chave de agrupamento do ranking "onde clicam". */
    public static function chave(object $e): string
    {
        if ($e->tipo === 'acao') {
            return 'n:'.$e->valor;
        }

        return $e->nome !== null ? 'n:'.$e->nome : 'e:'.($e->secao ?? '').'|'.($e->elemento ?? '').'|'.$e->alvo;
    }

    /** Passo da sequência (só o que tem nome ou é marco importante). */
    public static function passo(object $e): ?string
    {
        return match ($e->tipo) {
            'clk' => $e->nome !== null ? self::nome((string) $e->nome, self::CLIQUE, curto: true) : null,
            'acao' => self::nome((string) $e->valor, self::ACAO + self::CLIQUE, curto: true),
            'scroll' => (int) $e->valor >= 50 ? "rolou {$e->valor}%" : null,
            'play' => 'vídeo',
            // Mesmo texto do clique com data-kzm, para não repetir o passo.
            'buy' => ['hero' => 'Comprar (topo)', 'meio' => 'Comprar (meio)', 'final' => 'Comprar (final)', 'barra' => 'Comprar (barra fixa)',
                'oferta' => 'aceitou a oferta (upsell/downsell)'][(string) $e->valor] ?? 'Comprar',
            'preview' => 'prévia',
            default => null,
        };
    }

    private static function clique(object $e): string
    {
        if ($e->nome !== null) {
            return self::nome((string) $e->nome, self::CLIQUE);
        }
        $alvo = trim((string) ($e->elemento ?? ''));
        $onde = $e->secao ? " (seção {$e->secao})" : '';

        return match ($e->alvo) {
            'm' => 'clique morto em "'.($alvo !== '' ? $alvo : 'área vazia').'"'.$onde,
            'a' => 'clicou no link "'.$alvo.'"'.$onde,
            default => 'clicou em "'.$alvo.'"'.$onde,
        };
    }

    /** @param  array<string, string>  $mapa */
    private static function nome(string $nome, array $mapa, bool $curto = false): string
    {
        [$base, $resto] = array_pad(explode(':', $nome, 2), 2, null);
        if (! isset($mapa[$base]) && ! isset($mapa[$nome])) {
            return $curto ? $nome : "clicou em \"{$nome}\"";
        }
        if (isset($mapa[$nome])) {
            $saida = $mapa[$nome];

            return $curto ? (string) preg_replace('/^(clicou (em|no|na) |abriu (a |as )?|escolheu a |deu )/u', '', $saida) : $saida;
        }
        $texto = $mapa[$base];
        $resto = $resto !== null ? str_replace('-', ' ', $resto) : '';

        $saida = str_contains($texto, '%s') ? trim(sprintf($texto, $resto)) : $texto;
        if ($curto) {
            $saida = preg_replace('/^(clicou (em|no|na) |abriu (a |as )?|escolheu a |deu )/u', '', $saida);
        }

        return $saida;
    }
}
