<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MarketplaceClaim;
use App\Modules\Marketplace\Models\PrintJob;
use App\Notifications\FlexShipmentAlertNotification;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

/**
 * O controle dos envios Flex depois que a caixa sai daqui — pedido do
 * usuário em 2026-09-11, depois de dois problemas com a transportadora:
 *
 * 1. uma bicicleta que saiu e nunca voltou (pedido #1384: etiqueta impressa
 *    11:51, separada 12:22, venda cancelada 19:32 — e o Mercado Livre nunca
 *    registrou a rota iniciada; o estoque voltou sozinho no sistema como se
 *    ela estivesse na prateleira);
 * 2. entregador que leva o pacote e não marca no app do ML que iniciou a
 *    rota.
 *
 * A regra de cada alerta mora em alertas(). Quem sabe o que o canal diz é o
 * espelho gravado por ShipmentService (channel_*); quem sabe o que
 * aconteceu AQUI é o KoraFlex (ready_for_pickup_at, collected_at e o
 * recibo). O alerta nasce do desencontro entre os dois.
 *
 * Nada aqui consulta o Mercado Livre sozinho, exceto sincronizar(): a tela
 * lê só o banco.
 */
class FlexControlService
{
    public const NIVEL_ERRO = 'erro';

    public const NIVEL_AVISO = 'aviso';

    /** Informativo: aparece no detalhe, não conta como pendência nem notifica. */
    public const NIVEL_INFO = 'info';

    public const CACHE_CONTAGEM = 'flex.controle.alertas_abertos';

    /** Liga na carga inicial (flex:acompanhar-envios --sem-notificar): registra sem avisar. */
    public static bool $silenciar = false;

    public const RESOLUCOES = [
        ChannelShipment::RETURN_BACK_IN_STORE => 'Produto está na loja',
        ChannelShipment::RETURN_CLOSED_WITHOUT_ITEM => 'Encerrado sem o produto voltar',
        ChannelShipment::RETURN_CHECKED => 'Conferido, sem problema',
    ];

    private const TIPOS_RECLAMACAO = [
        'mediations' => 'Mediação',
        'returns' => 'Devolução',
        'cancellations' => 'Cancelamento',
    ];

    public function __construct(private readonly FlexPickupService $pickup) {}

    /** Todo envio Flex do Mercado Livre, com o que a tela e os alertas precisam. */
    public function query(): Builder
    {
        return ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('shipping_method', ChannelShipment::METHOD_FLEX)
            ->whereHas('order')
            ->with([
                'order.items:id,order_id,product_id,product_name,quantity',
                'order.items.product:id,sku',
                'order.pickupReceipt',
                'order.marketplaceClaims',
            ]);
    }

    /**
     * Hora da impressão (bem-sucedida) da etiqueta de cada pedido, numa
     * query só — a lista do mês tem dezenas de linhas.
     *
     * @param  iterable<int>  $pedidos
     * @return array<int, string>
     */
    public function etiquetasImpressas(iterable $pedidos): array
    {
        $ids = collect($pedidos)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return PrintJob::query()
            ->whereIn('order_id', $ids)
            ->where('is_thank_you', false)
            ->where('status', PrintJob::STATUS_PRINTED)
            ->orderBy('printed_at')
            ->get(['order_id', 'printed_at', 'updated_at'])
            ->groupBy('order_id')
            ->map(fn (Collection $jobs) => (string) ($jobs->first()->printed_at ?? $jobs->first()->updated_at))
            ->all();
    }

    /**
     * Os alertas de um envio. Cada um diz O QUE fazer, não só o que houve:
     * quem abre a tela é quem vai atrás do produto.
     *
     * @return list<array{codigo: string, nivel: string, titulo: string, detalhe: string, resolvido: bool}>
     */
    public function alertas(ChannelShipment $envio, ?string $etiquetaImpressaEm = null): array
    {
        $pedido = $envio->order;

        if (! $pedido) {
            return [];
        }

        $alertas = [];
        $resolvidos = $envio->flex_alerts_resolved ?? [];

        $add = function (string $codigo, string $nivel, string $titulo, string $detalhe) use (&$alertas, $resolvidos) {
            $alertas[] = [
                'codigo' => $codigo,
                'nivel' => $nivel,
                'titulo' => $titulo,
                'detalhe' => $detalhe,
                'resolvido' => $nivel !== self::NIVEL_INFO && in_array($codigo, $resolvidos, true),
            ];
        };

        $cancelada = $pedido->status === Order::STATUS_CANCELLED;
        $estoqueDevolvido = $pedido->stock_restored_at
            ? ' O estoque foi devolvido no sistema em '.$this->hora($pedido->stock_restored_at).', como se o produto estivesse aqui.'
            : '';

        // --- Venda cancelada com o produto fora da prateleira -------------
        if ($cancelada && $envio->channel_delivered_at) {
            $add(
                'cancelada_apos_entrega',
                self::NIVEL_ERRO,
                'Entregue e depois cancelada — o produto precisa voltar',
                'O Mercado Livre entregou ao comprador em '.$this->hora($envio->channel_delivered_at)
                .' e a venda foi cancelada depois. Confirme aqui quando o produto voltar.'.$estoqueDevolvido,
            );
        } elseif ($cancelada && ($envio->channel_shipped_at || $pedido->collected_at)) {
            $saiu = $pedido->collected_at ?? $envio->channel_shipped_at;

            $add(
                'cancelada_em_rota',
                self::NIVEL_ERRO,
                'Cancelada depois de sair com o entregador',
                'O pacote saiu daqui em '.$this->hora($saiu).' e a venda foi cancelada'
                .($envio->channel_cancelled_at ? ' em '.$this->hora($envio->channel_cancelled_at) : '')
                .'. O produto precisa voltar pra loja.'.$estoqueDevolvido,
            );
        } elseif ($cancelada && ($pedido->packed_at || $pedido->ready_for_pickup_at || $etiquetaImpressaEm)) {
            // O caso da bicicleta: separada, etiquetada, cancelada, e o ML
            // sem nenhum registro de saída. Ou ela está na prateleira, ou
            // saiu com um entregador que não iniciou a rota — só quem olha
            // a prateleira sabe.
            $separada = $pedido->ready_for_pickup_at ?? $pedido->packed_at;

            $add(
                'cancelada_apos_separar',
                self::NIVEL_ERRO,
                'Cancelada depois de separada — confira se o produto está aqui',
                ($etiquetaImpressaEm ? 'Etiqueta impressa em '.$this->hora($etiquetaImpressaEm).'; ' : '')
                .($separada ? 'caixa separada em '.$this->hora($separada).'; ' : '')
                .'venda cancelada'.($envio->channel_cancelled_at ? ' em '.$this->hora($envio->channel_cancelled_at) : '')
                .'. O Mercado Livre nunca registrou a saída em rota: se o entregador levou a caixa, ele não iniciou a rota e o produto precisa voltar.'
                .$estoqueDevolvido,
            );
        }

        // --- Não entregue / devolvido pelo canal --------------------------
        if (! $cancelada && ($envio->channel_not_delivered_at || $envio->channel_returned_at || $envio->channel_status === 'not_delivered')) {
            $add(
                'nao_entregue',
                self::NIVEL_ERRO,
                $envio->channel_returned_at ? 'Devolvido pelo Mercado Livre — confirme que chegou' : 'Não entregue ao comprador — o produto deve voltar',
                $envio->channel_returned_at
                    ? 'O Mercado Livre diz que devolveu em '.$this->hora($envio->channel_returned_at).'. Confira se o produto está mesmo aqui.'
                    : 'Tentativa de entrega sem sucesso'.($envio->channel_not_delivered_at ? ' em '.$this->hora($envio->channel_not_delivered_at) : '')
                    .($envio->channel_substatus ? " ({$envio->channel_substatus})" : '').'. Acompanhe até o produto voltar.',
            );
        }

        // --- Reclamação / devolução aberta no canal -----------------------
        foreach ($pedido->marketplaceClaims ?? [] as $reclamacao) {
            /** @var MarketplaceClaim $reclamacao */
            if ($reclamacao->status !== 'opened') {
                continue;
            }

            $tipo = self::TIPOS_RECLAMACAO[$reclamacao->type] ?? 'Reclamação';

            $add(
                'reclamacao_'.$reclamacao->external_claim_id,
                $reclamacao->type === 'returns' ? self::NIVEL_ERRO : self::NIVEL_AVISO,
                "{$tipo} aberta no Mercado Livre",
                "{$tipo} #{$reclamacao->external_claim_id} aberta em ".$this->hora($reclamacao->claim_created_at)
                .($reclamacao->type === 'returns' ? '. O produto vai voltar: confirme aqui quando chegar.' : '.'),
            );
        }

        // --- Entregador que não iniciou a rota ----------------------------
        $horasRota = max(1, (int) config('services.koraflex.route_alert_hours', 2));

        if (! $cancelada && $pedido->collected_at && ! $envio->channel_shipped_at && ! $envio->channel_delivered_at
            && $pedido->collected_at->lte(now()->subHours($horasRota))) {
            $add(
                'rota_nao_iniciada',
                self::NIVEL_ERRO,
                'O entregador não iniciou a rota',
                'Entregue ao entregador em '.$this->hora($pedido->collected_at)
                .' (há '.$this->duracao($pedido->collected_at, now()).') e o Mercado Livre ainda não mostra o pacote em rota.'
                .($pedido->pickupReceipt?->photo_path ? ' A retirada tem foto e assinatura.' : ''),
            );
        }

        if (! $cancelada && $pedido->collected_at && $envio->channel_shipped_at
            && $envio->channel_shipped_at->gt($pedido->collected_at->copy()->addHours($horasRota))) {
            $add(
                'rota_tardia',
                self::NIVEL_INFO,
                'Rota iniciada com atraso',
                'Retirado aqui em '.$this->hora($pedido->collected_at).', rota iniciada no Mercado Livre só em '
                .$this->hora($envio->channel_shipped_at).' ('.$this->duracao($pedido->collected_at, $envio->channel_shipped_at).' depois).',
            );
        }

        // --- Caixa que devia ter saído e está aqui ------------------------
        if (! $cancelada && $pedido->status === Order::STATUS_PAID && ! $pedido->collected_at
            && ! $envio->channel_shipped_at && ! $envio->channel_delivered_at
            && $pedido->created_at && $pedido->created_at->lt($this->pickup->janela()['de'])) {
            $add(
                'nao_saiu',
                self::NIVEL_AVISO,
                'Deveria ter saído e ainda não saiu',
                'Venda de '.$this->hora($pedido->created_at).' sem entrega ao entregador registrada'
                .($pedido->ready_for_pickup_at ? ' (bipada como pronta em '.$this->hora($pedido->ready_for_pickup_at).')' : '').'.',
            );
        }

        // --- Retirada sem prova -------------------------------------------
        if ($pedido->collected_at && ! $pedido->pickupReceipt?->photo_path) {
            $add(
                'sem_comprovante',
                self::NIVEL_INFO,
                $pedido->pickupReceipt?->signature_path ? 'Retirada sem foto' : 'Retirada sem assinatura e sem foto',
                'A entrega ao entregador foi registrada sem '.($pedido->pickupReceipt?->signature_path ? 'a foto' : 'comprovante').'.',
            );
        }

        return $alertas;
    }

    /**
     * Só o que ainda pede ação de alguém: nem informativo, nem resolvido.
     *
     * @return list<array<string, mixed>>
     */
    public function alertasAbertos(ChannelShipment $envio, ?string $etiquetaImpressaEm = null): array
    {
        return array_values(array_filter(
            $this->alertas($envio, $etiquetaImpressaEm),
            fn (array $alerta) => $alerta['nivel'] !== self::NIVEL_INFO && ! $alerta['resolvido'],
        ));
    }

    /**
     * Recalcula e grava os alertas de um envio, e avisa na sineta os que
     * são novos. Chamado depois de cada sincronização com o ML, de cada
     * ação na tela e pela rotina de acompanhamento (o alerta de rota
     * depende do relógio, não de evento nenhum).
     */
    public function reavaliar(ChannelShipment $envio, bool $notificar = true): void
    {
        try {
            $envio->loadMissing(['order.pickupReceipt', 'order.marketplaceClaims']);

            $impressa = $envio->order ? ($this->etiquetasImpressas([$envio->order_id])[$envio->order_id] ?? null) : null;
            $abertos = $this->alertasAbertos($envio, $impressa);
            $codigos = array_column($abertos, 'codigo');
            $jaAvisados = $envio->flex_alerts_notified ?? [];
            $novos = array_values(array_filter($abertos, fn (array $a) => ! in_array($a['codigo'], $jaAvisados, true)));

            $envio->forceFill([
                'flex_alerts' => $codigos ?: null,
                'flex_alerts_notified' => $novos
                    ? array_values(array_unique([...$jaAvisados, ...array_column($novos, 'codigo')]))
                    : ($envio->flex_alerts_notified ?: null),
            ]);

            if ($envio->isDirty()) {
                $envio->save();
                Cache::forget(self::CACHE_CONTAGEM);
            }

            if ($notificar && ! self::$silenciar && $novos && $envio->order) {
                $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

                foreach ($novos as $alerta) {
                    if ($admins->isNotEmpty()) {
                        Notification::send($admins, new FlexShipmentAlertNotification($envio->order, $alerta['titulo'], $alerta['detalhe']));
                    }

                    Log::warning('flex.controle.alerta', [
                        'pedido' => $envio->order_id,
                        'envio' => $envio->external_shipment_id,
                        'codigo' => $alerta['codigo'],
                    ]);
                }
            }
        } catch (Throwable $exception) {
            Log::warning('flex.controle.reavaliar_falhou', ['envio' => $envio->id, 'message' => $exception->getMessage()]);
        }
    }

    /** Contador do menu lateral: envios com pendência aberta. */
    public function contagemDeAlertas(): int
    {
        return (int) Cache::remember(self::CACHE_CONTAGEM, 300, fn () => ChannelShipment::query()
            ->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->where('shipping_method', ChannelShipment::METHOD_FLEX)
            ->whereNotNull('flex_alerts')
            ->count());
    }

    /**
     * Uma pessoa encerra os alertas abertos do envio — o produto voltou,
     * não vai voltar, ou foi conferido e não há problema. Grava quem, quando
     * e o porquê: é essa anotação que vale numa discussão futura.
     */
    public function resolver(ChannelShipment $envio, string $tipo, ?string $nota, User $usuario): void
    {
        $envio->loadMissing(['order.pickupReceipt', 'order.marketplaceClaims']);
        $impressa = $this->etiquetasImpressas([$envio->order_id])[$envio->order_id] ?? null;

        // Resolve só o que estava aberto AGORA, e antes de gravar a
        // resolução — por isso limpa as resolvidas pra recalcular do zero.
        $envio->flex_alerts_resolved = null;
        $codigos = array_column($this->alertasAbertos($envio, $impressa), 'codigo');

        $envio->forceFill([
            'return_resolution' => $tipo,
            'return_resolved_at' => now(),
            'return_resolved_by' => $usuario->id,
            'return_note' => $nota ? trim($nota) : null,
            'flex_alerts_resolved' => $codigos ?: null,
        ])->save();

        $this->reavaliar($envio, notificar: false);
    }

    public function desfazerResolucao(ChannelShipment $envio): void
    {
        $envio->forceFill([
            'return_resolution' => null,
            'return_resolved_at' => null,
            'return_resolved_by' => null,
            'return_note' => null,
            'flex_alerts_resolved' => null,
        ])->save();

        // Sem notificar: os alertas que voltam já tinham sido avisados.
        $this->reavaliar($envio, notificar: false);
    }

    /**
     * Onde o pacote está, numa palavra — a coluna "Situação" da tela.
     *
     * @return array{codigo: string, rotulo: string}
     */
    public function situacao(ChannelShipment $envio): array
    {
        $pedido = $envio->order;

        return match (true) {
            $pedido?->status === Order::STATUS_CANCELLED => ['codigo' => 'cancelada', 'rotulo' => 'Cancelada'],
            $envio->channel_returned_at !== null => ['codigo' => 'devolvida', 'rotulo' => 'Devolvida pelo ML'],
            $envio->channel_delivered_at !== null => ['codigo' => 'entregue', 'rotulo' => 'Entregue ao comprador'],
            $envio->channel_not_delivered_at !== null || $envio->channel_status === 'not_delivered' => ['codigo' => 'nao_entregue', 'rotulo' => 'Não entregue'],
            $envio->channel_shipped_at !== null => ['codigo' => 'em_rota', 'rotulo' => 'Em rota'],
            $pedido?->collected_at !== null => ['codigo' => 'com_entregador', 'rotulo' => 'Com o entregador, sem rota'],
            $pedido?->ready_for_pickup_at !== null => ['codigo' => 'pronta', 'rotulo' => 'Pronta, esperando coleta'],
            default => ['codigo' => 'na_loja', 'rotulo' => 'Na loja'],
        };
    }

    /**
     * Uma linha da tela, já com tudo do detalhe — abrir o detalhe não faz
     * requisição nova.
     *
     * @param  array<int, string>  $impressas
     * @param  array<int, string>  $usuarios
     * @return array<string, mixed>
     */
    public function linha(ChannelShipment $envio, array $impressas = [], array $usuarios = []): array
    {
        $pedido = $envio->order;
        $recibo = $pedido?->pickupReceipt;
        $impressa = $impressas[$envio->order_id] ?? null;
        $alertas = $this->alertas($envio, $impressa);

        return [
            'id' => $envio->id,
            'pedido' => $pedido?->id,
            'venda' => $pedido?->external_order_id,
            'envio' => $envio->external_shipment_id,
            'cliente' => trim((string) ($pedido?->shipping_recipient_name ?: $pedido?->shipping_name)) ?: null,
            'cidade' => $pedido ? trim(($pedido->shipping_city ?: '').($pedido->shipping_state ? '/'.$pedido->shipping_state : '')) : null,
            'endereco' => $pedido ? implode(' — ', array_filter([
                trim(($pedido->shipping_street ?? '').' '.($pedido->shipping_number ?? '')),
                $pedido->shipping_neighborhood,
                $pedido->shipping_zip,
            ])) : null,
            'itens' => $pedido?->items->map(fn ($item) => [
                'nome' => $item->product_name,
                'sku' => $item->product?->sku,
                'qtd' => (int) $item->quantity,
            ])->values()->all() ?? [],
            'total' => $pedido ? (float) $pedido->total : null,
            'statusPedido' => $pedido?->status,
            'situacao' => $this->situacao($envio),
            'linhaDoTempo' => [
                'vendidaEm' => $pedido?->created_at?->toIso8601String(),
                'etiquetaImpressaEm' => $impressa ? \Illuminate\Support\Carbon::parse($impressa)->toIso8601String() : null,
                'separadaEm' => $pedido?->packed_at?->toIso8601String(),
                'bipadaEm' => $pedido?->ready_for_pickup_at?->toIso8601String(),
                'entregueAoEntregadorEm' => $pedido?->collected_at?->toIso8601String(),
                'rotaIniciadaEm' => $envio->channel_shipped_at?->toIso8601String(),
                'primeiraVisitaEm' => $envio->channel_first_visit_at?->toIso8601String(),
                'entregueEm' => $envio->channel_delivered_at?->toIso8601String(),
                'naoEntregueEm' => $envio->channel_not_delivered_at?->toIso8601String(),
                'devolvidoEm' => $envio->channel_returned_at?->toIso8601String(),
                'canceladoEm' => $envio->channel_cancelled_at?->toIso8601String(),
                'estoqueDevolvidoEm' => $pedido?->stock_restored_at?->toIso8601String(),
            ],
            'canal' => [
                'status' => $envio->channel_status,
                'substatus' => $envio->channel_substatus,
                'conferidoEm' => $envio->channel_status_checked_at?->toIso8601String(),
            ],
            'recibo' => $recibo ? $this->recibo($recibo) : null,
            'alertas' => $alertas,
            'alertasAbertos' => count(array_filter($alertas, fn ($a) => $a['nivel'] !== self::NIVEL_INFO && ! $a['resolvido'])),
            'resolucao' => $envio->return_resolution ? [
                'tipo' => $envio->return_resolution,
                'rotulo' => self::RESOLUCOES[$envio->return_resolution] ?? $envio->return_resolution,
                'em' => $envio->return_resolved_at?->toIso8601String(),
                'por' => $usuarios[$envio->return_resolved_by] ?? null,
                'nota' => $envio->return_note,
            ] : null,
            'reclamacoes' => $pedido?->marketplaceClaims->map(fn (MarketplaceClaim $r) => [
                'id' => $r->external_claim_id,
                'tipo' => self::TIPOS_RECLAMACAO[$r->type] ?? ($r->type ?? 'Reclamação'),
                'status' => $r->status === 'opened' ? 'Aberta' : ($r->status === 'closed' ? 'Fechada' : $r->status),
                'abertaEm' => $r->claim_created_at?->toIso8601String(),
            ])->values()->all() ?? [],
        ];
    }

    /** @return array<string, mixed> */
    public function recibo(FlexPickupReceipt $recibo): array
    {
        return [
            'id' => $recibo->id,
            'entregador' => $recibo->carrier_name,
            'retiradoEm' => $recibo->collected_at?->toIso8601String(),
            'caixas' => $recibo->orders_count,
            'assinatura' => $recibo->signature_path !== null,
            'foto' => $recibo->photo_path !== null,
            'consentidoEm' => $recibo->consented_at?->toIso8601String(),
            'retido' => $recibo->retido(),
            'retidoEm' => $recibo->legal_hold_at?->toIso8601String(),
            'motivoRetencao' => $recibo->legal_hold_reason,
        ];
    }

    /**
     * Pendência aberta em algum pedido do recibo — o que impede a rotina de
     * retenção de apagar a foto: imagem de entrega em disputa é prova.
     */
    public function reciboEmDisputa(FlexPickupReceipt $recibo): bool
    {
        $pedidos = collect($recibo->order_ids ?? [])->filter()->all();

        if (! $pedidos) {
            return false;
        }

        $envios = $this->query()->whereIn('order_id', $pedidos)->get();
        $impressas = $this->etiquetasImpressas($pedidos);

        return $envios->contains(fn (ChannelShipment $envio) => $this->alertasAbertos($envio, $impressas[$envio->order_id] ?? null) !== []
            || $envio->return_resolution === ChannelShipment::RETURN_CLOSED_WITHOUT_ITEM);
    }

    private function hora(CarbonInterface|string|null $valor): string
    {
        if (! $valor) {
            return '—';
        }

        $data = $valor instanceof CarbonInterface ? $valor : \Illuminate\Support\Carbon::parse($valor);

        return $data->format('d/m H:i');
    }

    private function duracao(CarbonInterface $de, CarbonInterface $ate): string
    {
        $minutos = (int) abs($de->diffInMinutes($ate));

        if ($minutos < 60) {
            return "{$minutos} min";
        }

        $horas = intdiv($minutos, 60);

        return $horas < 48 ? "{$horas}h" : intdiv($horas, 24).' dias';
    }
}
