<?php

namespace App\Modules\Checkout\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Opções de entrega com os nomes que o cliente da loja KazaKora vê (pedido do
 * Lira 2026-11-24, a pedido do professor de tráfego): nada de Full, Flex ou
 * Turbo, que são nomes internos de logística e continuam só no admin.
 *
 * - Grátis: "Entrega Rápida" (o antigo Full da Grande SP e o Flex, quando os
 *   Correios entregam em até 3 dias úteis) ou "Entrega Normal" (PAC mais
 *   demorado, fora da Grande SP).
 * - Paga: "Entrega Expressa", sempre cobrando só o que passa do frete que
 *   já está no preço. Na Grande SP (CEP 01000-000 a 09999-999), em horário
 *   comercial e com valor configurado, é o motoboy em até 4 horas (R$ 39,00
 *   menos o frete embutido; grátis quando o embutido cobre); no resto, o
 *   SEDEX (o mesmo TURBO de FreteEmbutido::entregaDoCarrinho()).
 *
 * Toda opção diz quando chega ("Chega hoje até as 21h", "Chega até qui.,
 * 27/11", "Chega em até 10 dias úteis"), pelo prazo que o sistema já tem.
 */
class EntregasDaLoja
{
    public const RAPIDA = 'Entrega Rápida';

    public const NORMAL = 'Entrega Normal';

    public const EXPRESSA = 'Entrega Expressa';

    public function __construct(private readonly DiaDeEntrega $dias) {}

    /**
     * @param  array<string, mixed>|null  $full  EntregaExpressa::consultar()
     * @param  array{prazo: ?int, turbo: ?array}|null  $entrega  FreteEmbutido::entregaDoCarrinho()
     * @param  array{preco: float, valor: float, mensagem: string}|null  $motoboy  EntregaExpressa::motoboyGrandeSp()
     * @return list<array{id: string, tipo: string, nome: string, chega: string, preco: float, excedente: bool, prazo_dias: ?int}>
     */
    public function opcoes(string $cep, ?array $full, ?array $entrega, ?array $motoboy, ?CarbonInterface $agora = null): array
    {
        $opcoes = [$this->gratis($cep, $full, $entrega['prazo'] ?? null, $agora)];

        if ($motoboy) {
            $opcoes[] = [
                'id' => 'expressa',
                'tipo' => 'motoboy',
                'nome' => self::EXPRESSA,
                'chega' => (string) $motoboy['mensagem'],
                // Só o excedente: valor do motoboy menos o frete que já está no preço.
                'preco' => round((float) $motoboy['preco'], 2),
                'excedente' => true,
                'prazo_dias' => 0,
            ];
        } elseif ($sedex = $entrega['turbo'] ?? null) {
            $opcoes[] = [
                'id' => 'expressa',
                'tipo' => 'sedex',
                'nome' => self::EXPRESSA,
                'chega' => ! empty($sedex['prazo']) ? $this->dias->chegaCorreios((int) $sedex['prazo'], $agora) : 'Chega mais rápido',
                // Só a diferença entre o SEDEX e o frete que já está no preço.
                'preco' => round((float) $sedex['preco'], 2),
                'excedente' => true,
                'prazo_dias' => ! empty($sedex['prazo']) ? (int) $sedex['prazo'] : null,
            ];
        }

        return $opcoes;
    }

    public static function grandeSp(?string $cep): bool
    {
        $numero = (int) preg_replace('/\D/', '', (string) $cep);

        return $numero >= 1000000 && $numero <= 9999999;
    }

    /**
     * @param  array<string, mixed>|null  $full
     * @return array{id: string, tipo: string, nome: string, chega: string, preco: float, excedente: bool, prazo_dias: ?int}
     */
    private function gratis(string $cep, ?array $full, ?int $prazo, ?CarbonInterface $agora): array
    {
        if ($full) {
            $dia = ! empty($full['dia']) ? Carbon::parse($full['dia'], 'America/Sao_Paulo') : Carbon::instance($agora ?? now());

            return [
                'id' => 'gratis',
                'tipo' => 'rapida',
                'nome' => self::RAPIDA,
                'chega' => $this->dias->chega($dia, $agora, (string) config('entrega_expressa.horario_entrega', '21h')),
                'preco' => 0.0,
                'excedente' => false,
                'prazo_dias' => null,
            ];
        }

        // Mesma folga de postagem da tabela da página do produto (PrazoPorCep::opcoes).
        $ate = $prazo
            ? $prazo + (int) config('frete_embutido.folga_dias', 2)
            : (int) config('frete_embutido.prazo_padrao_dias', 7);
        $rapida = self::grandeSp($cep) || ($prazo !== null && $prazo <= PrazoPorCep::DIAS_FLEX);

        return [
            'id' => 'gratis',
            'tipo' => $rapida ? 'rapida' : 'normal',
            'nome' => $rapida ? self::RAPIDA : self::NORMAL,
            'chega' => $this->dias->chegaCorreios($ate, $agora),
            'preco' => 0.0,
            'excedente' => false,
            'prazo_dias' => $ate,
        ];
    }
}
