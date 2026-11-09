<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Company;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MelhorEnvioEnvio;
use App\Services\MelhorEnvio\CotacaoMelhorEnvio;
use App\Services\MelhorEnvio\Exceptions\MelhorEnvioException;
use App\Services\MelhorEnvio\MelhorEnvioClient;
use App\Support\FreteProvedor;
use App\Support\SiteAtual;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Etiqueta pelo Melhor Envio dos pedidos do site da KazaKora quando o
 * Melhor Envio é o provedor conectado em Logística (pedido do Lira em
 * 2026-11-03: "tudo", preço e etiqueta). Mesmo lugar e mesmas travas da
 * pré-postagem dos Correios (CorreiosAutoShipping):
 *
 *   NF-e autorizada com chave → cota o pacote → carrinho (me/cart) → compra
 *   com o saldo da carteira (me/shipment/checkout) → gera (me/shipment/generate)
 *   → PDF (me/imprimir/pdf/{id}) para a fila do KoraSync.
 *
 * Serviço: o mais barato; com TURBO pago pelo cliente, o mais rápido. Fica
 * escolhido na hora do pedido (escolherNoPedido) e a etiqueta usa o mesmo.
 *
 * Idempotente: cada passo fica gravado em melhor_envio_envios e a próxima
 * tentativa continua de onde parou (carrinho já criado não cria outro,
 * frete já pago não paga de novo). Falha (saldo insuficiente, API fora)
 * lança: ConfirmChannelShippingJob tenta de novo.
 */
class MelhorEnvioAutoShipping
{
    public function __construct(
        private readonly MelhorEnvioClient $client,
        private readonly CotacaoMelhorEnvio $cotacao,
        private readonly PackageDataResolver $pacotes,
    ) {}

    public function confirm(Order $order): MelhorEnvioEnvio
    {
        return Cache::lock("melhorenvio:auto:{$order->id}", 120)->block(30, fn () => $this->confirmarSemConcorrencia($order));
    }

    public function doPedido(Order $order): ?MelhorEnvioEnvio
    {
        return MelhorEnvioEnvio::query()->where('order_id', $order->id)->first();
    }

    public function geradaPara(Order $order): ?MelhorEnvioEnvio
    {
        return MelhorEnvioEnvio::query()->where('order_id', $order->id)->where('status', MelhorEnvioEnvio::STATUS_GERADA)->first();
    }

    private function confirmarSemConcorrencia(Order $order): MelhorEnvioEnvio
    {
        $envio = $this->doPedido($order) ?? new MelhorEnvioEnvio(['order_id' => $order->id, 'status' => MelhorEnvioEnvio::STATUS_ERRO]);

        if ($envio->status === MelhorEnvioEnvio::STATUS_GERADA) {
            return $this->comRastreio($envio);
        }

        try {
            if (! $envio->me_order_id) {
                $this->criarCarrinho($order, $envio);
            }
            if (! $envio->paga_em) {
                $this->comprar($envio);
            }
            $this->gerar($envio);
            // Onde levar o pacote, para o card do KoraSync (2026-11-09).
            app(PontoDePostagemMelhorEnvio::class)->gravar($envio);
        } catch (Throwable $e) {
            $envio->erro = Str::limit($e->getMessage(), 500, '');
            if ($envio->status !== MelhorEnvioEnvio::STATUS_PAGA && $envio->status !== MelhorEnvioEnvio::STATUS_CARRINHO) {
                $envio->status = MelhorEnvioEnvio::STATUS_ERRO;
            }
            $envio->save();
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.etiqueta_falhou', ['order_id' => $order->id, 'passo' => $envio->status, 'message' => $e->getMessage()]);

            throw $e;
        }

        return $this->comRastreio($envio);
    }

    /**
     * Grava no pedido a transportadora que vai levar a caixa, no momento da
     * compra (CotarFreteDoPedidoJob): a mais barata para o CEP e o pacote
     * do pedido, ou a mais rápida com TURBO. Só com o Melhor Envio
     * conectado e uma vez por pedido; a etiqueta depois sai com ela.
     */
    public function escolherNoPedido(Order $order): ?MelhorEnvioEnvio
    {
        if (FreteProvedor::doPedido($order->site_origem) !== FreteProvedor::MELHOR_ENVIO || $this->doPedido($order)) {
            return null;
        }

        ['pacote' => $pacote, 'volume' => $volume, 'opcoes' => $opcoes] = $this->cotarPedido($order, $this->empresa());
        $servico = $this->melhorOpcao($order, $opcoes);

        return MelhorEnvioEnvio::query()->firstOrCreate(['order_id' => $order->id], [
            'status' => MelhorEnvioEnvio::STATUS_COTADA,
            'servico_id' => $servico['servico_id'],
            'servico' => Str::limit($servico['nome'], 80, ''),
            'preco' => $servico['preco'],
            'prazo_dias' => $servico['prazo'] ?? null,
            'peso_g' => (int) $pacote['weight_grams'],
            'volume' => $volume,
        ]);
    }

    private function empresa(): Company
    {
        $empresa = Company::query()->first();
        if (! $empresa || ! $empresa->zip) {
            throw new RuntimeException('Cadastre o endereço da empresa em Configurações › Empresa.');
        }

        return $empresa;
    }

    /**
     * Pacote do pedido e as transportadoras que o Melhor Envio cota para ele.
     *
     * @return array{pacote: array, volume: array, valor: float, opcoes: list<array>}
     */
    private function cotarPedido(Order $order, Company $empresa): array
    {
        $pacote = $this->pacotes->forOrder($order, sempreComMedidas: true);
        $volume = [
            'height' => (int) ceil(max(2, (float) ($pacote['height'] ?? 2))),
            'width' => (int) ceil(max(11, (float) ($pacote['width'] ?? 11))),
            'length' => (int) ceil(max(16, (float) ($pacote['length'] ?? 16))),
            'weight' => round(max(1, (int) $pacote['weight_grams']) / 1000, 3),
        ];
        $valor = round((float) $order->items->sum(fn ($i) => (float) $i->product_price * (int) $i->quantity), 2);

        $opcoes = $this->cotacao->cotarUm([
            'origem' => self::digitos($empresa->zip), 'cep' => self::digitos($order->shipping_zip),
            'peso_g' => (int) $pacote['weight_grams'], 'altura' => $volume['height'], 'largura' => $volume['width'], 'comprimento' => $volume['length'],
            'valor' => $valor,
        ]);
        if (! $opcoes) {
            throw new RuntimeException("Pedido #{$order->id}: o Melhor Envio não cotou nenhuma transportadora para o CEP {$order->shipping_zip}.");
        }

        return ['pacote' => $pacote, 'volume' => $volume, 'valor' => $valor, 'opcoes' => $opcoes];
    }

    /** Qualquer transportadora: a mais barata; com TURBO pago, CotacaoMelhorEnvio::turbo() (até 1 dia da mais rápida, a mais barata). */
    private function melhorOpcao(Order $order, array $opcoes): array
    {
        $turbo = str_contains(mb_strtolower((string) $order->shipping_carrier_name), 'turbo');

        return $turbo ? CotacaoMelhorEnvio::turbo($opcoes) : CotacaoMelhorEnvio::maisBarata($opcoes);
    }

    private function criarCarrinho(Order $order, MelhorEnvioEnvio $envio): void
    {
        $invoice = $order->invoice()->first();
        if (! $invoice || $invoice->status !== Invoice::STATUS_AUTHORIZED || ! $invoice->chave_acesso) {
            throw new RuntimeException("Pedido #{$order->id}: NF-e ainda não autorizada com chave, a etiqueta do Melhor Envio espera por ela.");
        }

        $empresa = $this->empresa();
        ['pacote' => $pacote, 'volume' => $volume, 'valor' => $valor, 'opcoes' => $opcoes] = $this->cotarPedido($order, $empresa);

        // A transportadora escolhida no pedido (escolherNoPedido) manda; se
        // ela não atende mais o CEP, vai a melhor da cotação de agora.
        $servico = $envio->servico_id
            ? collect($opcoes)->firstWhere('servico_id', (int) $envio->servico_id)
            : null;
        if ($envio->servico_id && ! $servico) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.servico_do_pedido_indisponivel', ['order_id' => $order->id, 'servico_id' => $envio->servico_id, 'servico' => $envio->servico]);
        }
        $servico ??= $this->melhorOpcao($order, $opcoes);

        $carrinho = $this->client->post('me/cart', [
            'service' => $servico['servico_id'],
            'from' => [
                // Remetente "KazaKora" na etiqueta (pedido do Lira 09/11, #3978):
                // o nome fantasia do cadastro é o do dono e vai na NF-e.
                'name' => Str::limit((string) ($order->site_origem === SiteAtual::FERRAKORA ? 'FerraKora' : (config('melhorenvio.remetente') ?: $empresa->nome_fantasia ?: $empresa->razao_social)), 60, ''),
                'phone' => self::digitos($empresa->phone),
                'email' => $empresa->email,
                'document' => '',
                'company_document' => self::digitos($empresa->cnpj),
                'state_register' => self::digitos($empresa->inscricao_estadual) ?: 'ISENTO',
                'address' => $empresa->street,
                'number' => $empresa->number ?: 'S/N',
                'complement' => $empresa->complement,
                'district' => $empresa->neighborhood,
                'city' => $empresa->city,
                'state_abbr' => strtoupper((string) $empresa->state),
                'country_id' => 'BR',
                'postal_code' => self::digitos($empresa->zip),
            ],
            'to' => [
                'name' => Str::limit((string) $order->shipping_name, 60, ''),
                'phone' => self::digitos($order->shipping_phone),
                'email' => $order->shipping_email ?: $order->user?->email,
                'document' => self::digitos($order->buyer_document ?: $order->user?->cpf),
                'address' => $order->shipping_street,
                'number' => $order->shipping_number ?: 'S/N',
                'complement' => $order->shipping_complement,
                'district' => $order->shipping_neighborhood,
                'city' => $order->shipping_city,
                'state_abbr' => strtoupper((string) $order->shipping_state),
                'country_id' => 'BR',
                'postal_code' => self::digitos($order->shipping_zip),
            ],
            'products' => $order->items->map(fn ($i) => [
                'name' => Str::limit((string) $i->product_name, 100, ''),
                'quantity' => (string) (int) $i->quantity,
                'unitary_value' => number_format((float) $i->product_price, 2, '.', ''),
            ])->values()->all(),
            'volumes' => [$volume],
            'options' => [
                'insurance_value' => $valor,
                'receipt' => false,
                'own_hand' => false,
                'reverse' => false,
                'non_commercial' => false,
                'invoice' => ['key' => $invoice->chave_acesso],
                'platform' => 'KazaKora',
                'tags' => [['tag' => "Pedido #{$order->id}", 'url' => null]],
            ],
        ]);

        if (empty($carrinho['id'])) {
            throw new MelhorEnvioException("Pedido #{$order->id}: o Melhor Envio não devolveu o id da etiqueta.");
        }

        $envio->fill([
            'status' => MelhorEnvioEnvio::STATUS_CARRINHO,
            'me_order_id' => (string) $carrinho['id'],
            'protocolo' => $carrinho['protocol'] ?? null,
            'servico_id' => $servico['servico_id'],
            'servico' => Str::limit($servico['nome'], 80, ''),
            'preco' => $carrinho['price'] ?? $servico['preco'],
            'prazo_dias' => $servico['prazo'] ?? $envio->prazo_dias,
            'peso_g' => (int) $pacote['weight_grams'],
            'volume' => $volume,
            'erro' => null,
            'resposta' => $carrinho,
        ])->save();
    }

    private function comprar(MelhorEnvioEnvio $envio): void
    {
        try {
            $compra = $this->client->post('me/shipment/checkout', ['orders' => [$envio->me_order_id]]);
        } catch (MelhorEnvioException $e) {
            // Tentativa anterior pagou e caiu antes de gravar: segue.
            if (! str_contains(mb_strtolower($e->getMessage()), 'já foram pagas')) {
                throw new RuntimeException('Compra da etiqueta no Melhor Envio recusada (confira o saldo da carteira): '.$e->getMessage(), 0, $e);
            }
            $compra = null;
        }

        $envio->fill([
            'status' => MelhorEnvioEnvio::STATUS_PAGA,
            'paga_em' => now(),
            'preco' => $compra['purchase']['total'] ?? $envio->preco,
            'erro' => null,
        ])->save();
    }

    private function gerar(MelhorEnvioEnvio $envio): void
    {
        $resposta = $this->client->post('me/shipment/generate', ['orders' => [$envio->me_order_id]]);
        $item = $resposta[$envio->me_order_id] ?? null;

        if (is_array($item) && array_key_exists('status', $item) && ! $item['status']) {
            throw new MelhorEnvioException('Melhor Envio não gerou a etiqueta: '.($item['message'] ?? 'sem mensagem'));
        }

        $envio->fill(['status' => MelhorEnvioEnvio::STATUS_GERADA, 'gerada_em' => now(), 'erro' => null])->save();
    }

    /** Código de rastreio da transportadora (pode demorar a sair depois de gerar). */
    private function comRastreio(MelhorEnvioEnvio $envio): MelhorEnvioEnvio
    {
        if ($envio->rastreio || ! $envio->me_order_id) {
            return $envio;
        }

        try {
            $info = $this->client->post('me/shipment/tracking', ['orders' => [$envio->me_order_id]])[$envio->me_order_id] ?? [];
            $rastreio = $info['tracking'] ?? $info['melhorenvio_tracking'] ?? null;
            if ($rastreio) {
                $envio->forceFill(['rastreio' => $rastreio])->save();
            }
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->info('melhorenvio.rastreio_indisponivel', ['envio' => $envio->id, 'message' => $e->getMessage()]);
        }

        return $envio;
    }

    /**
     * PDF da etiqueta gerada (null = ainda não disponível). A API devolve a
     * URL do arquivo; só PDF de verdade vai para a impressora.
     */
    public function pdf(MelhorEnvioEnvio $envio): ?string
    {
        if ($envio->status !== MelhorEnvioEnvio::STATUS_GERADA || ! $envio->me_order_id) {
            return null;
        }

        try {
            $url = $this->client->get("me/imprimir/pdf/{$envio->me_order_id}");
            $url = is_array($url) ? ($url['url'] ?? $url[0] ?? null) : $url;
            if (! is_string($url) || ! str_starts_with($url, 'http')) {
                return null;
            }

            $arquivo = Http::timeout(30)->get($url);
            if ($arquivo->successful() && str_starts_with($arquivo->body(), '%PDF')) {
                return $arquivo->body();
            }

            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.etiqueta_nao_e_pdf', ['envio' => $envio->id, 'status' => $arquivo->status()]);
        } catch (Throwable $e) {
            Log::channel(config('melhorenvio.log_channel'))->warning('melhorenvio.etiqueta_pdf_falhou', ['envio' => $envio->id, 'message' => $e->getMessage()]);
        }

        return null;
    }

    private static function digitos(mixed $valor): string
    {
        return preg_replace('/\D/', '', (string) $valor) ?? '';
    }
}
