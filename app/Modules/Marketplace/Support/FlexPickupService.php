<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\FlexPickupReceipt;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\PrintJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * O motor do KoraFlex (app de celular, repositório à parte): a lista do que
 * a transportadora do Flex tem que levar HOJE, e a bipagem do QR da
 * etiqueta que registra "separado, etiquetado e pronto pra coleta".
 *
 * Nasceu em 2026-09-10 de um problema real com a transportadora do Flex:
 * sem registro de hora do que ficou pronto, discussão sobre pacote não
 * coletado vira palavra contra palavra. Cada bipada agora carimba hora no
 * pedido e na timeline.
 *
 * O QR DA ETIQUETA DO FLEX (decifrado numa etiqueta real, envio
 * 47981054232): o ZPL traz `^BQN,2,7^FDLA,{...}` com um JSON
 *
 *     {"id":"47981054232","sender_id":3283064948,"hash_code":"...","security_digit":"0"}
 *
 * e esse `id` é exatamente o external_shipment_id que já guardamos desde a
 * confirmação do envio — nenhuma consulta ao Mercado Livre é necessária pra
 * casar a bipada com a venda. Ver parseQr() pros formatos aceitos.
 */
class FlexPickupService
{
    /** Estado de cada venda na tela do dia. */
    public const ESTADO_PENDENTE = 'pendente';

    public const ESTADO_PRONTO = 'pronto';

    public const ESTADO_COLETADO = 'coletado';

    public function __construct(private readonly OrderFulfillmentTimeline $timeline) {}

    /**
     * A JANELA DO DIA, a regra que o usuário definiu junto com o app:
     * "se a venda saiu no dia após horário de corte, não deve aparecer,
     * isso seria regra para aparecer no envio do dia seguinte".
     *
     * Então o despacho de um dia D é tudo que foi vendido depois do corte
     * de D-1 e até o corte de D. Com corte às 12:00: uma venda de ontem
     * 15:30 sai hoje; uma de hoje 09:00 sai hoje; uma de hoje 12:01 sai
     * amanhã.
     *
     * A janela NÃO se move ao passar do meio-dia: às 15h de hoje a lista
     * ainda é a de hoje (as caixas continuam esperando a coleta de hoje) —
     * o que passou do corte já está contado no dia seguinte.
     *
     * @return array{de: CarbonImmutable, ate: CarbonImmutable}
     */
    public function janela(?CarbonImmutable $dia = null): array
    {
        $dia ??= CarbonImmutable::now();

        [$hora, $minuto] = $this->corte();

        $ate = $dia->setTime($hora, $minuto);

        return ['de' => $ate->subDay(), 'ate' => $ate];
    }

    /**
     * @return array{hora: string, entregas: list<array<string, mixed>>, atrasados: list<array<string, mixed>>, ...}
     */
    public function dia(?CarbonImmutable $referencia = null): array
    {
        $referencia ??= CarbonImmutable::now();
        $janela = $this->janela($referencia);

        $doDia = $this->flexQuery()
            ->whereBetween('orders.created_at', [$janela['de'], $janela['ate']])
            ->get();

        // ATRASADOS: venda de janela anterior que ainda não saiu daqui.
        // Sem esta lista, uma caixa esquecida de ontem some da tela hoje —
        // que é exatamente o tipo de sumiço que gerou a briga com a
        // transportadora.
        //
        // CORREÇÃO 2026-09-10 (o usuário viu ao testar): o filtro era
        // "ninguém bipou", então bipar uma atrasada a fazia DESAPARECER da
        // tela — sumia por ter dado certo, que é o pior tipo de sumiço, e
        // ela nem entrava no card "Prontas" porque é velha demais pra
        // janela do dia. Agora o que tira a caixa da lista é ela ter sido
        // ENTREGUE ao entregador (ou o canal dar a venda como enviada),
        // não ter sido bipada.
        $atrasados = $this->flexQuery()
            ->where('orders.created_at', '<', $janela['de'])
            ->where('orders.status', Order::STATUS_PAID)
            ->whereNull('orders.collected_at')
            ->get();

        $entregas = $doDia->map(fn (Order $order) => $this->paraTela($order));
        $atrasadas = $atrasados->map(fn (Order $order) => $this->paraTela($order));

        $pendentesDoDia = $entregas->where('estado', self::ESTADO_PENDENTE)->count();
        $prontasDoDia = $entregas->where('estado', self::ESTADO_PRONTO)->count();

        return [
            'dia' => $janela['ate']->toDateString(),
            'corte' => $this->corteFormatado(),
            'janela' => [
                'de' => $janela['de']->toDateTimeString(),
                'ate' => $janela['ate']->toDateTimeString(),
            ],
            // Vai pro texto do consentimento na tela de entrega: o app
            // promete ao entregador o prazo REAL de retenção da foto, não
            // um número escrito à mão que envelhece.
            'retencao_dias' => (int) config('services.koraflex.receipt_retention_days', 180),

            'total' => $entregas->count(),
            'pendentes' => $pendentesDoDia,

            // PRONTAS conta dia + atrasadas, pela mesma razão que "Faltam":
            // são as caixas que estão AGORA na área de coleta esperando o
            // entregador. Uma atrasada bipada está tão pronta quanto a de
            // hoje — e é ela que costuma ser esquecida.
            'prontos' => $prontasDoDia + $atrasadas->where('estado', self::ESTADO_PRONTO)->count(),
            'coletados' => $entregas->where('estado', self::ESTADO_COLETADO)->count(),

            // FALTAM = tudo que ainda tem que ser bipado, do dia MAIS o
            // atrasado. Pergunta do usuário em 2026-09-10, na primeira vez
            // que ele olhou a tela com uma caixa velha em aberto: "se tem
            // um em aberto atrasado, porque não está no card Faltam?".
            //
            // Estava fora porque o contador espelhava só a janela do dia —
            // e "Faltam" não é uma medida da janela, é a fila de trabalho
            // de quem está no galpão. Um contador que marca 0 com caixa na
            // prateleira é pior que contador nenhum: o atrasado é
            // justamente o que não pode ser esquecido de novo.
            //
            // 'total' continua sendo só o dia (a regra do corte que o
            // usuário definiu) — quem soma é a fila, não o total.
            'atrasados_total' => $atrasadas->count(),
            'faltam' => $pendentesDoDia + $atrasadas->where('estado', self::ESTADO_PENDENTE)->count(),

            // Os ids que o botão "Entreguei ao entregador" carimba de uma
            // vez — o motorista leva tudo junto, não de caixa em caixa.
            'prontas_ids' => $entregas->where('estado', self::ESTADO_PRONTO)->pluck('pedido')
                ->merge($atrasadas->where('estado', self::ESTADO_PRONTO)->pluck('pedido'))
                ->values()->all(),

            'entregas' => $entregas->values()->all(),
            'atrasados' => $atrasadas->values()->all(),
        ];
    }

    /**
     * Uma bipada. Devolve sempre o mesmo formato — quem decide a cor da
     * tela é o campo `ok` mais o `motivo`.
     *
     * @return array<string, mixed>
     */
    public function bipar(string $qr, ?string $dispositivo = null): array
    {
        $id = $this->parseQr($qr);

        if (! $id) {
            return $this->recusa('qr_ilegivel', 'Não consegui ler essa etiqueta. Aponte pro QR do Flex, não pro código de barras.');
        }

        $shipment = ChannelShipment::query()
            ->where(fn ($query) => $query->where('external_shipment_id', $id)->orWhere('tracking_code', $id))
            ->with('order')
            ->latest('id')
            ->first();

        if (! $shipment || ! $shipment->order) {
            return $this->recusa('nao_encontrada', "Etiqueta {$id} não é de nenhuma venda daqui.");
        }

        $order = $shipment->order;

        // Venda cancelada NUNCA pode ir pra transportadora — este é o
        // aviso que só existe porque a caixa já está fechada na mão de
        // alguém quando a bipada acontece.
        if ($order->status === Order::STATUS_CANCELLED) {
            return $this->recusa('cancelada', 'VENDA CANCELADA — não entregue essa caixa.', $order, $shipment);
        }

        if ($shipment->shipping_method !== ChannelShipment::METHOD_FLEX) {
            return $this->recusa(
                'nao_e_flex',
                'Essa etiqueta não é do Flex — essa venda vai por outro envio, não entra na coleta.',
                $order,
                $shipment,
            );
        }

        // PACK: dois pedidos do mesmo comprador podem dividir UMA etiqueta
        // (achado real no pack 2000014900875351, pedidos #1540/#1541). Uma
        // caixa, uma bipada, os dois pedidos carimbados — senão o segundo
        // fica "pendente" pra sempre numa caixa que já foi embora.
        $irmaos = $this->pedidosDoMesmoEnvio($shipment);

        $jaEstavaPronto = $order->ready_for_pickup_at;

        if (! $jaEstavaPronto) {
            $agora = now();

            DB::transaction(function () use ($irmaos, $agora, $dispositivo) {
                foreach ($irmaos as $irmao) {
                    $irmao->forceFill([
                        // Bipar É a separação concluída: a caixa está
                        // fechada e etiquetada na mão de quem bipou.
                        'packed_at' => $irmao->packed_at ?? $agora,
                        'ready_for_pickup_at' => $agora,
                    ])->save();

                    $this->timeline->record(
                        $irmao,
                        OrderFulfillmentEvent::STEP_READY_FOR_PICKUP,
                        OrderFulfillmentEvent::STATUS_SUCCESS,
                        'Bipado no KoraFlex: etiquetado e pronto pra coleta',
                        ['dispositivo' => $dispositivo],
                    );
                }
            });

            Log::info('koraflex.bipado', [
                'shipment' => $shipment->external_shipment_id,
                'pedidos' => $irmaos->pluck('id')->all(),
                'dispositivo' => $dispositivo,
            ]);
        }

        $order->refresh();

        return [
            'ok' => true,
            'motivo' => $jaEstavaPronto ? 'ja_estava_pronto' : 'pronto',
            'mensagem' => $jaEstavaPronto
                ? 'Essa já tinha sido bipada às '.$jaEstavaPronto->format('H:i').'.'
                : 'Pronto pra coleta.',
            'ja_estava_pronto_em' => $jaEstavaPronto?->format('d/m/Y H:i'),
            'venda' => $this->paraTela($order->loadMissing(['items', 'channelShipment'])),
            'no_pack' => $irmaos->count() > 1 ? $irmaos->pluck('id')->all() : null,
        ];
    }

    /**
     * "Entreguei ao entregador" — o botão que tira as caixas da área de
     * coleta e fecha o ciclo, com hora.
     *
     * Recebe uma lista porque é assim que acontece de verdade: o motorista
     * chega, leva TUDO que está pronto, e ninguém vai carimbar caixa por
     * caixa com ele esperando na porta.
     *
     * Só carimba o que já foi bipado: entregar o que ninguém conferiu é
     * exatamente o buraco que o app existe pra fechar.
     *
     * @param  list<int>  $pedidos
     * @return array<string, mixed>
     */
    public function coletar(array $pedidos, ?string $dispositivo = null, ?FlexPickupReceipt $recibo = null): array
    {
        $ordens = Order::query()
            ->whereIn('id', $pedidos)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->get();

        $entregues = [];
        $ignorados = [];
        $agora = now();

        DB::transaction(function () use ($ordens, $agora, $dispositivo, $recibo, &$entregues, &$ignorados) {
            foreach ($ordens as $order) {
                if (! $order->ready_for_pickup_at) {
                    $ignorados[] = $order->id;

                    continue;
                }

                if ($order->collected_at) {
                    continue;
                }

                $order->forceFill([
                    'collected_at' => $agora,
                    'pickup_receipt_id' => $recibo?->id,
                ])->save();

                $this->timeline->record(
                    $order,
                    OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER,
                    OrderFulfillmentEvent::STATUS_SUCCESS,
                    $recibo?->assinado()
                        ? 'Entregue ao entregador do Flex, com assinatura (KoraFlex)'
                        : 'Entregue ao entregador do Flex (KoraFlex)',
                    ['dispositivo' => $dispositivo, 'recibo' => $recibo?->id],
                );

                $entregues[] = $order->id;
            }
        });

        Log::info('koraflex.coletado', [
            'entregues' => $entregues,
            'ignorados' => $ignorados,
            'dispositivo' => $dispositivo,
        ]);

        $quantidade = count($entregues);

        return [
            'ok' => true,
            'motivo' => 'coletado',
            'mensagem' => match (true) {
                $quantidade === 0 => 'Nada novo pra entregar.',
                $quantidade === 1 => '1 caixa entregue ao entregador às '.$agora->format('H:i').'.',
                default => "{$quantidade} caixas entregues ao entregador às ".$agora->format('H:i').'.',
            },
            'entregues' => $entregues,
            'ignorados' => $ignorados,
        ];
    }

    /**
     * "Conferida e entregue" com comprovante: a mesma entrega do coletar(),
     * mas guardando assinatura, foto e consentimento do entregador.
     *
     * Pedido do usuário em 2026-09-10: "um campo de assinatura, que pode
     * ser um visto feito pelo dedo mesmo, mas quando a pessoa clica em
     * enviar, tira uma foto dele, mas antes de assinar colocar um aviso de
     * consentimento".
     *
     * O CONSENTIMENTO NÃO É FORMALIDADE. Assinatura e foto são dados
     * pessoais de alguém que não trabalha aqui — o entregador. Por isso:
     *
     * - sem `consentimento` verdadeiro, nada de imagem é gravado (a entrega
     *   até acontece, mas vira uma baixa simples, sem recibo assinado);
     * - o TEXTO do aviso mostrado na tela vem junto e fica gravado no
     *   recibo, pra daqui a seis meses ser possível dizer exatamente com o
     *   que ele concordou, mesmo que o texto tenha mudado desde então;
     * - as imagens vão pro disco local (nunca uma pasta pública) e são
     *   apagadas depois de `services.koraflex.receipt_retention_days` — ver
     *   o comando koraflex:limpar-recibos.
     *
     * @param  list<int>  $pedidos
     * @return array<string, mixed>
     */
    public function entregar(
        array $pedidos,
        bool $consentimento,
        ?string $assinatura = null,
        ?string $foto = null,
        ?string $nome = null,
        ?string $dispositivo = null,
        ?string $textoDoAviso = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): array {
        $agora = now();

        $recibo = FlexPickupReceipt::create([
            'carrier_name' => $nome ? trim($nome) : null,
            'collected_at' => $agora,
            'device' => $dispositivo,
            // IP e navegador de onde saiu o registro: junto com o hash das
            // imagens, é o que sustenta o recibo como prova (2026-09-11).
            'ip_address' => $ip ? mb_substr($ip, 0, 45) : null,
            'user_agent' => $userAgent ? mb_substr($userAgent, 0, 255) : null,
            'consented_at' => $consentimento ? $agora : null,
            'consent_text' => $consentimento ? $textoDoAviso : null,
            'signature_path' => null,
            'photo_path' => null,
            'order_ids' => [],
            'orders_count' => 0,
        ]);

        // As imagens só existem com o consentimento de pé.
        if ($consentimento) {
            [$assinaturaPath, $assinaturaHash] = $this->guardarImagem($assinatura, $recibo->id, 'assinatura', ['image/png'], 2_000_000);
            [$fotoPath, $fotoHash] = $this->guardarImagem($foto, $recibo->id, 'foto', ['image/jpeg', 'image/png'], 5_000_000);

            $recibo->forceFill([
                'signature_path' => $assinaturaPath,
                'signature_sha256' => $assinaturaHash,
                'photo_path' => $fotoPath,
                'photo_sha256' => $fotoHash,
            ])->save();
        }

        $resultado = $this->coletar($pedidos, $dispositivo, $recibo);

        $recibo->forceFill([
            'order_ids' => $resultado['entregues'],
            'orders_count' => count($resultado['entregues']),
        ])->save();

        // Recibo sem nenhuma caixa é lixo: o entregador não levou nada.
        if ($recibo->orders_count === 0) {
            $this->apagarImagens($recibo);
            $recibo->delete();

            return $resultado + ['recibo' => null];
        }

        return $resultado + [
            'recibo' => [
                'id' => $recibo->id,
                'assinado' => $recibo->assinado(),
                'com_foto' => $recibo->photo_path !== null,
                'entregador' => $recibo->carrier_name,
                'hora' => $recibo->collected_at->format('d/m/Y H:i'),
                'caixas' => $recibo->orders_count,
            ],
        ];
    }

    /**
     * Grava uma imagem que chegou como data URL do celular.
     *
     * Confere o cabeçalho declarado E os primeiros bytes do arquivo: o que
     * o navegador diz que mandou não é prova de nada, e isto aqui é um
     * endpoint que aceita arquivo de fora.
     *
     * Devolve o caminho e o SHA-256 dos bytes gravados — o hash fica no
     * recibo pra provar, depois, que o arquivo não foi trocado.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function guardarImagem(?string $dataUrl, int $reciboId, string $nome, array $tiposAceitos, int $limiteBytes): array
    {
        if (! $dataUrl) {
            return [null, null];
        }

        if (! preg_match('#^data:(image/[a-z+]+);base64,(.+)$#is', trim($dataUrl), $partes)) {
            Log::warning('koraflex.recibo.imagem_invalida', ['recibo' => $reciboId, 'campo' => $nome]);

            return [null, null];
        }

        [, $tipo, $base64] = $partes;

        if (! in_array(strtolower($tipo), $tiposAceitos, true)) {
            Log::warning('koraflex.recibo.tipo_recusado', ['recibo' => $reciboId, 'campo' => $nome, 'tipo' => $tipo]);

            return [null, null];
        }

        $conteudo = base64_decode($base64, true);

        if ($conteudo === false || strlen($conteudo) > $limiteBytes) {
            Log::warning('koraflex.recibo.imagem_recusada', [
                'recibo' => $reciboId,
                'campo' => $nome,
                'bytes' => $conteudo === false ? null : strlen($conteudo),
            ]);

            return [null, null];
        }

        $ehPng = str_starts_with($conteudo, "\x89PNG\r\n\x1a\n");
        $ehJpeg = str_starts_with($conteudo, "\xFF\xD8\xFF");

        if (! $ehPng && ! $ehJpeg) {
            Log::warning('koraflex.recibo.bytes_nao_sao_imagem', ['recibo' => $reciboId, 'campo' => $nome]);

            return [null, null];
        }

        $caminho = "flex/recibos/{$reciboId}/{$nome}.".($ehPng ? 'png' : 'jpg');

        if (! Storage::disk('local')->put($caminho, $conteudo)) {
            Log::error('koraflex.recibo.gravacao_falhou', ['recibo' => $reciboId, 'campo' => $nome]);

            return [null, null];
        }

        return [$caminho, hash('sha256', $conteudo)];
    }

    public function apagarImagens(FlexPickupReceipt $recibo): void
    {
        foreach ([$recibo->signature_path, $recibo->photo_path] as $caminho) {
            if ($caminho && Storage::disk('local')->exists($caminho)) {
                Storage::disk('local')->delete($caminho);
            }
        }
    }

    /**
     * Desfaz UM passo, o último: se a caixa já tinha sido entregue ao
     * entregador, volta pra "pronta"; se estava só bipada, volta pra
     * "pendente".
     *
     * Um passo de cada vez porque os dois enganos são diferentes — "marquei
     * entregue antes da hora" e "bipei a caixa errada" — e quem toca no
     * botão está no meio da operação, sem tempo pra escolher entre opções.
     * A separação (packed_at) nunca é desfeita: ela continua verdadeira.
     *
     * @return array<string, mixed>
     */
    public function desfazer(int $orderId): array
    {
        $order = Order::query()->find($orderId);

        if (! $order) {
            return $this->recusa('nao_encontrada', "Pedido #{$orderId} não existe.");
        }

        if ($order->collected_at) {
            $order->forceFill(['collected_at' => null])->save();

            $this->timeline->record(
                $order,
                OrderFulfillmentEvent::STEP_HANDED_TO_CARRIER,
                OrderFulfillmentEvent::STATUS_FAILED,
                'Entrega ao entregador desfeita no KoraFlex',
            );

            return [
                'ok' => true,
                'motivo' => 'desfeita_entrega',
                'mensagem' => 'Voltou pra pronta, esperando o entregador.',
                'venda' => $this->paraTela($order->loadMissing(['items', 'channelShipment'])),
            ];
        }

        if (! $order->ready_for_pickup_at) {
            return $this->recusa('nao_estava_pronto', 'Essa venda não estava marcada como pronta.', $order);
        }

        $order->forceFill(['ready_for_pickup_at' => null])->save();

        $this->timeline->record(
            $order,
            OrderFulfillmentEvent::STEP_READY_FOR_PICKUP,
            OrderFulfillmentEvent::STATUS_FAILED,
            'Bipagem desfeita no KoraFlex',
        );

        return [
            'ok' => true,
            'motivo' => 'desfeito',
            'mensagem' => 'Voltou pra pendente.',
            'venda' => $this->paraTela($order->loadMissing(['items', 'channelShipment'])),
        ];
    }

    /** @return Collection<int, Order> */
    private function pedidosDoMesmoEnvio(ChannelShipment $shipment): Collection
    {
        if (! $shipment->external_shipment_id) {
            return collect([$shipment->order]);
        }

        $ids = ChannelShipment::query()
            ->where('channel', $shipment->channel)
            ->where('external_shipment_id', $shipment->external_shipment_id)
            ->pluck('order_id')
            ->unique();

        return Order::query()
            ->whereIn('id', $ids)
            ->where('status', '!=', Order::STATUS_CANCELLED)
            ->get();
    }

    /**
     * Só Mercado Livre + self_service: a coleta do Flex é a do Mercado
     * Livre. Shopee tem coleta própria e TikTok nem passa por aqui.
     */
    private function flexQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return Order::query()
            ->nonPurchaseReturn()
            ->where('orders.origin', Order::ORIGIN_MERCADO_LIVRE)
            ->where('orders.status', '!=', Order::STATUS_CANCELLED)
            ->whereHas('channelShipment', function ($query) {
                $query->where('channel', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
                    ->where('shipping_method', ChannelShipment::METHOD_FLEX);
            })
            ->with(['items:id,order_id,product_id,product_name,quantity', 'items.product:id,sku', 'channelShipment'])
            ->orderBy('orders.created_at');
    }

    /** @return array<string, mixed> */
    private function paraTela(Order $order): array
    {
        $shipment = $order->channelShipment;

        return [
            'pedido' => $order->id,
            'venda' => $order->external_order_id,
            'envio' => $shipment?->external_shipment_id,
            'rastreio' => $shipment?->tracking_code,
            'cliente' => $this->nomeDoCliente($order),
            'cidade' => trim(($order->shipping_city ?: '').($order->shipping_state ? '/'.$order->shipping_state : '')),
            'bairro' => $order->shipping_neighborhood,
            'vendida_em' => $order->created_at?->format('d/m H:i'),
            'itens' => $order->items->map(fn ($item) => [
                'sku' => $item->product?->sku,
                'nome' => $item->product_name,
                'qtd' => (int) $item->quantity,
            ])->values()->all(),
            'pecas' => (int) $order->items->sum('quantity'),
            'estado' => $this->estado($order),
            'separado_em' => $order->packed_at?->format('d/m H:i'),
            'pronto_em' => $order->ready_for_pickup_at?->format('d/m H:i'),
            'coletado_em' => $order->collected_at?->format('d/m H:i'),
            'etiqueta_impressa' => $this->etiquetaImpressa($order),
        ];
    }

    /**
     * COLETADA quer dizer "saiu daqui": ou alguém entregou em mãos ao
     * entregador (o botão do app, `collected_at`), ou o canal já deu a
     * venda como enviada.
     *
     * O carimbo humano existe porque o do canal chega tarde demais — o
     * Mercado Livre só marca "enviada" quando o pacote é lido lá na ponta,
     * às vezes horas depois do motorista ter saído da loja. Pra discussão
     * com a transportadora, a hora que vale é a da entrega em mãos.
     */
    private function estado(Order $order): string
    {
        if ($order->collected_at || in_array($order->status, [Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true)) {
            return self::ESTADO_COLETADO;
        }

        return $order->ready_for_pickup_at ? self::ESTADO_PRONTO : self::ESTADO_PENDENTE;
    }

    private function etiquetaImpressa(Order $order): bool
    {
        return PrintJob::query()
            ->where('order_id', $order->id)
            ->where('is_thank_you', false)
            ->where('status', PrintJob::STATUS_PRINTED)
            ->exists();
    }

    /** Mesma regra de exibição da fila do KoraSync: nome mascarado não engana ninguém. */
    private function nomeDoCliente(Order $order): string
    {
        $nome = trim((string) ($order->shipping_recipient_name ?: $order->shipping_name));

        if ($nome === '' || str_contains($nome, '*')) {
            return 'Cliente (dados ocultados pelo canal)';
        }

        return $nome;
    }

    /**
     * Aceita o QR inteiro do Flex (JSON), o JSON já extraído, ou só o
     * número — celular velho, leitor externo e digitação manual chegam de
     * jeitos diferentes e todos têm que funcionar com a caixa na mão.
     */
    public function parseQr(string $qr): ?string
    {
        $qr = trim($qr);

        if ($qr === '') {
            return null;
        }

        // O ZPL prefixa o conteúdo com "LA," (modo alfanumérico do ^BQ);
        // alguns leitores entregam isso junto.
        $limpo = preg_replace('/^LA,/i', '', $qr) ?? $qr;

        $json = json_decode($limpo, true);

        if (is_array($json) && isset($json['id'])) {
            return preg_replace('/\D/', '', (string) $json['id']) ?: null;
        }

        // Número puro (o próprio id do envio, ou o rastreio digitado).
        if (preg_match('/^\d{6,20}$/', $limpo)) {
            return $limpo;
        }

        // Última tentativa: um id de envio no meio de qualquer texto.
        if (preg_match('/"id"\s*:\s*"?(\d{6,20})"?/', $limpo, $m)) {
            return $m[1];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function recusa(string $motivo, string $mensagem, ?Order $order = null, ?ChannelShipment $shipment = null): array
    {
        return [
            'ok' => false,
            'motivo' => $motivo,
            'mensagem' => $mensagem,
            'venda' => $order ? $this->paraTela($order->loadMissing(['items', 'channelShipment'])) : null,
        ];
    }

    /** @return array{0: int, 1: int} */
    private function corte(): array
    {
        $corte = (string) config('services.koraflex.cutoff', '12:00');

        if (! preg_match('/^(\d{1,2}):(\d{2})$/', trim($corte), $m)) {
            Log::warning('koraflex.corte_invalido', ['valor' => $corte]);

            return [12, 0];
        }

        return [(int) $m[1], (int) $m[2]];
    }

    private function corteFormatado(): string
    {
        [$hora, $minuto] = $this->corte();

        return sprintf('%02d:%02d', $hora, $minuto);
    }
}
