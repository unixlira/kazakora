<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Services\CorreiosFreightQuoteService;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Services\Correios\CorreiosPrePostagemService;
use App\Services\Correios\Exceptions\CorreiosException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pré-postagem dos Correios gerada SOZINHA pro pedido — a mesma que o
 * menu Correios faz na mão (CorreiosController), só que disparada pelo
 * pipeline de envio. Pedido explícito 2026-09-25, pros pedidos da Amazon
 * que chegam pelo Bling:
 *
 *   nota fiscal (do Bling ou nossa) autorizada, COM chave
 *   → pré-postagem com a chave da NF-e
 *   → QR Code na etiqueta (CorreiosLabelPdf)
 *   → impressão automática (LabelFetchService, como qualquer canal)
 *
 * Sem nota autorizada com chave, lança — ConfirmChannelShippingJob tenta
 * de novo com backoff, e a autorização da nota já dispara uma tentativa
 * imediata (ver AmazonDriver::submitInvoice()).
 *
 * Idempotente: pedido com pré-postagem GERADA devolve a mesma, nunca cria
 * outra (duas pré-postagens = dois objetos pagos nos Correios). Tentativa
 * que falhou fica UMA linha de erro por pedido, atualizada a cada retry,
 * visível no menu Correios.
 */
class CorreiosAutoShipping
{
    public function __construct(
        private readonly CorreiosPrePostagemService $correios,
        private readonly CorreiosFreightQuoteService $precos,
        private readonly PackageDataResolver $pacotes,
    ) {}

    public function confirm(Order $order): CorreiosPrePostagem
    {
        // Duas tentativas simultâneas (retry do job + disparo da nota) não
        // podem as duas passar do "já existe?" e criar dois objetos.
        return Cache::lock("correios:auto:{$order->id}", 120)->block(30, fn () => $this->confirmarSemConcorrencia($order));
    }

    public function geradaPara(Order $order): ?CorreiosPrePostagem
    {
        return CorreiosPrePostagem::query()
            ->where('order_id', $order->id)
            ->where('status', CorreiosPrePostagem::STATUS_GERADA)
            ->latest('id')
            ->first();
    }

    private function confirmarSemConcorrencia(Order $order): CorreiosPrePostagem
    {
        if ($gerada = $this->geradaPara($order)) {
            return $gerada;
        }

        $invoice = $order->invoice()->first();

        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED || ! $invoice->chave_acesso) {
            throw new RuntimeException("Pedido #{$order->id}: NF-e ainda não autorizada com chave — a pré-postagem dos Correios espera por ela.");
        }

        $pacote = $this->pacotes->forOrder($order);
        [$servico, $preco] = $this->servicoMaisBarato($order, $pacote);

        $input = [
            'customer' => [
                'name' => Str::limit((string) $order->shipping_name, 50, ''),
                'document' => $order->buyer_document,
                'phone' => $order->shipping_phone,
                'email' => $order->shipping_email,
            ],
            'address' => [
                'zip' => $order->shipping_zip,
                'street' => Str::limit((string) $order->shipping_street, 50, ''),
                'number' => Str::limit((string) ($order->shipping_number ?: 'S/N'), 6, ''),
                'complement' => $order->shipping_complement ? Str::limit($order->shipping_complement, 30, '') : null,
                'neighborhood' => Str::limit((string) $order->shipping_neighborhood, 30, ''),
                'city' => Str::limit((string) $order->shipping_city, 30, ''),
                'state' => strtoupper((string) $order->shipping_state),
            ],
            'service_code' => $servico,
            'weight_grams' => $pacote['weight_grams'],
            'dimensions' => [
                'format' => $pacote['format'],
                'height' => $pacote['height'],
                'width' => $pacote['width'],
                'length' => $pacote['length'],
            ],
            'content_items' => $order->items->map(fn ($item) => [
                'conteudo' => $item->product_name,
                'quantidade' => $item->quantity,
                'valor' => (float) $item->product_price,
            ])->all(),
            'invoice' => ['numero' => $invoice->numero, 'chave' => $invoice->chave_acesso],
        ];

        $registro = CorreiosPrePostagem::query()
            ->where('order_id', $order->id)
            ->where('status', CorreiosPrePostagem::STATUS_ERRO)
            ->latest('id')
            ->first() ?? new CorreiosPrePostagem;

        $registro->fill([
            'order_id' => $order->id,
            'origin' => $order->origin,
            'external_order_id' => $order->external_order_id,
            'customer_name' => $input['customer']['name'],
            'customer_document' => $input['customer']['document'],
            'customer_phone' => $input['customer']['phone'],
            'customer_email' => $input['customer']['email'],
            ...collect($input['address'])->only(['zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state'])->all(),
            'service_code' => $servico,
            'service_label' => CorreiosPrePostagemService::SERVICOS_CONTRATO[$servico],
            'postage_price' => $preco,
            'weight_grams' => $pacote['weight_grams'],
            'dimension_format' => $pacote['format'],
            'dimension_height' => $pacote['height'],
            'dimension_width' => $pacote['width'],
            'dimension_length' => $pacote['length'],
            'content_items' => $input['content_items'],
        ]);

        try {
            $resultado = $this->correios->create($input);
        } catch (CorreiosException $exception) {
            $registro->status = CorreiosPrePostagem::STATUS_ERRO;
            $registro->error_message = $exception->getMessage();
            $registro->save();

            throw $exception;
        }

        $registro->status = CorreiosPrePostagem::STATUS_GERADA;
        $registro->correios_id = (string) ($resultado['id'] ?? '');
        $registro->codigo_objeto = $resultado['codigoObjeto'] ?? null;
        $registro->qr_payload = $registro->codigo_objeto ?: $registro->correios_id;
        $registro->raw_response = $resultado;
        $registro->error_message = null;
        $registro->save();

        return $registro;
    }

    /**
     * Cota PAC e SEDEX e fica com o menor (decisão do usuário
     * 2026-09-25). Cotação que falha não trava: sem preço nenhum, PAC.
     *
     * @param  array{format: string, weight_grams: int, height: ?float, width: ?float, length: ?float}  $pacote
     * @return array{0: string, 1: ?float}
     */
    private function servicoMaisBarato(Order $order, array $pacote): array
    {
        $cotacoes = collect(array_keys(CorreiosPrePostagemService::SERVICOS_CONTRATO))
            ->mapWithKeys(fn (string $servico) => [$servico => $this->precos->priceFor(
                $servico,
                (string) $order->shipping_zip,
                $pacote['weight_grams'],
                $pacote['format'],
                $pacote['height'],
                $pacote['width'],
                $pacote['length'],
                null,
            )])
            ->filter(fn ($preco) => $preco !== null)
            ->sort();

        if ($cotacoes->isEmpty()) {
            return [CorreiosPrePostagemService::SERVICO_PAC, null];
        }

        return [(string) $cotacoes->keys()->first(), (float) $cotacoes->first()];
    }
}
