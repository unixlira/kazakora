<?php

namespace App\Modules\Cart\Support\Sequencia;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia;
use Illuminate\Support\Carbon;

/**
 * Calendário da sequência de carrinho abandonado. Desde 26/11/2026 (o Lira
 * achou que disparava demais) são só 4 contatos, contados de
 * `inicio_sequencia_em`:
 *
 *   D+1 WhatsApp  lembrete com o produto
 *   D+3 WhatsApp  segundo lembrete
 *   D+5 e-mail    Caderno KazaKora: matéria do blog ligada ao produto + link dele
 *   D+7 WhatsApp  último lembrete
 *
 * Depois do D+7 acabou. Só sai entre 9h e 20h (America/Sao_Paulo); fora
 * disso, vai para a próxima janela. Nunca dois disparos no mesmo dia: o
 * próximo sai, no mínimo, no dia seguinte ao último (e 20 horas depois).
 */
class CalendarioSequencia
{
    /** Dias desde o início de cada disparo, do 1º ao 4º. */
    public const DIAS = [1, 3, 5, 7];

    public const TOTAL = 4;

    /** Canal de cada disparo (o e-mail entra no lugar do WhatsApp de quem não tem celular). */
    public const ETAPAS = [
        1 => ['canal' => 'whatsapp', 'tipo' => 'lembrete', 'rotulo' => 'WhatsApp: lembrete com o produto'],
        2 => ['canal' => 'whatsapp', 'tipo' => 'segundo_lembrete', 'rotulo' => 'WhatsApp: segundo lembrete'],
        3 => ['canal' => 'email', 'tipo' => 'caderno', 'rotulo' => 'E-mail do Caderno KazaKora (matéria do produto)'],
        4 => ['canal' => 'whatsapp', 'tipo' => 'ultimo_lembrete', 'rotulo' => 'WhatsApp: último lembrete'],
    ];

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

        return self::quando($carrinho->inicio_sequencia_em, $enviados + 1, $carrinho->ultimo_disparo_em);
    }

    /** Data do disparo $numero, dado o início e o último disparo (ou null). */
    public static function quando(Carbon $inicio, int $numero, ?Carbon $ultimo = null): Carbon
    {
        $quando = self::janela($inicio->copy()->addDays(self::dia($numero)));

        if ($ultimo) {
            $minimo = self::minimoDepois($ultimo);
            if ($minimo->greaterThan($quando)) {
                $quando = $minimo;
            }
        }

        return $quando;
    }

    /** O mais cedo que pode sair o disparo seguinte a $ultimo: outro dia e 20 horas depois. */
    public static function minimoDepois(Carbon $ultimo): Carbon
    {
        $vinteHoras = self::janela($ultimo->copy()->addHours(self::HORAS_MINIMAS_ENTRE_DISPAROS));
        $outroDia = self::janela(self::amanha($ultimo));

        return $vinteHoras->greaterThan($outroDia) ? $vinteHoras : $outroDia;
    }

    /** As 9h do dia seguinte (São Paulo) a $quando, no fuso de $quando. */
    public static function amanha(Carbon $quando): Carbon
    {
        return $quando->copy()->setTimezone(self::FUSO)->addDay()->setTime(self::HORA_INICIO, 0)->setTimezone($quando->getTimezone());
    }

    /** Os dois instantes caem no mesmo dia em São Paulo? */
    public static function mesmoDia(Carbon $a, Carbon $b): bool
    {
        return $a->copy()->setTimezone(self::FUSO)->toDateString() === $b->copy()->setTimezone(self::FUSO)->toDateString();
    }

    /** Dia do calendário do disparo $numero (1 a 4): 1, 3, 5 ou 7. */
    public static function dia(int $numero): int
    {
        return self::DIAS[max(1, min($numero, self::TOTAL)) - 1];
    }

    /** Canal previsto do disparo $numero: whatsapp|email. */
    public static function canal(int $numero): string
    {
        return self::ETAPAS[max(1, min($numero, self::TOTAL))]['canal'];
    }

    public static function tipo(int $numero): string
    {
        return self::ETAPAS[max(1, min($numero, self::TOTAL))]['tipo'];
    }

    /** Agora está entre 9h e 20h em São Paulo? */
    public static function dentroDaJanela(Carbon $quando): bool
    {
        $hora = $quando->copy()->setTimezone(self::FUSO)->hour;

        return $hora >= self::HORA_INICIO && $hora < self::HORA_FIM;
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
