<?php

namespace App\Modules\Marketplace\Support;

use Illuminate\Support\Facades\Cache;

/**
 * Quando avisar por e-mail a venda que não entrou (pedido do Lira 11/11/2026).
 *
 * Até aqui o e-mail saía na primeira falha. Em 11/11 a Shopee derrubou a
 * conexão várias vezes ("Connection reset by peer") e respondeu "pedido não
 * encontrado" para venda recém-feita: 7 e-mails de "venda NÃO importada"
 * para vendas que a rodada seguinte importou normalmente. Alarme falso
 * demais faz o alerta de verdade (incidente 2026-09-10) ser ignorado.
 *
 * Regra: guarda desde quando cada venda falha. Avisa só quando ela continua
 * fora do sistema há ESPERA_MINUTOS e repete no máximo a cada
 * REPETIR_MINUTOS. Venda importada numa rodada sai da lista. O log de erro
 * continua registrando toda falha.
 */
class AlertaVendaNaoImportada
{
    public const ESPERA_MINUTOS = 30;

    public const REPETIR_MINUTOS = 60;

    /** Venda que some do canal (cancelada antes de entrar) não fica para sempre. */
    private const ESQUECER_DIAS = 3;

    /**
     * @param  list<array{sn: string, message: string}>  $falhas  falhas desta rodada
     * @param  list<string>  $importadas  vendas que entraram nesta rodada
     * @return list<array{sn: string, message: string}> as que devem ir para o e-mail agora
     */
    public static function filtrar(string $canal, array $falhas, array $importadas): array
    {
        $chave = 'alerta-venda-nao-importada:'.$canal;

        return Cache::lock($chave.':trava', 10)->block(5, function () use ($chave, $falhas, $importadas) {
            $agora = now()->getTimestamp();
            $mapa = (array) Cache::get($chave, []);

            foreach ($importadas as $sn) {
                unset($mapa[(string) $sn]);
            }
            foreach ($mapa as $sn => $estado) {
                if ($agora - (int) ($estado['primeira'] ?? 0) > self::ESQUECER_DIAS * 86400) {
                    unset($mapa[$sn]);
                }
            }

            $avisar = [];
            foreach ($falhas as $falha) {
                $sn = (string) $falha['sn'];
                $estado = $mapa[$sn] ?? ['primeira' => $agora, 'avisada' => null];
                $presa = $agora - (int) $estado['primeira'] >= self::ESPERA_MINUTOS * 60;
                $podeRepetir = $estado['avisada'] === null || $agora - (int) $estado['avisada'] >= self::REPETIR_MINUTOS * 60;
                if ($presa && $podeRepetir) {
                    $estado['avisada'] = $agora;
                    $minutos = intdiv($agora - (int) $estado['primeira'], 60);
                    $avisar[] = ['sn' => $sn, 'message' => $falha['message']." (fora do sistema há {$minutos} min)"];
                }
                $mapa[$sn] = $estado;
            }

            Cache::put($chave, $mapa, now()->addDays(self::ESQUECER_DIAS + 1));

            return $avisar;
        });
    }
}
