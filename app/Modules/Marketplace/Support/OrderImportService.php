<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderFulfillmentEvent;
use App\Modules\Checkout\Support\OrderFulfillmentTimeline;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\Fiscal\Jobs\GenerateInvoiceJob;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Marketplace\Drivers\MarketplaceDriverManager;
use App\Modules\Marketplace\Jobs\ConfirmChannelShippingJob;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Notifications\ProductAutoImportedNotification;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Canal-agnóstico de propósito: só fala com MarketplaceChannelDriver
 * (interface), nunca com a API de um canal específico. Cada driver já
 * devolve o pedido no formato comum declarado em
 * MarketplaceChannelDriver::importOrder() — esse serviço só sabe transformar
 * esse formato em Order/OrderItem e debitar o estoque central, do mesmo
 * jeito que o checkout do site já faz.
 */
class OrderImportService
{
    public function __construct(
        private readonly MarketplaceDriverManager $manager,
        private readonly StockManager $stock,
        private readonly OrderPaymentFinalizer $finalizer,
        private readonly OrderFulfillmentTimeline $timeline,
    ) {
    }

    public function import(string $channel, string $externalOrderId): Order
    {
        $data = $this->manager->driver($channel)->importOrder($externalOrderId);

        return $this->importNormalized($channel, $data);
    }

    /**
     * Mesmo caminho real do import via webhook (pedido, itens, débito de
     * estoque, disparo de etiqueta/nota), mas recebendo os dados já
     * normalizados em vez de buscar via driver — ponto de entrada usado
     * pela tela de teste de webhook (Admin/Impressoes/TesteWebhook), que
     * não tem um pedido de verdade em nenhum marketplace pra consultar.
     *
     * @param  array<string, mixed>  $data
     */
    public function importNormalized(string $channel, array $data, bool $dispatchShippingConfirmation = true): Order
    {
        $existing = Order::query()
            ->where('origin', $channel)
            ->where('external_order_id', $data['external_order_id'])
            ->first();

        if ($existing) {
            $this->timeline->record($existing, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Webhook reentregue ({$channel}), status={$data['status']}");

            // Autocorreção pra pedidos que entraram antes de placed_at
            // existir (achado real 2026-08-06, ver comentário em
            // createOrder()) — reprocessar o backfill já corrige a data sem
            // precisar de um script separado.
            if (! empty($data['placed_at']) && ! $existing->created_at->equalTo($data['placed_at'])) {
                $existing->forceFill(['created_at' => $data['placed_at']])->save();
            }

            // Mesma lógica pro total (achado real 2026-08-06,
            // ShopeeDriver::importOrder() — total_amount da Shopee nunca
            // incluía o frete, todo pedido já importado antes desse fix
            // ficou com o total sub-contado). Reprocessar o sync já
            // corrige os pedidos existentes, sem script separado.
            $financialFields = array_filter([
                'subtotal' => $data['subtotal'] ?? null,
                'shipping_cost' => $data['shipping_cost'] ?? null,
                'total' => $data['total'] ?? null,
            ], fn ($value) => $value !== null);

            $changed = array_filter($financialFields, fn ($value, $field) => (float) $existing->{$field} !== (float) $value, ARRAY_FILTER_USE_BOTH);

            if ($changed) {
                $existing->update($changed);
            }

            $buyerFields = $this->resolveBuyerFieldUpdates($existing, $data);

            if ($buyerFields) {
                $existing->update($buyerFields);
            }

            return $this->syncStatus($existing, $data['status']);
        }

        try {
            return $this->createOrder($channel, $data, $dispatchShippingConfirmation);
        } catch (QueryException $exception) {
            // Reentrega de webhook quase simultânea pode passar pelo check
            // de existência acima antes do outro processo commitar — o
            // índice único (origin, external_order_id) pega isso na hora do
            // insert. Trata como já importado em vez de estourar erro.
            if ($exception->getCode() !== '23000') {
                throw $exception;
            }

            // BUG REAL 2026-08-08 encontrado por causa disto: nem toda
            // QueryException 23000 dentro de createOrder() é essa corrida
            // (ex: um NOT NULL de outra tabela gravada na mesma
            // transação, como product_fiscal_data.tipo_operacao —
            // ver ShopeeDriver::importFiscalData()). Antes, firstOrFail()
            // MASCARAVA esse erro completamente diferente como
            // "ModelNotFoundException: No query results for model Order",
            // fazendo o pedido inteiro sumir sem log nenhum do problema
            // real. Confirma que o pedido REALMENTE existe (corrida de
            // verdade) antes de tratar como sucesso — se não existir, era
            // outra coisa, relança a exceção original pra aparecer no
            // shopee.log/failed_jobs com a mensagem real, útil de
            // debugar, em vez de uma pista falsa.
            $order = Order::query()
                ->where('origin', $channel)
                ->where('external_order_id', $data['external_order_id'])
                ->first();

            if (! $order) {
                throw $exception;
            }

            return $this->syncStatus($order, $data['status']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createOrder(string $channel, array $data, bool $dispatchShippingConfirmation = true): Order
    {
        return DB::transaction(function () use ($channel, $data, $dispatchShippingConfirmation) {
            $order = Order::create([
                'user_id' => null,
                'status' => $data['status'],
                'origin' => $channel,
                'external_order_id' => $data['external_order_id'],
                'buyer_document' => $data['buyer_document'] ?? null,
                'shipping_name' => $data['buyer_name'],
                'shipping_phone' => $data['buyer_phone'] ?? 'Não informado',
                'shipping_email' => $data['buyer_email'] ?? null,
                'shipping_whatsapp' => $data['buyer_whatsapp'] ?? null,
                'shipping_zip' => $data['shipping_zip'],
                'shipping_street' => $data['shipping_street'],
                'shipping_number' => $data['shipping_number'],
                'shipping_complement' => $data['shipping_complement'],
                'shipping_neighborhood' => $data['shipping_neighborhood'],
                'shipping_city' => $data['shipping_city'],
                'shipping_state' => $data['shipping_state'],
                'subtotal' => $data['subtotal'],
                'shipping_cost' => $data['shipping_cost'],
                'total' => $data['total'],
            ]);

            // created_at não está no $fillable de Order (proteção normal de
            // mass-assignment) — Order::create() acima sempre grava now(),
            // então corrige com forceFill logo em seguida quando o canal
            // manda a data real da venda (placed_at). Achado real
            // 2026-08-06 (backfill de pedidos antigos do Mercado
            // Livre/Shopee): sem isso, um pedido de meses atrás importado
            // hoje aparecia como "vendido hoje" nos cards de faturamento.
            // Sem placed_at (tela de teste de webhook, pedido fake), fica
            // now() mesmo, que já é o comportamento correto pra isso.
            if (! empty($data['placed_at'])) {
                $order->forceFill(['created_at' => $data['placed_at']])->save();
            }

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Pedido importado do canal {$channel}", ['external_order_id' => $data['external_order_id']]);

            $unmappedItems = [];

            foreach ($data['items'] as $item) {
                $listing = ProductChannelListing::query()
                    ->where('channel', $channel)
                    ->where('external_id', $item['external_id'])
                    ->first();
                $product = $listing?->product;
                $autoImported = false;

                // Item de um anúncio que nunca foi trazido pro catálogo
                // local (feito direto no canal, fora do Kazakora) — tenta
                // importar automaticamente em vez de deixar o pedido
                // inteiro sem produto, o que travava a NF-e/etiqueta pra
                // sempre (o canal recusa liberar o envio sem nota válida,
                // e sem produto local não tem como emitir nota nenhuma).
                // Entra como rascunho, com dados fiscais quando o canal já
                // tem isso preenchido — ver
                // MarketplaceChannelDriver::autoImportProduct(). Canais sem
                // essa capacidade (ainda) implementada devolvem null, igual
                // ao comportamento anterior. Passa a quantidade desta
                // própria venda pro driver poder somar de volta ao estoque
                // "atual" que ele buscar no canal — ver comentário lá:
                // sem isso, a baixa abaixo contaria esta venda 2x.
                if (! $product) {
                    $product = $this->manager->driver($channel)->autoImportProduct($item['external_id'], $item['quantity']);
                    $autoImported = (bool) $product;

                    if ($product) {
                        Log::info('marketplace.order_import.product_auto_imported', [
                            'channel' => $channel,
                            'external_order_id' => $data['external_order_id'],
                            'item_external_id' => $item['external_id'],
                            'product_id' => $product->id,
                        ]);
                    }
                }

                $order->items()->create([
                    'product_id' => $product?->id,
                    'product_name' => $product?->name
                        ?? ($item['external_name'] ?? null)
                        ?? "Item {$item['external_id']} (sem produto local mapeado)",
                    'product_price' => $item['unit_price'],
                    'quantity' => $item['quantity'],
                    'subtotal' => round($item['unit_price'] * $item['quantity'], 2),
                ]);

                if (! $product) {
                    Log::warning('marketplace.order_import.unmapped_item', [
                        'channel' => $channel,
                        'external_order_id' => $data['external_order_id'],
                        'item_external_id' => $item['external_id'],
                    ]);

                    $unmappedItems[] = $item['external_id'];

                    continue;
                }

                if ($autoImported) {
                    // Avisa o admin na hora, não só quando a nota falhar lá
                    // na frente (esse aviso genérico não deixa óbvio que é
                    // um produto novo precisando de revisão manual).
                    $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

                    if ($admins->isNotEmpty()) {
                        Notification::send($admins, new ProductAutoImportedNotification($product, $order));
                    }
                }

                $this->stock->adjust(
                    $product,
                    -$item['quantity'],
                    StockMovement::TYPE_SALE,
                    reason: 'Venda importada — '.$channel,
                    reference: $order,
                );
            }

            if ($unmappedItems) {
                $this->timeline->record($order, OrderFulfillmentEvent::STEP_STOCK_UPDATED, OrderFulfillmentEvent::STATUS_FAILED, 'Itens sem produto local mapeado, estoque não debitado para eles', ['unmapped_external_ids' => $unmappedItems]);
            } else {
                $this->timeline->record($order, OrderFulfillmentEvent::STEP_STOCK_UPDATED, OrderFulfillmentEvent::STATUS_SUCCESS, 'Estoque central debitado para todos os itens');
            }

            if (! empty($data['external_shipment_id'])) {
                ChannelShipment::query()->updateOrCreate(
                    ['order_id' => $order->id, 'channel' => $channel],
                    ['external_shipment_id' => $data['external_shipment_id']],
                );
            }

            // Nem todo driver retorna 'marketplace_fee' hoje (Shopee/TikTok
            // ainda são stubs sem integração real) — só grava quando o dado é
            // real, nunca inventa um valor pra canal sem essa informação.
            if (array_key_exists('marketplace_fee', $data)) {
                OrderChannelFee::query()->updateOrCreate(
                    ['order_id' => $order->id, 'channel' => $channel],
                    [
                        'gross_amount' => $data['total'],
                        'fee_amount' => $data['marketplace_fee'],
                        'source' => OrderChannelFee::SOURCE_API,
                        'computed_at' => now(),
                    ],
                );
            }

            // Etiqueta e nota fiscal são dois pipelines paralelos e
            // independentes a partir daqui — nenhum bloqueia o outro. Antes,
            // a confirmação de envio só disparava depois que a nota fiscal
            // era emitida E aceita pelo canal; com o certificado da NF-e
            // quebrado, isso travava a etiqueta inteira também. Agora a
            // etiqueta anda sozinha assim que o pedido é pago, e a nota
            // fiscal segue seu próprio caminho (fila dedicada, ver
            // GenerateInvoiceJob) sem afetar o envio.
            if ($data['status'] === Order::STATUS_PAID) {
                // Desligado pela tela de teste de webhook: o pedido fake não
                // existe de verdade no canal, então confirmar o envio pela
                // API real (ConfirmChannelShippingJob -> driver real) só
                // devolveria erro. O controller de teste simula esse trecho
                // diretamente em vez de disparar o job real.
                if ($dispatchShippingConfirmation) {
                    ConfirmChannelShippingJob::dispatch($order->id)->afterCommit();
                }

                GenerateInvoiceJob::dispatch($order->id)->afterCommit();
            }

            return $order;
        });
    }

    /**
     * Ordem "de progresso" normal de um pedido — usado só pra bloquear
     * regressão (ver isStaleStatus() abaixo), nunca pra decidir uma
     * transição válida (isso continua sendo responsabilidade de cada
     * driver/mapOrderStatus()).
     */
    private const STATUS_PROGRESS = [
        Order::STATUS_PENDING => 0,
        Order::STATUS_AWAITING_PAYMENT => 1,
        Order::STATUS_PAID => 2,
        Order::STATUS_SHIPPED => 3,
        Order::STATUS_COMPLETED => 4,
    ];

    /**
     * BUG REAL encontrado 2026-08-07 (pedido #158, Shopee): um webhook de
     * status "atrasado" (chegou depois de outro mais recente já ter
     * avançado o pedido — no caso, TO_CONFIRM_RECEIVE chegando depois de
     * SHIPPED, mapeado à época incorretamente pra `paid`, ver
     * ShopeeDriver::mapOrderStatus()) fez o pedido regredir de `shipped`
     * pra `paid` e reprocessou envio/nota fiscal de um pedido que já
     * estava com etiqueta impressa. O mapeamento errado específico já foi
     * corrigido, mas essa trava aqui é a defesa de verdade: nenhum canal
     * (não só a Shopee) deveria conseguir mover um pedido pra trás na
     * esteira normal (pending → awaiting_payment → paid → shipped →
     * completed) via reimportação/webhook — só forward, ou pra
     * `cancelled` (sempre aceito, de qualquer estado, é um evento real
     * mesmo vindo fora de ordem). Pedido cancelado nunca "reabre" via
     * webhook atrasado.
     */
    private function isStaleStatus(string $current, string $newStatus): bool
    {
        if ($newStatus === Order::STATUS_CANCELLED) {
            return false;
        }

        if ($current === Order::STATUS_CANCELLED) {
            return true;
        }

        $currentRank = self::STATUS_PROGRESS[$current] ?? null;
        $newRank = self::STATUS_PROGRESS[$newStatus] ?? null;

        if ($currentRank === null || $newRank === null) {
            return false;
        }

        return $newRank < $currentRank;
    }

    /**
     * Acha real 2026-08-08 (pedido #189): a Shopee mascara o nome do
     * comprador ("E******a") e simplesmente OMITE buyer_cpf_id em
     * get_order_detail() até o pedido avançar pra um status pago/pronto
     * pra envio — confirmado ao vivo, o mesmo order_sn consultado de novo
     * minutos depois já veio com nome completo e CPF reais. Se o webhook
     * que criou o pedido chegou ANTES disso acontecer, buyer_document
     * ficava vazio e shipping_name mascarado PRA SEMPRE (nada reconsultava
     * depois), e GenerateInvoiceJob falhava sem parar com "não foi
     * possível identificar o CPF/CNPJ do comprador" até esgotar as 3
     * tentativas — mesmo a Shopee já tendo o dado real disponível.
     *
     * Só troca vazio/mascarado por preenchido/desmascarado, nunca o
     * contrário — um pedido que já tem dado bom nunca regride.
     *
     * Bug real 2026-08-08 (pedido do próprio usuário: "maioria sem
     * contato"): esta função nunca tocava em shipping_email, e só
     * reconhecia telefone "incompleto" quando já vinha com `*` — um
     * pedido que nasceu com buyer_phone nulo (por isso gravado como o
     * literal "Não informado" em createOrder()) ficava preso nesse
     * estado pra sempre, mesmo que um webhook seguinte trouxesse o
     * telefone real. E-mail e telefone agora seguem o mesmo padrão de
     * documento/nome: só atualiza de vazio/mascarado pra preenchido.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function resolveBuyerFieldUpdates(Order $existing, array $data): array
    {
        $fields = [];

        if (($existing->buyer_document === null || $existing->buyer_document === '') && ! empty($data['buyer_document'])) {
            $fields['buyer_document'] = $data['buyer_document'];
        }

        $newName = (string) ($data['buyer_name'] ?? '');
        $nameLooksMasked = str_contains($newName, '*');
        $existingNameLooksMasked = str_contains((string) $existing->shipping_name, '*');

        if ($newName !== '' && ! $nameLooksMasked && (empty($existing->shipping_name) || $existingNameLooksMasked)) {
            $fields['shipping_name'] = $newName;
        }

        $newPhone = (string) ($data['buyer_phone'] ?? '');
        $existingPhone = (string) $existing->shipping_phone;
        $existingPhoneMissing = $existingPhone === '' || $existingPhone === 'Não informado' || str_contains($existingPhone, '*');

        if ($newPhone !== '' && ! str_contains($newPhone, '*') && $existingPhoneMissing) {
            $fields['shipping_phone'] = $newPhone;
        }

        $newEmail = (string) ($data['buyer_email'] ?? '');

        if ($newEmail !== '' && empty($existing->shipping_email)) {
            $fields['shipping_email'] = $newEmail;
        }

        return $fields;
    }

    /**
     * Busca ativa (não espera um webhook futuro reprocessar o pedido) —
     * chamada por GenerateInvoiceJob antes de tentar emitir a nota, quando
     * o pedido ainda está sem documento/nome real. Ver
     * resolveBuyerFieldUpdates() acima pro porquê disso acontecer.
     * Silenciosa em qualquer erro (canal fora do ar, driver sem
     * importOrder de verdade implementado etc.) — quem chama já sabe lidar
     * com "documento ainda ausente" normalmente (a validação da
     * NFeXmlBuilderService), isso é só uma tentativa extra de recuperar o
     * dado antes de deixar a emissão falhar.
     */
    public function refreshBuyerInfo(Order $order): void
    {
        if ($order->origin === Order::ORIGIN_STORE) {
            return;
        }

        $needsRefresh = empty($order->buyer_document) || str_contains((string) $order->shipping_name, '*');

        if (! $needsRefresh) {
            return;
        }

        $this->fetchAndApplyBuyerFieldUpdates($order);
    }

    /**
     * Mesma ideia de refreshBuyerInfo(), mas com um gatilho mais amplo —
     * também considera telefone/e-mail ausentes, não só documento/nome.
     * Deliberadamente **não** é chamada no caminho síncrono de emissão de
     * nota (refreshBuyerInfo() continua enxuta pra isso, é o requisito
     * bloqueante de verdade) — usada pelo comando de backfill
     * `orders:refresh-contato` pra tentar completar o cadastro de
     * clientes já existentes sem esperar um webhook futuro reprocessar o
     * pedido.
     *
     * @return bool true se algum campo foi realmente atualizado.
     */
    public function refreshContactInfo(Order $order): bool
    {
        if ($order->origin === Order::ORIGIN_STORE) {
            return false;
        }

        $existingPhone = (string) $order->shipping_phone;
        $phoneMissing = $existingPhone === '' || $existingPhone === 'Não informado' || str_contains($existingPhone, '*');

        $needsRefresh = empty($order->buyer_document)
            || str_contains((string) $order->shipping_name, '*')
            || empty($order->shipping_email)
            || $phoneMissing;

        if (! $needsRefresh) {
            return false;
        }

        return $this->fetchAndApplyBuyerFieldUpdates($order);
    }

    private function fetchAndApplyBuyerFieldUpdates(Order $order): bool
    {
        try {
            $data = $this->manager->driver($order->origin)->importOrder($order->external_order_id);
        } catch (\Throwable $exception) {
            Log::warning('marketplace.order_import.buyer_info_refresh_failed', [
                'order_id' => $order->id,
                'channel' => $order->origin,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $fields = $this->resolveBuyerFieldUpdates($order, $data);

        if ($fields) {
            $order->update($fields);
        }

        return (bool) $fields;
    }

    private function syncStatus(Order $order, string $newStatus): Order
    {
        if ($order->status === $newStatus) {
            return $order;
        }

        if ($this->isStaleStatus($order->status, $newStatus)) {
            Log::info('marketplace.order_import.stale_status_ignored', [
                'order_id' => $order->id,
                'channel' => $order->origin,
                'current_status' => $order->status,
                'ignored_status' => $newStatus,
            ]);

            $this->timeline->record($order, OrderFulfillmentEvent::STEP_WEBHOOK_RECEIVED, OrderFulfillmentEvent::STATUS_SUCCESS, "Status \"{$newStatus}\" do canal ignorado — pedido já estava em \"{$order->status}\", mais avançado (webhook fora de ordem).");

            return $order;
        }

        $wasCancelled = $order->status === Order::STATUS_CANCELLED;
        $wasPaid = $order->status === Order::STATUS_PAID;

        $order->update(['status' => $newStatus]);

        if ($newStatus === Order::STATUS_CANCELLED && ! $wasCancelled) {
            $this->finalizer->restoreStockIfNeeded($order, 'Pedido cancelado no canal de origem');
        }

        if ($newStatus === Order::STATUS_PAID && ! $wasPaid) {
            ConfirmChannelShippingJob::dispatch($order->id);
            GenerateInvoiceJob::dispatch($order->id);
        }

        return $order;
    }
}
