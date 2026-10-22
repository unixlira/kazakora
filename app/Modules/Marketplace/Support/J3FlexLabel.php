<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Support\EntregaExpressa;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Correios\CorreiosPrePostagemService;
use App\Services\J3\J3Client;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Etiqueta da J3 nas vendas Flex — pedido do Lira em 2026-10-21: "se a
 * venda for Flex (dentro do perímetro), usar a API da J3 pra emitir a
 * etiqueta própria deles; fora do perímetro, continua a etiqueta padrão".
 *
 * Flex = Mercado Livre `self_service`. O perímetro é a área atendida pela
 * própria J3 (GET /api/regions/coverage/{cep}), então quem decide se o CEP
 * está dentro é ela, não uma lista nossa.
 *
 * Nunca trava o pedido: J3 desligada, CEP fora, dado de pacote faltando ou
 * a API fora do ar devolvem null e o LabelFetchService segue com a
 * etiqueta do Mercado Livre de sempre. O motivo fica no log.
 *
 * Sites (KazaKora, FerraKora, Beauty Kora), pedido do Lira em 2026-10-21:
 * frete grátis dentro do perímetro da Entrega Expressa (EntregaExpressa) e
 * da área da J3 vira pedido avulso na J3 (sem `ecommerce`), que é o único
 * que tem etiqueta própria dela. Fora disso, Correios como sempre. Ver
 * LojaShippingDriver.
 */
class J3FlexLabel
{
    public function __construct(
        private readonly J3Client $j3,
        private readonly PackageDataResolver $pacotes,
    ) {}

    public function seAplica(ChannelShipment $shipment): bool
    {
        return (bool) config('services.j3.enabled')
            && $this->j3->isConfigured()
            && $shipment->channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE
            && $shipment->shipping_method === ChannelShipment::METHOD_FLEX
            && filled($shipment->external_shipment_id);
    }

    /**
     * Pedido do site que sai pela J3: chave J3_SITE_ENABLED, frete grátis
     * (quem pagou SEDEX ou outra transportadora leva o que pagou), CEP no
     * perímetro da Entrega Expressa e dentro da área da J3. A consulta de
     * cobertura fora do ar conta como "não": o pedido segue pelos Correios.
     */
    public function atendeSite(Order $order): bool
    {
        if (! config('services.j3.site') || ! $this->j3->isConfigured()
            || $order->origin !== Order::ORIGIN_STORE
            || (float) $order->shipping_cost > 0
            || ! app(EntregaExpressa::class)->atende($order->shipping_zip)) {
            return false;
        }

        try {
            return $this->j3->cepAtendido((string) $order->shipping_zip);
        } catch (Throwable $exception) {
            Log::warning('j3.site.cobertura_falhou', ['order_id' => $order->id, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Pedido avulso na J3 para o pedido do site. Idempotente pelo número do
     * pedido (shippingIntegrationId): retry do job reaproveita o que já foi
     * criado, nunca cria um segundo (cada um é cobrado). Erro lança para o
     * ConfirmChannelShippingJob tentar de novo, sem cair nos Correios: o
     * POST pode ter passado e virariam dois envios pagos.
     *
     * @return array{tracking: string, order_code: ?string}
     */
    public function criarPedidoDoSite(Order $order): array
    {
        return Cache::lock("j3:site:{$order->id}", 120)->block(30, function () use ($order) {
            $invoice = $order->invoice()->first();

            if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED || ! $invoice->numero) {
                throw new RuntimeException("Pedido #{$order->id}: NF-e ainda não autorizada — o pedido na J3 espera por ela.");
            }

            $integracao = $this->integracaoDoSite($order);
            $resposta = $this->j3->pedidoPorEnvio($integracao)
                ?: $this->j3->criarPedido($this->montar($order, $integracao, null));

            if (blank($resposta['trackingNumber'] ?? null)) {
                throw new RuntimeException("J3 não devolveu rastreio para o pedido #{$order->id}.");
            }

            Log::info('j3.site.pedido', ['order_id' => $order->id, 'tracking' => $resposta['trackingNumber']]);

            return ['tracking' => (string) $resposta['trackingNumber'], 'order_code' => $resposta['orderCode'] ?? null];
        });
    }

    /** PDF da etiqueta da J3 de um rastreio já criado. */
    public function etiquetaPorRastreio(string $tracking): string
    {
        return $this->j3->baixarEtiqueta($this->j3->urlDaEtiqueta($tracking));
    }

    /** Número que casa o pedido do site com o da J3 (o mesmo que o cliente vê). */
    public function integracaoDoSite(Order $order): string
    {
        return (string) ($order->external_order_id ?: "KZ-{$order->id}");
    }

    /** PDF da etiqueta da J3, ou null pra usar a etiqueta padrão. */
    public function emitir(ChannelShipment $shipment): ?string
    {
        if (! $this->seAplica($shipment)) {
            return null;
        }

        $order = $shipment->order;

        try {
            $tracking = $shipment->j3_tracking_number;
            $url = null;

            if (! $tracking) {
                if (! $this->j3->cepAtendido((string) $order->shipping_zip)) {
                    Log::info('j3.flex.fora_do_perimetro', ['shipment_id' => $shipment->id, 'cep' => $order->shipping_zip]);

                    return null;
                }

                $existente = $this->j3->pedidoPorEnvio($shipment->external_shipment_id);

                // A J3 já importa sozinha toda venda Flex do Mercado Livre
                // (origin Integration, shippingIntegrationId = id do envio),
                // mas não dá pra saber quando: a data que ela grava é a da
                // venda. Criar pelo POST quando ela ainda não importou abria
                // a chance de dois pedidos (e duas cobranças) pro mesmo
                // envio — conferido em 2026-10-21. Venda do ML só usa o
                // pedido que a J3 já tem; sem ele, etiqueta do ML.
                if (! $existente && $shipment->channel === MarketplaceAccount::CHANNEL_MERCADO_LIVRE) {
                    Log::info('j3.flex.ainda_nao_importado', ['shipment_id' => $shipment->id, 'envio' => $shipment->external_shipment_id]);

                    return null;
                }

                $resposta = $existente ?: $this->j3->criarPedido($this->montarPedido($order, $shipment));

                $tracking = $resposta['trackingNumber'] ?? null;
                $url = $resposta['stampUrl'] ?? null;

                if (blank($tracking) && blank($url)) {
                    Log::warning('j3.flex.sem_rastreio', ['shipment_id' => $shipment->id, 'resposta' => $resposta]);

                    return null;
                }

                $shipment->forceFill([
                    'j3_tracking_number' => $tracking,
                    'j3_order_code' => $resposta['orderCode'] ?? null,
                ])->save();
            }

            $pdf = $this->j3->baixarEtiqueta($url ?: $this->j3->urlDaEtiqueta($tracking));

            Log::info('j3.flex.etiqueta', ['shipment_id' => $shipment->id, 'tracking' => $tracking]);

            return $pdf;
        } catch (Throwable $exception) {
            Log::warning('j3.flex.falhou_usando_padrao', [
                'shipment_id' => $shipment->id,
                'order_id' => $shipment->order_id,
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * CreateIntegrationOrderDto. shippingIntegrationId = id do envio no
     * Mercado Livre (o mesmo do QR da etiqueta Flex), que é como a J3 casa
     * o pedido com a venda do ML.
     *
     * @return array<string, mixed>
     */
    public function montarPedido(Order $order, ChannelShipment $shipment): array
    {
        return $this->montar($order, (string) $shipment->external_shipment_id, 'MercadoLivre');
    }

    /**
     * Sem $ecommerce = pedido avulso (site), o que tem etiqueta da J3.
     *
     * @return array<string, mixed>
     */
    private function montar(Order $order, string $integracao, ?string $ecommerce): array
    {
        $order->loadMissing('items');
        $pacote = $this->pacotes->forOrder($order, sempreComMedidas: true);

        $endereco = array_filter([
            'fullAddress' => $this->enderecoCompleto($order),
            'addressComplement' => $order->shipping_complement ?: null,
            'addressZipCode' => $order->shipping_zip,
            'addressStreet' => $order->shipping_street,
            'addressNumber' => $order->shipping_number ?: 'S/N',
            'addressDistrict' => $order->shipping_neighborhood,
            'addressCity' => $order->shipping_city,
            'addressState' => strtoupper((string) $order->shipping_state),
            'contactName' => $order->shipping_recipient_name ?: $order->shipping_name,
            'contactPhoneNumber' => $order->shipping_phone,
            'isResidentialAddress' => true,
        ], fn ($valor) => $valor !== null && $valor !== '');

        $dados = array_filter([
            'shippingIntegrationId' => $integracao,
            'date' => $order->created_at?->timezone('America/Sao_Paulo')->toDateString(),
            'nf' => $this->numeroDaNota($order),
            'notes' => 'Pedido '.Order::rotuloDoCanal($order->origin, $order->site_origem)." #{$order->id}",
            'shippingAddress' => $endereco,
            'packages' => [[
                'type' => $pacote['format'] === CorreiosPrePostagemService::FORMATO_ENVELOPE ? 'envelope' : 'box',
                'quantity' => 1,
                'description' => Str::limit($order->items->pluck('product_name')->implode(', '), 100, ''),
                'weightInGrams' => $pacote['weight_grams'],
                'widthInCentimeters' => $pacote['width'],
                'lengthInCentimeters' => $pacote['length'],
                'heightInCentimeters' => $pacote['height'],
                'itemValueInCents' => (int) round((float) $order->subtotal * 100),
            ]],
        ], fn ($valor) => $valor !== null && $valor !== '');

        return array_filter([
            'sellerCnpj' => config('services.j3.seller_cnpj') ?: null,
            'ecommerce' => $ecommerce,
            'order' => $dados,
        ]);
    }

    private function enderecoCompleto(Order $order): string
    {
        $rua = trim(implode(', ', array_filter([$order->shipping_street, $order->shipping_number ?: 'S/N', $order->shipping_neighborhood])));
        $cidade = trim(implode(' - ', array_filter([$order->shipping_city, strtoupper((string) $order->shipping_state)])));

        return trim(implode(', ', array_filter([$rua, $cidade])));
    }

    private function numeroDaNota(Order $order): ?string
    {
        $invoice = $order->invoice()->first();

        return $invoice && $invoice->status === Invoice::STATUS_AUTHORIZED && $invoice->numero
            ? (string) $invoice->numero
            : null;
    }
}
