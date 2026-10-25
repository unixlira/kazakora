<?php

namespace App\Modules\Checkout\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Dia em que a entrega chega de verdade (pedido do Lira 2026-10-24): sábado
 * à noite e domingo não tem entrega, então "Receba amanhã" virava promessa
 * falsa. Full entrega de segunda a sábado (pedido até o horário de corte sai
 * no mesmo dia); Correios (Flex, Padrão e Turbo) contam só dias úteis, de
 * segunda a sexta. Feriados nacionais (config entrega_expressa.feriados) não
 * contam em nenhum dos dois. Tudo no horário de Brasília.
 */
class DiaDeEntrega
{
    private const NOMES = [0 => 'DOMINGO', 1 => 'SEGUNDA', 2 => 'TERÇA', 3 => 'QUARTA', 4 => 'QUINTA', 5 => 'SEXTA', 6 => 'SÁBADO'];

    /** Full: hoje, se é dia de entrega e ainda não passou do corte; senão o próximo dia de entrega. */
    public function full(?CarbonInterface $agora = null): Carbon
    {
        $agora = $this->agora($agora);
        [$hora, $minuto] = array_map('intval', explode(':', (string) config('entrega_expressa.horario_corte', '13:00')));

        if ($this->entregaFull($agora) && $agora->lt($agora->copy()->setTime($hora, $minuto))) {
            return $agora->copy()->startOfDay();
        }

        return $this->proximo($agora, fn (Carbon $dia) => $this->entregaFull($dia));
    }

    /**
     * Correios: posta hoje se é dia útil (senão no próximo dia útil) e chega N
     * dias úteis depois (N = prazo, no mínimo 1). Sábado à noite: posta na
     * segunda, chega na terça.
     */
    public function correios(int $dias, ?CarbonInterface $agora = null): Carbon
    {
        $dia = $this->agora($agora)->startOfDay();

        if (! $this->diaUtil($dia)) {
            $dia = $this->proximo($dia, fn (Carbon $d) => $this->diaUtil($d));
        }

        for ($i = 0; $i < max(1, $dias); $i++) {
            $dia = $this->proximo($dia, fn (Carbon $d) => $this->diaUtil($d));
        }

        return $dia;
    }

    /**
     * "hoje", "amanhã", o dia da semana em maiúsculas ("SEGUNDA") até 6 dias;
     * depois "dd/mm". Em domingo ou feriado não diz "amanhã": "Receba SEGUNDA".
     */
    public function rotulo(CarbonInterface $dia, ?CarbonInterface $agora = null): string
    {
        $hoje = $this->agora($agora)->startOfDay();
        $diferenca = (int) $hoje->diffInDays(Carbon::instance($dia)->startOfDay());

        return match (true) {
            $diferenca <= 0 => 'hoje',
            $diferenca === 1 && $this->entregaFull($hoje) => 'amanhã',
            $diferenca <= 6 => self::NOMES[$dia->dayOfWeek],
            default => $dia->format('d/m'),
        };
    }

    /** Daqui a exatamente N dias corridos? (o "Receba em N dias" só vale assim) */
    public function emDiasCorridos(CarbonInterface $dia, int $dias, ?CarbonInterface $agora = null): bool
    {
        return $this->agora($agora)->startOfDay()->addDays($dias)->isSameDay($dia);
    }

    /** "Receba amanhã", "Receba SEGUNDA" ou "Receba em 05/11". */
    public function frase(CarbonInterface $dia, ?CarbonInterface $agora = null): string
    {
        $rotulo = $this->rotulo($dia, $agora);

        return str_contains($rotulo, '/') ? "Receba em {$rotulo}" : "Receba {$rotulo}";
    }

    /** Flex da página do produto: "Receba de 1 a 7 dias" quando pode chegar amanhã; senão "Receba a partir de TERÇA". */
    public function fraseFlex(?CarbonInterface $agora = null): string
    {
        $primeiro = $this->correios(1, $agora);

        return $this->emDiasCorridos($primeiro, 1, $agora)
            ? 'Receba de 1 a 7 dias'
            : 'Receba a partir de '.$this->rotulo($primeiro, $agora);
    }

    /** Turbo dos Correios: "Receba em 2 dias" quando os dias úteis batem com os corridos; senão "Receba SEGUNDA". */
    public function fraseCorreios(int $dias, ?CarbonInterface $agora = null): string
    {
        $dia = $this->correios($dias, $agora);

        return $this->emDiasCorridos($dia, $dias, $agora)
            ? "Receba em {$dias} ".($dias === 1 ? 'dia' : 'dias')
            : $this->frase($dia, $agora);
    }

    private function entregaFull(CarbonInterface $dia): bool
    {
        return ! $dia->isSunday() && ! $this->feriado($dia);
    }

    private function diaUtil(CarbonInterface $dia): bool
    {
        return ! $dia->isWeekend() && ! $this->feriado($dia);
    }

    private function feriado(CarbonInterface $dia): bool
    {
        $feriados = (array) config('entrega_expressa.feriados', []);

        return in_array($dia->format('m-d'), $feriados, true) || in_array($dia->format('Y-m-d'), $feriados, true);
    }

    private function proximo(CarbonInterface $depoisDe, callable $vale): Carbon
    {
        $dia = Carbon::instance($depoisDe)->startOfDay();

        do {
            $dia->addDay();
        } while (! $vale($dia));

        return $dia;
    }

    private function agora(?CarbonInterface $agora): Carbon
    {
        return Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo');
    }
}
