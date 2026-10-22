<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Modules\Marketplace\Support\CorreiosAutoShipping;
use App\Modules\Marketplace\Support\CorreiosLabelPdf;
use App\Modules\Marketplace\Support\J3FlexLabel;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Envio dos pedidos do SITE (origin=loja): a mesma pré-postagem automática
 * dos Correios da Amazon (CorreiosAutoShipping), com a chave da NF-e.
 *
 * ACHADO REAL 2026-10-17 (#3205, primeiro pedido pago do site): a nota saiu,
 * mas nada gerava etiqueta — não havia envio nem PrintJob, e o KoraSync
 * ficava esperando pra sempre. Agora a nota autorizada dispara
 * ConfirmChannelShippingJob (ver GenerateInvoiceJob) e o pedido segue o
 * caminho de qualquer canal: ChannelShipment → CheckShipmentLabelJob →
 * LabelFetchService → fila do KoraSync.
 *
 * J3 (2026-10-21): frete grátis dentro do perímetro da Entrega Expressa e
 * da área da J3 sai pela J3, com a etiqueta dela, em vez dos Correios —
 * vale para KazaKora, FerraKora e Beauty Kora (todos origin=loja). Ver
 * J3FlexLabel::atendeSite().
 *
 * Só ENVIO: o site não é marketplace. Fica fora de
 * MarketplaceDriverManager::channels() (publicar produto, importar pedido,
 * avaliações), e o resto do contrato lança.
 */
class LojaShippingDriver implements MarketplaceChannelDriver
{
    public const METODO_J3 = 'J3 Flex';

    public function channel(): string
    {
        return Order::ORIGIN_STORE;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function confirmShipping(Order $order): array
    {
        $j3 = app(J3FlexLabel::class);

        // Já foi pra J3 numa tentativa anterior: continua nela, nunca abre
        // pré-postagem nos Correios por cima (dois envios pagos).
        $jaNaJ3 = ChannelShipment::query()->where('order_id', $order->id)->whereNotNull('j3_tracking_number')->exists();

        if ($jaNaJ3 || (! app(CorreiosAutoShipping::class)->geradaPara($order) && $j3->atendeSite($order))) {
            $pedido = $j3->criarPedidoDoSite($order);

            ChannelShipment::query()->where('order_id', $order->id)->where('channel', Order::ORIGIN_STORE)->update([
                'j3_tracking_number' => $pedido['tracking'],
                'j3_order_code' => $pedido['order_code'],
            ]);

            return [
                'external_shipment_id' => $j3->integracaoDoSite($order),
                'tracking_code' => $pedido['tracking'],
                'shipping_method' => self::METODO_J3,
                'status' => 'confirmed',
            ];
        }

        $prePostagem = app(CorreiosAutoShipping::class)->confirm($order);

        return [
            'external_shipment_id' => $prePostagem->correios_id,
            'tracking_code' => $prePostagem->codigo_objeto,
            'shipping_method' => 'Correios '.$prePostagem->service_label,
            'status' => 'confirmed',
        ];
    }

    public function fetchLabel(Order $order): array
    {
        $j3Tracking = ChannelShipment::query()->where('order_id', $order->id)->where('channel', Order::ORIGIN_STORE)->value('j3_tracking_number');

        if ($j3Tracking) {
            return ['ready' => true, 'contents' => app(J3FlexLabel::class)->etiquetaPorRastreio($j3Tracking), 'content_type' => 'application/pdf'];
        }

        $correios = app(CorreiosAutoShipping::class);
        $prePostagem = $correios->geradaPara($order);

        // Etiqueta que não descreve mais o pacote não vai pra impressora.
        if ($prePostagem && ($problema = $correios->problemaDaEtiqueta($order, $prePostagem))) {
            Log::warning('correios.etiqueta_bloqueada', ['order_id' => $order->id, 'motivo' => $problema]);

            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        return $prePostagem
            ? ['ready' => true, 'contents' => app(CorreiosLabelPdf::class)->render($prePostagem), 'content_type' => 'application/pdf']
            : ['ready' => false, 'contents' => null, 'content_type' => null];
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        throw $this->naoSeAplica('publicar produto');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        throw $this->naoSeAplica('atualizar estoque');
    }

    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        return null;
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        throw $this->naoSeAplica('despublicar produto');
    }

    public function importOrder(string $externalOrderId): array
    {
        throw $this->naoSeAplica('importar pedido');
    }

    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        throw $this->naoSeAplica('enviar nota ao canal');
    }

    public function fetchReviews(string $externalId): array
    {
        throw $this->naoSeAplica('buscar avaliações');
    }

    public function replyReview(string $externalId, string $comment): array
    {
        throw $this->naoSeAplica('responder avaliação');
    }

    private function naoSeAplica(string $acao): RuntimeException
    {
        return new RuntimeException("Pedido do site não tem canal externo: {$acao} não se aplica.");
    }
}
