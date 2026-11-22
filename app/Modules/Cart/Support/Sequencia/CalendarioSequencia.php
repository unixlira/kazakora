<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia;
use Illuminate\Support\Carbon;

/**
 * Calendário da sequência de carrinho abandonado (pedido do Lira 21/11/2026):
 * dias contados de `inicio_sequencia_em` — D+1, D+3, D+5, D+7, D+20 e D+30 —
 * e para no 6º. Só sai entre 9h e 20h (America/Sao_Paulo); fora disso, vai
 * para a próxima janela. Entre dois disparos, pelo menos 20 horas (rodada
 * atrasada por limite de envio não manda dois colados).
 */
class CalendarioSequencia
{
    /** Dias desde o início de cada disparo, do 1º ao 6º. */
    public const DIAS = [1, 3, 5, 7, 20, 30];

    public const TOTAL = 6;

    public const HORA_INICIO = 9;

    public const HORA_FIM = 20;

    public const FUSO = 'America/Sao_Paulo';

    public const HORAS_MINIMAS_ENTRE_DISPAROS = 20;

    /** Quando sai o próximo disparo (null = acabou a sequência). */
    public static function proximoEm(CarrinhoAbandonadoSequencia $carrinho): ?Carbon
    {
        $enviados = (int) $carrinho->disparos_enviados;

        if (! $carrinho->inicio_sequencia_em || $enviados >= self::TOTAL) {
            return null;
        }

        $quando = self::janela($carrinho->inicio_sequencia_em->copy()->addDays(self::DIAS[$enviados]));

        if ($carrinho->ultimo_disparo_em) {
            $minimo = self::janela($carrinho->ultimo_disparo_em->copy()->addHours(self::HORAS_MINIMAS_ENTRE_DISPAROS));
            if ($minimo->greaterThan($quando)) {
                $quando = $minimo;
            }
        }

        return $quando;
    }

    /** Dia do calendário do disparo $numero (1 a 6): 1, 3, 5, 7, 20 ou 30. */
    public static function dia(int $numero): int
    {
        return self::DIAS[max(1, min($numero, self::TOTAL)) - 1];
    }

    /** O mesmo instante, se estiver entre 9h e 20h em São Paulo; senão, as 9h da próxima janela. */
    public static function janela(Carbon $quando): Carbon
    {
        $local = $quando->copy()->setTimezone(self::FUSO);

        if ($local->hour < self::HORA_INICIO) {
            $local->setTime(self::HORA_INICIO, 0);
        } elseif ($local->hour >= self::HORA_FIM) {
            $local->addDay()->setTime(self::HORA_INICIO, 0);
        }

        return $local->setTimezone($quando->getTimezone());
    }
}
