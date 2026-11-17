<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use Illuminate\Support\Facades\DB;

/**
 * Alteração do endereço de entrega depois da compra (Lira, 2026-11-17,
 * pedido #4246): o histórico do pedido guarda o que a cliente digitou.
 *
 * - Grava na linha do tempo um evento address_changed com o mesmo formato
 *   do registro feito à mão no #4246: digitado_pela_cliente, corrigido_para,
 *   motivo e por.
 * - Guarda em orders.endereco_digitado o endereço original, uma vez só (na
 *   primeira alteração); as seguintes nunca o sobrescrevem.
 */
class EnderecoDoPedido
{
    /** Campo do pedido => chave no histórico (a mesma do admin, detalheCompleto). */
    public const CAMPOS = [
        'shipping_recipient_name' => 'destinatario',
        'shipping_zip' => 'cep',
        'shipping_street' => 'rua',
        'shipping_number' => 'numero',
        'shipping_complement' => 'complemento',
        'shipping_neighborhood' => 'bairro',
        'shipping_city' => 'cidade',
        'shipping_state' => 'uf',
    ];

    public function __construct(private readonly OrderFulfillmentTimeline $timeline)
    {
    }

    /** @return array<string, ?string> endereço atual do pedido nas chaves do histórico */
    public static function retrato(Order $order): array
    {
        $retrato = [];
        foreach (self::CAMPOS as $campo => $chave) {
            $valor = $order->getAttribute($campo);
            $retrato[$chave] = filled($valor) ? (string) $valor : null;
        }

        return $retrato;
    }

    public static function emUmaLinha(array $endereco): string
    {
        $rua = trim(($endereco['rua'] ?? '').', '.(($endereco['numero'] ?? null) ?: 'S/N'), ', ');
        $partes = array_filter([
            $rua.(filled($endereco['complemento'] ?? null) ? ' - '.$endereco['complemento'] : ''),
            $endereco['bairro'] ?? null,
            trim(($endereco['cidade'] ?? '').'/'.($endereco['uf'] ?? ''), '/'),
            filled($endereco['cep'] ?? null) ? 'CEP '.$endereco['cep'] : null,
        ], fn ($parte) => filled($parte));

        return implode(', ', $partes);
    }

    /**
     * Troca o endereço de entrega e registra o antes e o depois. Só os
     * campos de CAMPOS são aceitos; os ausentes ficam como estão. Devolve
     * false (e não grava nada) se nada mudou.
     *
     * @param  array<string, mixed>  $novo  campos shipping_* do pedido
     */
    public function alterar(Order $order, array $novo, ?string $motivo = null, ?User $por = null): bool
    {
        $novo = array_intersect_key($novo, self::CAMPOS);
        $novo = array_map(fn ($valor) => filled($valor) ? trim((string) $valor) : null, $novo);

        if (isset($novo['shipping_state'])) {
            $novo['shipping_state'] = mb_strtoupper($novo['shipping_state']);
        }

        return DB::transaction(function () use ($order, $novo, $motivo, $por) {
            $antes = self::retrato($order);
            $order->forceFill($novo);

            if (! $order->isDirty(array_keys($novo))) {
                return false;
            }

            $depois = self::retrato($order);

            // Original da compra: só na primeira alteração.
            if ($order->endereco_digitado === null) {
                $order->endereco_digitado = $antes;
            }

            $order->save();

            $this->timeline->record(
                $order,
                OrderFulfillmentEvent::STEP_ADDRESS_CHANGED,
                OrderFulfillmentEvent::STATUS_SUCCESS,
                'De: '.self::emUmaLinha($antes).' | Para: '.self::emUmaLinha($depois),
                [
                    'digitado_pela_cliente' => $order->endereco_digitado,
                    'corrigido_para' => $depois,
                    'motivo' => filled($motivo) ? trim($motivo) : null,
                    'por' => $por?->name ?? 'sistema',
                    // Antes desta alteração (igual ao digitado na primeira vez).
                    'anterior' => $antes,
                ],
            );

            return true;
        });
    }
}
