<?php

namespace App\Modules\FerraKora\Services;

use App\Models\User;
use App\Modules\Auth\Mail\WelcomeEmail;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Mail\PixAguardandoPagamento;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\CartStockChangedException;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Modules\Checkout\Support\OrderPaymentFinalizer;
use App\Modules\FerraKora\Models\FerraKoraProduto;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Support\StockManager;
use App\Modules\Operacional\Models\ShippingMethod;
use App\Services\MercadoPago\CredenciaisCheckout;
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\DescontoPix;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Checkout próprio do FerraKora (pedido do Lira 2026-10-14): o site manda os
 * itens, o cliente, o endereço e a forma de pagamento; aqui saem o pedido e a
 * cobrança no Mercado Pago, com as mesmas contas do checkout da KazaKora
 * (CheckoutController::storePayment): preço com o frete embutido do CEP de
 * entrega, frete grátis, desconto da caixa única e desconto no Pix.
 *
 * O pedido é da KazaKora (origin "loja", nota, expedição e margem iguais) e
 * leva site_origem = ferrakora. Sem sessão nem login: o site acompanha o
 * pagamento pelo token devolvido na criação (token()).
 */
class CheckoutFerraKora
{
    public function __construct(
        private readonly MercadoPagoPaymentService $mercadoPago,
        private readonly StockManager $stock,
        private readonly OrderPaymentFinalizer $finalizer,
    ) {}

    /**
     * Itens com o preço do CEP de entrega (ou da região do visitante, sem CEP).
     * Só variações de produto publicado no FerraKora.
     *
     * @param  array<int, array{variante: string, quantidade: int}>  $pedidos
     * @return Collection<int, array{product: Product, quantity: int, subtotal: float}>
     */
    public function itens(array $pedidos, ?string $cep, bool $travar = false): Collection
    {
        $frete = app(FreteEmbutido::class);
        $frete->ligar();
        $frete->usarCep($cep);

        $quantidades = [];
        foreach ($pedidos as $i => $pedido) {
            $id = (int) Str::after((string) $pedido['variante'], 'kzv-');
            if (! str_starts_with((string) $pedido['variante'], 'kzv-') || $id < 1) {
                throw ValidationException::withMessages(["itens.{$i}.variante" => 'Produto inválido.']);
            }
            $quantidades[$id] = ($quantidades[$id] ?? 0) + (int) $pedido['quantidade'];
        }

        $produtos = Product::query()
            ->whereIn('id', array_keys($quantidades))
            ->where('is_active', true)
            ->with('quantityDiscounts', 'images', 'parent.images')
            ->when($travar, fn ($q) => $q->lockForUpdate())
            ->get()
            ->keyBy('id');

        $publicados = FerraKoraProduto::query()
            ->where('ativo', true)
            ->whereIn('product_id', $produtos->map(fn (Product $p) => $p->parent_product_id ?? $p->id))
            ->pluck('product_id')
            ->all();

        $semEstoque = [];
        $itens = collect();

        foreach ($quantidades as $id => $quantidade) {
            $produto = $produtos->get($id);

            if (! $produto || ! in_array($produto->parent_product_id ?? $produto->id, $publicados, true)) {
                throw ValidationException::withMessages(['itens' => 'Este produto não está mais à venda no FerraKora.']);
            }

            if ($produto->stock < $quantidade) {
                $semEstoque[] = $produto->name;

                continue;
            }

            $itens->push([
                'product' => $produto,
                'quantity' => $quantidade,
                'subtotal' => round($produto->unitPriceForQuantity($quantidade) * $quantidade, 2),
            ]);
        }

        if ($semEstoque !== []) {
            throw new CartStockChangedException($semEstoque);
        }

        return $itens;
    }

    /**
     * Valores da compra, em reais. desconto_pix só vale pagando tudo no Pix.
     *
     * @return array{subtotal: float, desconto_plataforma: float, desconto_pix: float, total: float, total_pix: float, prazo: ?int}
     */
    public function valores(Collection $itens): array
    {
        $subtotal = round($itens->sum('subtotal'), 2);
        $entrega = app(FreteEmbutido::class)->entregaDoCarrinho($itens);
        $plataforma = (float) $entrega['desconto_plataforma'];
        $pix = DescontoPix::desconto(max(0, $subtotal - $plataforma));
        $total = round($subtotal - $plataforma, 2);

        return [
            'subtotal' => $subtotal,
            'desconto_plataforma' => $plataforma,
            'desconto_pix' => $pix,
            'total' => $total,
            'total_pix' => round($total - $pix, 2),
            'prazo' => $entrega['prazo'],
        ];
    }

    /** Resumo para a tela do checkout (preços em centavos, como o catálogo). */
    public function resumo(array $pedidos, ?string $cep): array
    {
        $itens = $this->itens($pedidos, $cep);
        $valores = $this->valores($itens);

        return [
            'itens' => $itens->map(fn (array $item) => [
                'variante' => 'kzv-'.$item['product']->id,
                'nome' => $item['product']->name,
                'imagem' => $item['product']->images->first()?->url ?? $item['product']->parent?->images->first()?->url,
                'quantidade' => $item['quantity'],
                'estoque' => (int) $item['product']->stock,
                'preco_unitario' => self::centavos($item['subtotal'] / $item['quantity']),
                'subtotal' => self::centavos($item['subtotal']),
            ])->values()->all(),
            'subtotal' => self::centavos($valores['subtotal']),
            'desconto_plataforma' => self::centavos($valores['desconto_plataforma']),
            'desconto_pix' => self::centavos($valores['desconto_pix']),
            'desconto_pix_percentual' => DescontoPix::percentual(),
            'frete' => 0,
            'prazo_dias' => $valores['prazo'],
            'total' => self::centavos($valores['total']),
            'total_pix' => self::centavos($valores['total_pix']),
            'mercadopago_public_key' => CredenciaisCheckout::publicKey(),
        ];
    }

    /**
     * Cria o pedido e a cobrança. Estoque e preço são conferidos sob lock;
     * se o total mudou desde a tela (total_esperado, em centavos), nada é
     * gravado e o site recebe o resumo novo.
     *
     * @return array{order: Order, mp: array<string, mixed>}
     */
    public function criarPedido(array $dados, array $localizacao): array
    {
        $metodo = $dados['pagamento']['metodo'];
        $endereco = $dados['endereco'];

        return DB::transaction(function () use ($dados, $metodo, $endereco, $localizacao) {
            $itens = $this->itens($dados['itens'], $endereco['zip'], travar: true);
            $valores = $this->valores($itens);
            $pixDiscount = $metodo === Payment::METHOD_PIX ? $valores['desconto_pix'] : 0.0;
            $total = round($valores['total'] - $pixDiscount, 2);

            if (self::centavos($total) !== (int) $dados['total_esperado']) {
                throw new PrecoMudouException();
            }

            $user = $this->cliente($dados['cliente']);
            $address = $user->addresses()->create([
                ...$endereco,
                'recipient_name' => $dados['cliente']['name'],
                'phone' => $dados['cliente']['phone'],
                'is_default' => $user->addresses()->count() === 0,
            ]);
            $frete = ShippingMethod::freteGratis();

            $order = Order::create([
                'user_id' => $user->id,
                'site_origem' => Order::SITE_FERRAKORA,
                'external_order_id' => Order::gerarNumeroDaLoja(),
                'status' => Order::STATUS_AWAITING_PAYMENT,
                'shipping_method_id' => $frete->id,
                'shipping_carrier_name' => $frete->name,
                'shipping_name' => $address->recipient_name,
                'shipping_phone' => $address->phone,
                'shipping_zip' => $address->zip,
                'shipping_street' => $address->street,
                'shipping_number' => $address->number,
                'shipping_complement' => $address->complement,
                'shipping_neighborhood' => $address->neighborhood,
                'shipping_city' => $address->city,
                'shipping_state' => $address->state,
                ...$localizacao,
                'subtotal' => $valores['subtotal'],
                'shipping_cost' => 0,
                'discount_amount' => round($pixDiscount + $valores['desconto_plataforma'], 2),
                'pix_discount_amount' => $pixDiscount,
                'desconto_plataforma_amount' => $valores['desconto_plataforma'],
                'total' => $total,
            ]);

            foreach ($itens as $item) {
                $order->items()->create([
                    'product_id' => $item['product']->id,
                    'product_name' => $item['product']->name,
                    'product_price' => $item['product']->unitPriceForQuantity($item['quantity']),
                    'quantity' => $item['quantity'],
                    'subtotal' => $item['subtotal'],
                ]);

                $this->stock->adjust($item['product'], -$item['quantity'], StockMovement::TYPE_SALE, reason: "Pedido #{$order->id}", reference: $order);
            }

            if ($metodo === Payment::METHOD_PIX) {
                $mp = $this->mercadoPago->createPixPayment($total, $this->pagador($user, $order), "order-{$order->id}-payment-1", "order:{$order->id}:payment:1");

                Payment::create([
                    'order_id' => $order->id,
                    'provider' => Payment::PROVIDER_MERCADOPAGO,
                    'mercadopago_payment_id' => (string) $mp['id'],
                    'method_type' => Payment::METHOD_PIX,
                    'amount' => $total,
                    'status' => Payment::STATUS_REQUIRES_CONFIRMATION,
                ]);

                DB::afterCommit(fn () => $this->enviarEmailDoPix($user, $order, $mp, $total));

                return ['order' => $order, 'mp' => $mp];
            }

            $cartao = $dados['pagamento'];
            $mp = $this->mercadoPago->createOrder([
                'type' => 'online',
                'processing_mode' => 'automatic',
                'capture_mode' => 'automatic',
                'external_reference' => "order-{$order->id}-payment-1",
                'total_amount' => (string) $total,
                'payer' => $this->pagador($user),
                'transactions' => ['payments' => [[
                    'amount' => (string) $total,
                    'payment_method' => [
                        'id' => $cartao['mp_payment_method_id'],
                        'type' => 'credit_card',
                        'token' => $cartao['mp_card_token'],
                        'installments' => (int) $cartao['mp_card_installments'],
                    ],
                ]]],
            ], "order:{$order->id}:payment:1");
            $pagamento = $mp['transactions']['payments'][0] ?? [];

            if (in_array($pagamento['status'] ?? null, ['failed', 'rejected'], true)) {
                // Rollback: sem pedido, sem baixa de estoque, sem conta nova.
                throw new MercadoPagoException('Pagamento no cartão recusado: '.($pagamento['status_detail'] ?? 'sem motivo.'));
            }

            Payment::create([
                'order_id' => $order->id,
                'provider' => Payment::PROVIDER_MERCADOPAGO,
                'mercadopago_order_id' => (string) $mp['id'],
                'mercadopago_payment_id' => isset($pagamento['id']) ? (string) $pagamento['id'] : null,
                'method_type' => Payment::METHOD_CARD,
                'amount' => $total,
                'status' => ($pagamento['status'] ?? null) === 'processed' ? Payment::STATUS_CAPTURED : Payment::STATUS_REQUIRES_CONFIRMATION,
            ]);

            DB::afterCommit(fn () => $this->finalizer->finalize($order->fresh(['payments'])));

            return ['order' => $order, 'mp' => $mp];
        });
    }

    /**
     * Reconsulta o Mercado Pago (nunca confia no navegador) e finaliza o
     * pedido pago pelo OrderPaymentFinalizer, a mesma fonte do webhook.
     */
    public function atualizarStatus(Order $order): Order
    {
        foreach ($order->payments as $payment) {
            if ($payment->status !== Payment::STATUS_REQUIRES_CONFIRMATION || $payment->provider !== Payment::PROVIDER_MERCADOPAGO) {
                continue;
            }

            if ($payment->mercadopago_order_id) {
                $mp = $this->mercadoPago->retrieveOrder($payment->mercadopago_order_id)['transactions']['payments'][0] ?? [];
                $status = match (true) {
                    in_array($mp['status'] ?? null, ['failed', 'rejected', 'cancelled'], true) => Payment::STATUS_CANCELED,
                    ($mp['status'] ?? null) === 'processed' => Payment::STATUS_CAPTURED,
                    default => null,
                };
            } else {
                $mp = $this->mercadoPago->retrievePayment($payment->mercadopago_payment_id);
                $status = match ($mp['status'] ?? null) {
                    'approved' => Payment::STATUS_CAPTURED,
                    'cancelled', 'rejected' => Payment::STATUS_CANCELED,
                    default => null,
                };
            }

            if ($status) {
                $payment->update(['status' => $status]);
            }
            if ($status === Payment::STATUS_CANCELED) {
                $this->finalizer->cancelSiblingsAfterFailure($order->fresh(['payments']), $payment);
            }
        }

        $this->finalizer->finalize($order->fresh(['payments']));

        return $order->refresh();
    }

    /** Token do acompanhamento do pedido no site (sem login). */
    public static function token(Order $order): string
    {
        return hash_hmac('sha256', 'ferrakora-pedido:'.$order->id, (string) config('app.key'));
    }

    public static function centavos(float $reais): int
    {
        return (int) round($reais * 100);
    }

    /**
     * Conta do cliente na KazaKora. Mesmo e-mail e mesmo CPF (ou conta sem
     * CPF) = mesma conta; e-mail ou CPF de outra conta = aviso. Conta nova
     * recebe a senha temporária no e-mail, como no checkout da loja.
     */
    private function cliente(array $cliente): User
    {
        $cpf = preg_replace('/\D/', '', $cliente['cpf']);
        $conta = User::withTrashed()->where('email', trim($cliente['email']))->first();

        if ($conta) {
            $cpfDaConta = preg_replace('/\D/', '', (string) $conta->cpf);
            if ($conta->trashed() || ($cpfDaConta !== '' && $cpfDaConta !== $cpf)) {
                throw ValidationException::withMessages(['cliente.email' => 'Este e-mail já tem cadastro na KazaKora com outro CPF. Confira o CPF ou use outro e-mail.']);
            }
            if ($cpfDaConta === '') {
                if (User::cpfEmUso($cpf, $conta->id)) {
                    throw ValidationException::withMessages(['cliente.cpf' => 'Este CPF já está em outra conta. Use o e-mail dessa conta.']);
                }
                $conta->forceFill(['cpf' => $cpf])->save();
            }
            if (blank($conta->phone)) {
                $conta->forceFill(['phone' => $cliente['phone']])->save();
            }

            return $conta;
        }

        if (User::cpfEmUso($cpf)) {
            throw ValidationException::withMessages(['cliente.cpf' => 'Este CPF já tem cadastro com outro e-mail. Use o e-mail da sua conta.']);
        }

        $senhaTemporaria = Str::password(10, symbols: false);
        $user = User::create([
            'name' => $cliente['name'],
            'email' => trim($cliente['email']),
            'cpf' => $cpf,
            'phone' => $cliente['phone'],
            'password' => Hash::make($senhaTemporaria),
            'role' => User::ROLE_CUSTOMER,
        ]);
        $user->forceFill(['deve_trocar_senha' => true])->save();

        DB::afterCommit(function () use ($user, $senhaTemporaria) {
            rescue(fn () => Mail::to($user->email)->send(new WelcomeEmail($user, $senhaTemporaria)));
        });

        return $user;
    }

    /** Igual ao checkout da loja: no Pix vai o e-mail nosso, para o Mercado Pago não escrever ao cliente. */
    private function pagador(User $user, ?Order $pix = null): array
    {
        $partes = preg_split('/\s+/', trim($user->name), 2);

        return array_filter([
            'email' => $pix ? str_replace('{pedido}', (string) $pix->id, (string) config('loja.email_pagador_pix')) : $user->email,
            'first_name' => $partes[0] ?? $user->name,
            'last_name' => $partes[1] ?? $partes[0] ?? $user->name,
            'identification' => ['type' => 'CPF', 'number' => preg_replace('/\D/', '', (string) $user->cpf)],
        ]);
    }

    private function enviarEmailDoPix(User $user, Order $order, array $mp, float $valor): void
    {
        $qr = $mp['point_of_interaction']['transaction_data'] ?? [];

        if (empty($qr['qr_code'])) {
            return;
        }

        rescue(fn () => Mail::to($user->email)->send(new PixAguardandoPagamento($order, $valor, (string) $qr['qr_code'], $qr['qr_code_base64'] ?? null, $mp['date_of_expiration'] ?? null)), null, false);
    }
}
