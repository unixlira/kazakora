<?php

namespace App\Modules\Admin\Support\Leads;

use App\Modules\Cart\Models\CarrinhoAbandonadoSequencia as Registro;
use App\Modules\Cart\Support\Sequencia\CalendarioSequencia;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\LeadsDigitados;
use Illuminate\Support\Carbon;

/**
 * Situação única do lead na tela única de Leads (plano do Lira 25/11/2026,
 * §3). Um lugar só para cards, tabela e filtros. Prioridade de cima para
 * baixo:
 *
 * 1. Pago — pedido com pagamento confirmado (LeadsDigitados::pago).
 * 2. Pedido aguardando pgto — pedido gerado (pending/awaiting_payment), sem pagamento.
 * 3. No checkout agora — atividade nos últimos 5 minutos, sem pedido.
 * 4. Em remarketing N/6 — na sequência de carrinho abandonado, não parada.
 * 5. Parou — sequência parada (pediu para parar, saiu das promoções, fim...).
 * 6. Abandonou — sem atividade há mais de 5 minutos, sem pedido, fora da sequência.
 *
 * Só lê: nenhuma regra de negócio (conversão, sequência) muda aqui.
 */
class LeadStatusResolver
{
    public const PAGO = 'pago';

    public const AGUARDANDO = 'aguardando';

    public const AGORA = 'agora';

    public const REMARKETING = 'remarketing';

    public const PAROU = 'parou';

    public const ABANDONOU = 'abandonou';

    /** Atividade nos últimos minutos = "no checkout agora" (o mesmo da tela Leads de hoje). */
    public const MINUTOS_AGORA = 5;

    /** Situações que contam como "aberto" (card Abertos, chip Abertos e Valor em aberto). */
    public const ABERTOS = [self::AGUARDANDO, self::AGORA, self::REMARKETING, self::ABANDONOU];

    public const TOM = [
        self::PAGO => 'green',
        self::AGUARDANDO => 'blue',
        self::AGORA => 'purple',
        self::REMARKETING => 'orange',
        self::PAROU => 'gray',
        self::ABANDONOU => 'yellow',
    ];

    public const MOTIVOS = [
        Registro::PAROU_COMPROU => 'comprou',
        Registro::PAROU_PEDIU => 'pediu para parar',
        Registro::PAROU_SEM_PROMOCOES => 'saiu das promoções',
        Registro::PAROU_SEM_CONTATO => 'sem celular nem e-mail',
        Registro::PAROU_FIM => 'sequência terminou',
    ];

    /** Pedido gerado e ainda esperando o pagamento (cancelado não conta). */
    public static function aguardandoPagamento(Order $pedido): bool
    {
        return in_array($pedido->status, [Order::STATUS_PENDING, Order::STATUS_AWAITING_PAYMENT], true);
    }

    /**
     * @param  ?Order  $pedido  pedido ligado ao lead (order_id do lead ou do carrinho)
     * @param  bool  $convertido  o lead já está "convertido" (só acontece com pedido pago desde 25/11/2026)
     * @param  ?Carbon  $ultimaAtividade  última vez que o cliente mexeu no checkout
     * @return array{codigo: string, rotulo: string, tom: string, motivo: ?string, disparos: ?int}
     */
    public function resolver(?Order $pedido, bool $convertido, ?Carbon $ultimaAtividade, ?Registro $registro): array
    {
        if (($pedido && LeadsDigitados::pago($pedido)) || ($convertido && ! $pedido)) {
            return $this->situacao(self::PAGO, 'Pago');
        }

        if ($pedido && self::aguardandoPagamento($pedido)) {
            return $this->situacao(self::AGUARDANDO, 'Pedido aguardando pgto');
        }

        if ($ultimaAtividade && $ultimaAtividade->gte(now()->subMinutes(self::MINUTOS_AGORA))) {
            return $this->situacao(self::AGORA, 'No checkout agora');
        }

        if ($registro && ! $registro->parado) {
            $n = (int) $registro->disparos_enviados;

            return $this->situacao(self::REMARKETING, "Em remarketing {$n}/".CalendarioSequencia::TOTAL, disparos: $n);
        }

        if ($registro && $registro->parado) {
            return $this->situacao(self::PAROU, 'Parou', self::MOTIVOS[$registro->motivo_parada] ?? $registro->motivo_parada, (int) $registro->disparos_enviados);
        }

        return $this->situacao(self::ABANDONOU, 'Abandonou');
    }

    private function situacao(string $codigo, string $rotulo, ?string $motivo = null, ?int $disparos = null): array
    {
        return ['codigo' => $codigo, 'rotulo' => $rotulo, 'tom' => self::TOM[$codigo], 'motivo' => $motivo, 'disparos' => $disparos];
    }
}
