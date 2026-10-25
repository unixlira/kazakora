<?php

namespace App\Modules\Checkout\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Entrega expressa na Grande São Paulo (pedido 2026-10-09): diz se o CEP
 * está na área atendida e qual mensagem mostrar ("Receba hoje até as 21h"
 * até o horário de corte, "Receba amanhã" depois dele). Faixas e horários
 * ficam em config/entrega_expressa.php.
 */
class EntregaExpressa
{
    public const HOJE = 'hoje';

    public const AMANHA = 'amanha';

    /**
     * @return array{tipo: string, mensagem: string, local: string}|null null quando o CEP está fora da área
     */
    public function consultar(?string $cep, ?CarbonInterface $agora = null): ?array
    {
        $faixa = $this->faixa($cep);

        if (! $faixa) {
            return null;
        }

        $agora = Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo');
        [$hora, $minuto] = array_map('intval', explode(':', (string) config('entrega_expressa.horario_corte', '13:00')));
        $antesDoCorte = $agora->lt($agora->copy()->setTime($hora, $minuto));

        return [
            'tipo' => $antesDoCorte ? self::HOJE : self::AMANHA,
            'mensagem' => $antesDoCorte
                ? 'Receba hoje até as '.config('entrega_expressa.horario_entrega', '21h')
                : 'Receba amanhã',
            'local' => $faixa['local'],
        ];
    }

    /**
     * Mesma consulta pela cidade (sem CEP): visitante localizado só pelo IP
     * (CF-IPCity da Cloudflare). Vale a primeira faixa com o "local" igual à
     * cidade, sem diferença de acento ou maiúscula.
     *
     * @return array{tipo: string, mensagem: string, local: string}|null
     */
    public function consultarCidade(?string $cidade, ?CarbonInterface $agora = null): ?array
    {
        $nome = Str::lower(Str::ascii(trim((string) $cidade)));

        if ($nome === '' || ! config('entrega_expressa.ativa', true)) {
            return null;
        }

        $faixa = collect(config('entrega_expressa.faixas', []))
            ->first(fn (array $faixa) => Str::lower(Str::ascii($faixa['local'])) === $nome);

        return $faixa ? $this->consultar((string) $faixa['inicio'], $agora) : null;
    }

    /**
     * Turbo de motoboy (pedido 2026-10-24): CEP na área e compra em horário
     * comercial (seg a sex e sábado até 13h, horários em config).
     *
     * @return array{preco: float, mensagem: string}|null
     */
    public function motoboy(?string $cep, ?CarbonInterface $agora = null): ?array
    {
        if (! $this->faixa($cep)) {
            return null;
        }

        $agora = Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo');
        $janela = match (true) {
            $agora->isSunday() => null,
            $agora->isSaturday() => config('entrega_expressa.motoboy.sabado'),
            default => config('entrega_expressa.motoboy.semana'),
        };

        if (! $janela) {
            return null;
        }

        [$inicio, $fim] = array_map(function (string $horario) use ($agora) {
            [$hora, $minuto] = array_map('intval', explode(':', $horario));

            return $agora->copy()->setTime($hora, $minuto);
        }, $janela);

        if ($agora->lt($inicio) || $agora->gte($fim)) {
            return null;
        }

        return [
            'preco' => (float) config('entrega_expressa.motoboy.preco', 39.00),
            'mensagem' => (string) config('entrega_expressa.motoboy.mensagem', 'Receba em até 3 horas'),
        ];
    }

    /** Sábado a partir do fim do expediente (13h) ou domingo, no horário de Brasília. */
    public function fimDeSemana(?CarbonInterface $agora = null): bool
    {
        $agora = Carbon::instance($agora ?? now())->setTimezone('America/Sao_Paulo');

        if ($agora->isSunday()) {
            return true;
        }

        if (! $agora->isSaturday()) {
            return false;
        }

        [$hora, $minuto] = array_map('intval', explode(':', (string) (config('entrega_expressa.motoboy.sabado')[1] ?? '13:00')));

        return $agora->gte($agora->copy()->setTime($hora, $minuto));
    }

    public function atende(?string $cep): bool
    {
        return $this->faixa($cep) !== null;
    }

    /**
     * @return array{inicio: string, fim: string, local: string}|null
     */
    private function faixa(?string $cep): ?array
    {
        if (! config('entrega_expressa.ativa', true)) {
            return null;
        }

        $digitos = preg_replace('/\D/', '', (string) $cep);

        if (strlen($digitos) !== 8) {
            return null;
        }

        $numero = (int) $digitos;

        foreach (config('entrega_expressa.faixas', []) as $faixa) {
            if ($numero >= (int) $faixa['inicio'] && $numero <= (int) $faixa['fim']) {
                return $faixa;
            }
        }

        return null;
    }
}
