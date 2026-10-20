<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Str;

/**
 * Regra do Lira (2026-10-15) para as imagens de IA dos produtos da categoria
 * ferramenta: a sequência do anúncio segue 1º despertar desejo, 2º quebrar
 * objeções, 3º terminar com um CTA poderoso.
 *
 * Cada arte da matriz recebe uma etapa e a matriz é reordenada para que as
 * de desejo venham primeiro e a de CTA seja sempre a última. O CTA continua
 * sujeito à regra de nada de promessa, selo ou oferta que não seja real.
 */
class SequenciaPsicologicaImagens
{
    public const DESEJO = 'desejo';

    public const OBJECAO = 'objecao';

    public const CTA = 'cta';

    private const ORDEM = [self::DESEJO, self::OBJECAO, self::CTA];

    private const ROTULOS = [
        self::DESEJO => 'Despertar desejo',
        self::OBJECAO => 'Quebrar objeções',
        self::CTA => 'CTA poderoso',
    ];

    private const ORIENTACAO = [
        self::DESEJO => 'Etapa 1 da sequência (despertar desejo): mostrar o resultado e o poder da ferramenta em ação, fazendo o comprador se imaginar usando.',
        self::OBJECAO => 'Etapa 2 da sequência (quebrar objeções): responder com dado verdadeiro a dúvida de potência, durabilidade, compatibilidade, medida, montagem ou segurança.',
        self::CTA => 'Etapa 3 da sequência (CTA poderoso): imagem final de fechamento, produto protagonista e chamada curta para comprar agora, sem cupom, frete ou promoção que não seja real.',
    ];

    /** Etapa de cada arte, pela chave da matriz (A..J), por marketplace. */
    private const ETAPAS = [
        'shopee' => [
            'A' => self::DESEJO, 'B' => self::DESEJO, 'C' => self::DESEJO, 'D' => self::DESEJO,
            'E' => self::OBJECAO, 'F' => self::OBJECAO, 'G' => self::OBJECAO, 'I' => self::OBJECAO,
            'H' => self::CTA,
        ],
        'mercado_livre' => [
            'A' => self::DESEJO, 'B' => self::DESEJO, 'C' => self::DESEJO, 'D' => self::DESEJO, 'E' => self::DESEJO,
            'F' => self::OBJECAO, 'G' => self::OBJECAO, 'H' => self::OBJECAO, 'I' => self::OBJECAO,
            'J' => self::CTA,
        ],
    ];

    private const PALAVRAS_FERRAMENTA = [
        'ferramenta', 'parafusadeira', 'furadeira', 'chave de fenda', 'chave inglesa', 'chave allen',
        'alicate', 'martelo', 'serra', 'esmerilhadeira', 'lixadeira', 'soquete', 'trena', 'nivel laser',
        'tarracha', 'multimetro', 'soprador', 'martelete', 'plaina', 'torquimetro', 'rebitador',
    ];

    /** Produto de ferramenta, pelo texto de categoria, nome e descrição. */
    public function ehFerramenta(string ...$textos): bool
    {
        $texto = ' '.Str::of(implode(' ', $textos))->ascii()->lower().' ';

        foreach (self::PALAVRAS_FERRAMENTA as $palavra) {
            if (str_contains($texto, $palavra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reordena a matriz (desejo → objeções → CTA, mantendo a ordem original
     * dentro de cada etapa) e marca cada arte com `stage` e `stage_label`.
     *
     * @param  array<int, array<string, mixed>>  $matrix
     * @return array<int, array<string, mixed>>
     */
    public function aplicar(array $matrix, string $marketplace): array
    {
        $etapas = self::ETAPAS[$marketplace] ?? self::ETAPAS['shopee'];

        $marcada = collect($matrix)->map(function (array $item) use ($etapas): array {
            $etapa = $etapas[$item['key'] ?? ''] ?? self::OBJECAO;

            return [...$item, 'stage' => $etapa, 'stage_label' => self::ROTULOS[$etapa]];
        });

        return $marcada
            ->sortBy(fn (array $item): int => array_search($item['stage'], self::ORDEM, true))
            ->values()
            ->all();
    }

    public function orientacao(string $etapa): string
    {
        return self::ORIENTACAO[$etapa] ?? '';
    }
}
