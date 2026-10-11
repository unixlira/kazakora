<?php

namespace App\Modules\Fiscal\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Valor da UFESP (Unidade Fiscal do Estado de SP), base da multa do
 * cancelamento fora do prazo. É ANUAL: a Fazenda divulga em dezembro, por
 * Comunicado DICAR no Diário Oficial, o valor de 1º/jan a 31/dez do ano
 * seguinte.
 *
 * Não existe API oficial. A atualização lê a tabela do Yahii (endereço
 * fixo, traz o valor e o comunicado de cada ano) e só aceita o valor se a
 * página oficial desse comunicado, em legislacao.fazenda.sp.gov.br,
 * confirmar o mesmo número. Sem confirmação oficial, não grava e avisa.
 */
class UfespService
{
    public const FONTE_TABELA = 'https://yahii.com.br/indices/ufesp.php';

    private const FONTE_OFICIAL = 'https://legislacao.fazenda.sp.gov.br/Paginas/Comunicado-%s-%d-de-%d.aspx';

    /** Valor do ano (o do ano anterior mais recente se o do ano ainda não saiu). */
    public function valor(?int $ano = null): float
    {
        $ano ??= (int) now()->year;

        return Cache::remember("fiscal:ufesp:{$ano}", 3600, function () use ($ano) {
            $valor = DB::table('ufesp_valores')->where('ano', '<=', $ano)->orderByDesc('ano')->value('valor');

            return $valor !== null ? (float) $valor : (float) config('nfe.ufesp');
        });
    }

    /** @return array{ano: int, valor: float, base_legal: ?string, fonte: ?string}|null */
    public function registro(int $ano): ?array
    {
        $linha = DB::table('ufesp_valores')->where('ano', $ano)->first();

        return $linha ? ['ano' => (int) $linha->ano, 'valor' => (float) $linha->valor, 'base_legal' => $linha->base_legal, 'fonte' => $linha->fonte] : null;
    }

    /**
     * Busca o valor do ano nas fontes e grava se a página oficial confirmar.
     *
     * @return array{ano: int, valor: float, base_legal: string, fonte: string}
     */
    public function atualizar(int $ano): array
    {
        $tabela = $this->texto(self::FONTE_TABELA);

        // "de 01/01/2026 A 31/12/2026 38,42 Comunicado DICAR-88/25 , de 17-12-2025"
        $padrao = '/de 01\/01\/'.$ano.' A 31\/12\/'.$ano.' ([\d.]+,\d{2}) (Comunicado ([A-Z]+)-(\d+)\/(\d{2}) ?, de [\d-]+)/u';

        if (! preg_match($padrao, $tabela, $m)) {
            throw new RuntimeException("UFESP de {$ano} ainda não aparece na tabela de ".self::FONTE_TABELA.'.');
        }

        $valor = (float) str_replace(['.', ','], ['', '.'], $m[1]);
        $baseLegal = preg_replace('/\s+,/', ',', $m[2]);
        $oficial = sprintf(self::FONTE_OFICIAL, $m[3], (int) $m[4], 2000 + (int) $m[5]);

        $textoOficial = $this->texto($oficial);
        $valorEscrito = 'R$ '.number_format($valor, 2, ',', '.');

        if (! str_contains($textoOficial, (string) $ano) || ! str_contains($textoOficial, $valorEscrito)) {
            throw new RuntimeException("A página oficial ({$oficial}) não confirmou a UFESP de {$ano} = {$valorEscrito}. Nada foi gravado.");
        }

        DB::table('ufesp_valores')->updateOrInsert(
            ['ano' => $ano],
            ['valor' => $valor, 'base_legal' => $baseLegal, 'fonte' => $oficial, 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::forget("fiscal:ufesp:{$ano}");

        return ['ano' => $ano, 'valor' => $valor, 'base_legal' => $baseLegal, 'fonte' => $oficial];
    }

    /** Valor lançado à mão pelo admin (quando as fontes falharem). */
    public function definir(int $ano, float $valor, ?string $baseLegal): void
    {
        DB::table('ufesp_valores')->updateOrInsert(
            ['ano' => $ano],
            ['valor' => $valor, 'base_legal' => $baseLegal, 'fonte' => 'manual', 'updated_at' => now(), 'created_at' => now()],
        );
        Cache::forget("fiscal:ufesp:{$ano}");
    }

    private function texto(string $url): string
    {
        $html = Http::timeout(20)->withHeaders(['User-Agent' => 'Mozilla/5.0 (Kazakora)'])->get($url)->throw()->body();
        $texto = html_entity_decode(preg_replace('/<[^>]+>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // A página oficial mete zero-width space no meio do texto.
        return preg_replace('/\s+/u', ' ', str_replace(["\u{200B}", "\u{00A0}"], ['', ' '], $texto));
    }
}
