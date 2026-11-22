<?php

namespace App\Modules\Infoprodutos\Support;

/**
 * Qual utm_content dos sites corresponde a um anúncio da Meta (GreenKora).
 *
 * 1. o id do anúncio; 2. o nome do anúncio (sem diferença de maiúscula);
 * 3. (22/11, Homer) um código curto que aparece como pedaço do nome do
 * anúncio, desde que a utm_campaign das visitas desse código também esteja
 * no nome da campanha. Ex.: anúncio "AD01 | MC_homer-lancamento_V01-demo-homer_9x16"
 * da campanha "DMS | VENDAS | BR | HOMER | V01 ..." casa com as visitas de
 * utm_content=V01 e utm_campaign=dms_homer_br. Sem achar, fica o nome do anúncio.
 */
class UtmDoAnuncio
{
    /** @var array<string, string> */
    private array $porMinusculo = [];

    /**
     * @param  array<string, list<string>>  $utms  utm_content => utm_campaigns vistas
     */
    public function __construct(private readonly array $utms)
    {
        foreach (array_keys($utms) as $c) {
            $this->porMinusculo[mb_strtolower((string) $c)] ??= (string) $c;
        }
    }

    public function chave(string $anuncioId, string $anuncioNome, string $campanhaNome): string
    {
        if (isset($this->utms[$anuncioId])) {
            return $anuncioId;
        }
        if (isset($this->utms[$anuncioNome])) {
            return $anuncioNome;
        }
        if (isset($this->porMinusculo[mb_strtolower($anuncioNome)])) {
            return $this->porMinusculo[mb_strtolower($anuncioNome)];
        }

        $pedacosAnuncio = array_flip(self::pedacos($anuncioNome));
        $pedacosCampanha = array_flip(self::pedacos($campanhaNome));
        $achados = [];
        foreach ($this->utms as $conteudo => $campanhas) {
            $p = self::pedacos((string) $conteudo);
            if (count($p) !== 1 || ! isset($pedacosAnuncio[$p[0]])) {
                continue;
            }
            foreach ($campanhas as $utmCampanha) {
                $pc = self::pedacos($utmCampanha);
                if ($pc !== [] && array_diff($pc, array_keys($pedacosCampanha)) === []) {
                    $achados[(string) $conteudo] = true;
                    break;
                }
            }
        }

        // Só quando não há dúvida.
        return count($achados) === 1 ? (string) array_key_first($achados) : $anuncioNome;
    }

    /** @return list<string> */
    private static function pedacos(string $texto): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', mb_strtolower($texto)) ?: [], fn ($p) => $p !== ''));
    }
}
