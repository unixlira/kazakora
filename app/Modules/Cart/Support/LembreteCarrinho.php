<?php

namespace App\Modules\Cart\Support;

use App\Modules\Cart\Models\CartSnapshot;
use Illuminate\Support\Carbon;

/**
 * Calendário dos lembretes de carrinho abandonado (pedido 2026-10-10): o 1º
 * 50 minutos depois da última mexida no carrinho; depois 1 por dia durante
 * 7 dias, revezando manhã, tarde e noite.
 */
class LembreteCarrinho
{
    public const TOTAL = 8;

    public const MINUTOS_PRIMEIRO = 50;

    /** Horários dos lembretes diários: manhã, tarde e noite. */
    public const HORARIOS = ['09:00', '14:00', '20:00'];

    /** Quando sai o próximo lembrete (null = acabou a sequência). */
    public static function proximoEm(CartSnapshot $carrinho): ?Carbon
    {
        $enviados = (int) $carrinho->lembretes_enviados;
        $inicio = $carrinho->ultima_atividade_em;

        if (! $inicio || $enviados >= self::TOTAL) {
            return null;
        }

        if ($enviados === 0) {
            return $inicio->copy()->addMinutes(self::MINUTOS_PRIMEIRO);
        }

        [$hora, $minuto] = explode(':', self::HORARIOS[($enviados - 1) % count(self::HORARIOS)]);
        $quando = $inicio->copy()->startOfDay()->addDays($enviados)->setTime((int) $hora, (int) $minuto);

        // Nunca dois lembretes com menos de 6 horas entre eles.
        $minimo = $carrinho->ultimo_lembrete_em?->copy()->addHours(6);

        return $minimo && $minimo->greaterThan($quando) ? $minimo : $quando;
    }
}
