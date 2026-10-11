<?php

namespace App\Modules\Marketplace\Support;

use App\Models\Setting;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexBillingPeriod;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Mercado Envios Flex (logistic_type "self_service" na API do ML — nome
 * confirmado contra a documentação oficial 2026-08-10, e é o mesmo valor
 * que já aparece de verdade em ChannelShipment.shipping_method pros
 * pedidos reais deste canal) cobra do vendedor um valor fixo por entrega,
 * faturado quinzenalmente (dia 1-15 e 16-fim do mês) — pedido explícito
 * do usuário. Esse serviço é a única fonte de verdade pra "quantos fretes
 * Flex" e "quanto custou" num período, reaproveitado pela tela de
 * controle, pelo e-mail agendado e pelo abatimento no dashboard
 * financeiro.
 */
class FlexDeliveryService
{
    private const SETTING_COST_PER_DELIVERY = 'flex.cost_per_delivery';

    private const DEFAULT_COST_PER_DELIVERY = '12.99';

    public function costPerDelivery(): float
    {
        return (float) Setting::get(self::SETTING_COST_PER_DELIVERY, self::DEFAULT_COST_PER_DELIVERY);
    }

    public function updateCostPerDelivery(float $value): void
    {
        Setting::set(self::SETTING_COST_PER_DELIVERY, number_format($value, 2, '.', ''));
    }

    /**
     * @return array{count: int, total: float}
     */
    public function summaryForPeriod(Carbon $start, Carbon $end): array
    {
        $count = $this->flexShipmentsQuery($start, $end)->count();

        return [
            'count' => $count,
            'total' => round($count * $this->costPerDelivery(), 2),
        ];
    }

    /**
     * Ciclo quinzenal que contém a data informada — dia 1-15 ou
     * 16-fim do mês (fim de verdade: 28/29/30/31 conforme o mês, nunca
     * fixo em "30" — pedido do usuário foi "dia 30" mas o objetivo real é
     * "fim da segunda quinzena", que em fevereiro e nos meses de 31 dias
     * cairia errado se fosse um número fixo).
     *
     * @return array{start: Carbon, end: Carbon}
     */
    public function cycleContaining(Carbon $date): array
    {
        if ($date->day <= 15) {
            return ['start' => $date->copy()->startOfMonth(), 'end' => $date->copy()->startOfMonth()->addDays(14)];
        }

        return ['start' => $date->copy()->startOfMonth()->addDays(15), 'end' => $date->copy()->endOfMonth()->startOfDay()];
    }

    /**
     * Fecha (cria ou atualiza) o registro do ciclo em flex_billing_periods
     * pro período informado — snapshot do valor por entrega vigente agora,
     * pra não mudar retroativamente se o valor for editado depois.
     */
    public function closeCycle(Carbon $start, Carbon $end): FlexBillingPeriod
    {
        $summary = $this->summaryForPeriod($start, $end);

        return FlexBillingPeriod::query()->updateOrCreate(
            ['period_start' => $start->toDateString(), 'period_end' => $end->toDateString()],
            [
                'deliveries_count' => $summary['count'],
                'cost_per_delivery' => $this->costPerDelivery(),
                'total_amount' => $summary['total'],
            ],
        );
    }

    private function flexShipmentsQuery(Carbon $start, Carbon $end): Builder
    {
        // COALESCE(confirmed_at, created_at): confirmed_at é a data real do
        // despacho (mais fiel a "quando a entrega Flex aconteceu"), mas
        // nunca deveria faltar num shipment self_service já processado —
        // o fallback é só defensivo, pra nunca excluir um registro
        // legítimo da contagem por um campo nulo inesperado.
        return ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('shipping_method', 'self_service')
            ->whereBetween(DB::raw('COALESCE(confirmed_at, created_at)'), [
                $start->copy()->startOfDay(),
                $end->copy()->endOfDay(),
            ]);
    }
}
