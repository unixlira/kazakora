<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\CartStockChangedException;
use App\Modules\Checkout\Support\CompletarEnderecoPeloCep;
use App\Modules\Checkout\Services\CheckoutOutroSite;
use App\Modules\FerraKora\Services\PrecoMudouException;
use App\Rules\CpfValido;
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Checkout próprio dos outros sites (FerraKora e Beauty Kora, pedidos do Lira
 * 2026-10-14) pelo Mercado Pago da KazaKora. Regras em CheckoutOutroSite;
 * cada site tem a sua subclasse (FerraKoraCheckoutController,
 * BeautyKoraCheckoutController).
 *
 * POST resumo  {itens: [{variante: "kzv-1", quantidade: 1}], cep?, frete? (id da opção), turbo? (antigo), cupom?, email?}
 *              → cupom{codigo, descricao, desconto} ou cupom_erro
 * POST pedido  {itens, frete?, turbo?, cupom?, total_esperado (centavos), cliente{name,email,cpf,phone},
 *               endereco{zip,street,number,complement,neighborhood,city,state},
 *               pagamento{metodo: pix|card|boleto, mp_card_token, mp_card_installments, mp_payment_method_id}}
 *              → pedido, token, pix{copia_e_cola,...} ou boleto{linha_digitavel, codigo_barras, url, vencimento}
 * GET  pedido/{id}?token=  situação do pagamento (o site consulta até pagar)
 * GET  cep/{cep}           rua, bairro, cidade e UF
 */
abstract class CheckoutOutroSiteController extends Controller
{
    public function __construct(protected readonly CheckoutOutroSite $checkout) {}

    /** Depois do pedido criado (Beauty Kora: liga ao "Meus pedidos" do cliente logado). */
    protected function depoisDoPedido(Request $request, Order $order, array $dados): void {}

    public function resumo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            ...$this->regrasDosItens(),
            'cep' => ['nullable', 'string', 'max:9'],
            'turbo' => ['nullable', 'boolean'],
            'frete' => ['nullable', 'string', 'max:40'],
            'cupom' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            return $this->semCache(response()->json($this->checkout->resumo($dados['itens'], $dados['cep'] ?? null, CheckoutOutroSite::freteEscolhido($dados), $dados['cupom'] ?? null, $dados['email'] ?? null)));
        } catch (CartStockChangedException $e) {
            return response()->json(['message' => $e->getMessage(), 'estoque' => true], 409);
        }
    }

    public function pedido(Request $request): JsonResponse
    {
        if (PaymentGateway::active() !== PaymentGateway::MERCADOPAGO || ! app(MercadoPagoPaymentService::class)->isConfigured()) {
            return response()->json(['message' => 'Pagamento indisponível no momento. Tente de novo em alguns minutos.'], 503);
        }

        if (is_array($request->input('cliente')) && is_string($request->input('cliente.name'))) {
            $request->merge(['cliente' => [...$request->input('cliente'), 'name' => trim($request->input('cliente.name'))]]);
        }
        if (is_array($request->input('endereco'))) {
            $request->merge(['endereco' => app(CompletarEnderecoPeloCep::class)->completar($request->input('endereco'))]);
        }

        $cartao = $request->input('pagamento.metodo') === Payment::METHOD_CARD;
        $dados = $request->validate([
            ...$this->regrasDosItens(),
            'turbo' => ['nullable', 'boolean'],
            'frete' => ['nullable', 'string', 'max:40'],
            'cupom' => ['nullable', 'string', 'max:60'],
            'total_esperado' => ['required', 'integer', 'min:1'],
            'cliente.name' => ['required', 'string', 'min:11', 'max:255'],
            'cliente.email' => ['required', 'email', 'max:255'],
            'cliente.cpf' => ['required', 'string', 'max:14', new CpfValido],
            'cliente.phone' => ['required', 'string', 'min:10', 'max:20'],
            'endereco.zip' => ['required', 'string', 'max:9'],
            'endereco.street' => ['required', 'string', 'max:255'],
            'endereco.number' => ['required', 'string', 'max:20'],
            'endereco.complement' => ['nullable', 'string', 'max:255'],
            'endereco.neighborhood' => ['required', 'string', 'max:255'],
            'endereco.city' => ['required', 'string', 'max:255'],
            'endereco.state' => ['required', 'string', 'size:2'],
            'pagamento.metodo' => ['required', 'in:pix,card,boleto'],
            'pagamento.mp_card_token' => [$cartao ? 'required' : 'nullable', 'string'],
            'pagamento.mp_card_installments' => [$cartao ? 'required' : 'nullable', 'integer', 'min:1', 'max:12'],
            'pagamento.mp_payment_method_id' => [$cartao ? 'required' : 'nullable', 'string', 'max:50'],
        ], [
            'cliente.name.required' => 'Digite seu nome completo.',
            'cliente.name.min' => 'Digite seu nome completo (nome e sobrenome).',
            'cliente.cpf.required' => 'Digite seu CPF.',
            'cliente.phone.required' => 'Digite seu celular com DDD.',
            'cliente.phone.min' => 'Digite seu celular com DDD.',
            'endereco.*.required' => 'Informe :attribute.',
            'endereco.state.size' => 'A UF tem 2 letras (ex.: SP).',
            'pagamento.mp_card_token.required' => 'Confira os dados do cartão.',
        ], [
            'cliente.email' => 'e-mail',
            'endereco.zip' => 'o CEP',
            'endereco.street' => 'a rua',
            'endereco.number' => 'o número',
            'endereco.neighborhood' => 'o bairro',
            'endereco.city' => 'a cidade',
            'endereco.state' => 'a UF',
        ]);
        $dados['endereco']['zip'] = preg_replace('/\D/', '', $dados['endereco']['zip']);
        $dados['endereco']['state'] = strtoupper($dados['endereco']['state']);

        try {
            ['order' => $order, 'mp' => $mp] = $this->checkout->criarPedido($dados, $this->localizacaoDoVisitante($request));
        } catch (CartStockChangedException $e) {
            return response()->json(['message' => $e->getMessage(), 'estoque' => true], 409);
        } catch (PrecoMudouException $e) {
            return response()->json(['message' => $e->getMessage(), 'resumo' => $this->checkout->resumo($dados['itens'], $dados['endereco']['zip'], CheckoutOutroSite::freteEscolhido($dados), $dados['cupom'] ?? null, $dados['cliente']['email'])], 409);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['message' => 'Este CPF ou e-mail já tem cadastro na KazaKora. Use os dados da sua conta.'], 422);
        } catch (MercadoPagoException $e) {
            Log::channel('mercadopago')->error('mercadopago.'.$this->checkout->site().'.payment_failed', [
                'metodo' => $dados['pagamento']['metodo'],
                'message' => $e->getMessage(),
                'context' => $e->context(),
            ]);

            if (str_contains(strtolower($e->getMessage()), 'identification')) {
                return response()->json(['message' => 'O Mercado Pago recusou o CPF informado. Confira o CPF e tente de novo.', 'errors' => ['cliente.cpf' => ['Confira o CPF.']]], 422);
            }

            return response()->json(['message' => match ($dados['pagamento']['metodo']) {
                Payment::METHOD_CARD => 'O pagamento no cartão não foi aprovado. Confira os dados, tente outro cartão ou pague no Pix.',
                Payment::METHOD_BOLETO => 'Não foi possível gerar o boleto agora. Tente de novo em instantes ou pague no Pix.',
                default => 'Não foi possível gerar o Pix agora. Tente de novo em instantes.',
            }], 422);
        }

        $order->refresh();
        rescue(fn () => $this->depoisDoPedido($request, $order, $dados));
        $qr = $mp['point_of_interaction']['transaction_data'] ?? [];

        return response()->json([
            ...$this->situacao($order),
            'token' => CheckoutOutroSite::token($order),
            'pix' => $dados['pagamento']['metodo'] === Payment::METHOD_PIX ? [
                'copia_e_cola' => $qr['qr_code'] ?? null,
                'qr_code_base64' => $qr['qr_code_base64'] ?? null,
                'expira_em' => $mp['date_of_expiration'] ?? null,
            ] : null,
            'boleto' => $dados['pagamento']['metodo'] === Payment::METHOD_BOLETO ? $this->boleto($mp) : null,
        ], 201);
    }

    public function status(Request $request, Order $order): JsonResponse
    {
        abort_unless(
            $order->site_origem === $this->checkout->site() && hash_equals(CheckoutOutroSite::token($order), (string) $request->query('token')),
            404,
        );

        if ($order->status === Order::STATUS_AWAITING_PAYMENT) {
            try {
                $order = $this->checkout->atualizarStatus($order->load('payments'));
            } catch (MercadoPagoException $e) {
                Log::channel('mercadopago')->warning('mercadopago.'.$this->checkout->site().'.status_failed', ['order_id' => $order->id, 'message' => $e->getMessage()]);
            }
        }

        return $this->semCache(response()->json($this->situacao($order)));
    }

    /**
     * Meus pedidos (FerraKora, pedido do Lira 2026-10-14): só com login. O
     * cliente entra com a conta da KazaKora (AuthenticateFerraKoraCliente) e
     * vê os pedidos dessa conta feitos neste site, sem endereço nem telefone.
     *
     * GET pedidos (Bearer da sessão)
     */
    public function meusPedidos(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        $pedidos = Order::query()
            ->where('site_origem', $this->checkout->site())
            ->where('user_id', $user->id)
            ->with(['items.product.images', 'items.product.parent.images', 'invoice', 'channelShipment', 'latestCorreiosPrePostagem'])
            ->latest('created_at')->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (Order $order) => $this->resumoDoPedido($order))
            ->values();

        return $this->semCache(response()->json(['pedidos' => $pedidos]));
    }

    public function cep(string $cep): JsonResponse
    {
        $endereco = app(CompletarEnderecoPeloCep::class)->completar(['zip' => $cep]);

        return blank($endereco['city'] ?? null)
            ? response()->json(['message' => 'CEP não encontrado.'], 404)
            : response()->json(collect($endereco)->only(['zip', 'street', 'neighborhood', 'city', 'state']));
    }

    /** Boleto gerado: linha digitável, código de barras, PDF e vencimento. */
    private function boleto(array $mp): array
    {
        $boleto = MercadoPagoPaymentService::dadosDoBoleto($mp);

        return [
            'linha_digitavel' => $boleto['linhaDigitavel'],
            'codigo_barras' => $boleto['codigoBarras'],
            'url' => $boleto['pdfUrl'],
            'vencimento' => $boleto['vencimento'],
        ];
    }

    private function regrasDosItens(): array
    {
        return [
            'itens' => ['required', 'array', 'min:1', 'max:20'],
            'itens.*.variante' => ['required', 'string', 'max:30'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    private function situacao(Order $order): array
    {
        return [
            'pedido' => [
                'id' => $order->id,
                'numero' => $order->numero,
                'total' => CheckoutOutroSite::centavos((float) $order->total),
                'status' => $order->status,
                'pago' => in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true),
                'cancelado' => $order->status === Order::STATUS_CANCELLED,
            ],
        ];
    }

    /** Pedido para o "Meus pedidos" do site: o mesmo que a página de rastreio da loja mostra. */
    private function resumoDoPedido(Order $order): array
    {
        $etapas = $order->etapas();
        $cancelado = $order->status === Order::STATUS_CANCELLED;
        $ultima = collect($etapas)->last(fn (array $etapa) => $etapa['feito']);

        return [
            'id' => $order->id,
            'numero' => $order->numero,
            'criado_em' => $order->created_at?->toIso8601String(),
            'status' => $order->status,
            'situacao' => match (true) {
                $cancelado => 'Cancelado',
                $ultima['titulo'] === 'Pedido recebido' => 'Aguardando pagamento',
                default => $ultima['titulo'],
            },
            'cancelado' => $cancelado,
            'pago' => in_array($order->status, [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED], true),
            'total' => CheckoutOutroSite::centavos((float) $order->total),
            'itens' => $order->items->map(fn ($item) => [
                'nome' => $item->product_name,
                'quantidade' => $item->quantity,
                'imagem' => $item->product?->images->first()?->url ?? $item->product?->parent?->images->first()?->url,
            ])->values(),
            'transportadora' => $order->shipping_carrier_name,
            'codigo_rastreio' => $order->trackingCode(),
            'etapas' => $etapas,
        ];
    }

    /** Igual ao checkout da loja: latitude/longitude do IP pela Cloudflare. */
    private function localizacaoDoVisitante(Request $request): array
    {
        $lat = $request->header('CF-IPLatitude');
        $lng = $request->header('CF-IPLongitude');

        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return ['visitante_latitude' => null, 'visitante_longitude' => null];
        }

        return ['visitante_latitude' => (float) $lat, 'visitante_longitude' => (float) $lng];
    }

    private function semCache(JsonResponse $resposta): JsonResponse
    {
        $resposta->headers->set('Cache-Control', 'private, no-store');

        return $resposta;
    }
}
