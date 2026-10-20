<?php

namespace App\Modules\App\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\App\Http\Middleware\AuthenticateAppCliente;
use App\Modules\App\Models\AppPushToken;
use App\Modules\App\Services\CheckoutApp;
use App\Modules\App\Support\FormatoApp;
use App\Modules\Checkout\Models\Payment;
use App\Modules\Checkout\Support\CartStockChangedException;
use App\Modules\FerraKora\Services\PrecoMudouException;
use App\Rules\CpfValido;
use App\Services\MercadoPago\Exceptions\MercadoPagoException;
use App\Services\MercadoPago\Exceptions\PagamentoRecusadoException;
use App\Services\MercadoPago\MercadoPagoPaymentService;
use App\Support\PaymentGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Checkout do app nativo (2026-10-18). Regras em CheckoutApp (as do
 * checkout do site); aqui só a entrada e a saída do contrato do app.
 */
class CheckoutAppController extends Controller
{
    public function __construct(private readonly CheckoutApp $checkout) {}

    /** @return array<string, list<mixed>> */
    public static function regrasDosItens(): array
    {
        return [
            'itens' => ['required', 'array', 'min:1', 'max:20'],
            'itens.*.variante' => ['required', 'string', 'max:30'],
            'itens.*.quantidade' => ['required', 'integer', 'min:1', 'max:99'],
        ];
    }

    /** Resumo do carrinho. Cupom inválido volta em cupom_erro, sem 422. Token opcional (cupom por pessoa). */
    public function resumo(Request $request): JsonResponse
    {
        $dados = $request->validate([
            ...self::regrasDosItens(),
            'cep' => ['nullable', 'string', 'max:9'],
            'frete' => ['nullable', 'string', 'max:40'],
            'cupom' => ['nullable', 'string', 'max:60'],
        ]);
        $user = AuthenticateAppCliente::doToken($request)[0] ?? null;
        $cep = filled($dados['cep'] ?? null) ? preg_replace('/\D/', '', $dados['cep']) : null;

        try {
            return response()->json($this->checkout->resumo($dados['itens'], $cep, $dados['frete'] ?? null, $dados['cupom'] ?? null, $user?->email));
        } catch (CartStockChangedException $e) {
            return response()->json(['message' => $e->getMessage(), 'estoque' => true], 409);
        }
    }

    public function pedido(Request $request): JsonResponse
    {
        if (PaymentGateway::active() !== PaymentGateway::MERCADOPAGO || ! app(MercadoPagoPaymentService::class)->isConfigured()) {
            return response()->json(['message' => 'Pagamento indisponível no momento. Tente de novo em alguns minutos.'], 503);
        }

        /** @var User $user */
        $user = $request->user();
        $cartao = $request->input('pagamento.metodo') === Payment::METHOD_CARD;
        $dados = $request->validate([
            ...self::regrasDosItens(),
            'endereco_id' => ['required', 'integer'],
            'frete' => ['nullable', 'string', 'max:40'],
            'cupom' => ['nullable', 'string', 'max:60'],
            'total_esperado' => ['required', 'integer', 'min:1'],
            'pagamento.metodo' => ['required', 'in:pix,card'],
            'pagamento.mp_card_token' => [$cartao ? 'required' : 'nullable', 'string'],
            'pagamento.mp_card_installments' => [$cartao ? 'required' : 'nullable', 'integer', 'min:1', 'max:12'],
            'pagamento.mp_payment_method_id' => [$cartao ? 'required' : 'nullable', 'string', 'max:50'],
            'pagamento.mp_issuer_id' => ['nullable'],
        ], [
            'endereco_id.required' => 'Escolha o endereço de entrega.',
            'pagamento.metodo.in' => 'Escolha Pix ou cartão.',
            'pagamento.mp_card_token.required' => 'Confira os dados do cartão.',
            'pagamento.mp_card_installments.required' => 'Escolha as parcelas.',
            'pagamento.mp_payment_method_id.required' => 'Confira os dados do cartão.',
        ]);

        $endereco = $user->addresses()->find($dados['endereco_id']);
        if (! $endereco) {
            throw ValidationException::withMessages(['endereco_id' => 'Esse endereço não está mais na sua conta. Escolha outro ou cadastre um novo.']);
        }
        // Mesmas travas do checkout do site: CPF válido (o Mercado Pago recusa sem) e nome completo.
        if (! CpfValido::valido((string) $user->cpf)) {
            throw ValidationException::withMessages(['cpf' => 'Cadastre um CPF válido na sua conta para pagar.']);
        }

        $plataforma = strtolower((string) $request->header('X-App-Plataforma'));
        $pedido = [
            ...$dados,
            'conta' => $user,
            'endereco_salvo' => $endereco,
            'app_plataforma' => in_array($plataforma, AppPushToken::PLATAFORMAS, true) ? $plataforma : 'app',
            'cliente' => ['name' => $user->name, 'email' => $user->email, 'cpf' => (string) $user->cpf, 'phone' => (string) ($user->phone ?: $endereco->phone)],
            'endereco' => [
                'zip' => preg_replace('/\D/', '', (string) $endereco->zip),
                'street' => $endereco->street,
                'number' => $endereco->number,
                'complement' => $endereco->complement,
                'neighborhood' => $endereco->neighborhood,
                'city' => $endereco->city,
                'state' => $endereco->state,
            ],
        ];

        try {
            ['order' => $order, 'mp' => $mp] = $this->checkout->criarPedido($pedido, $this->localizacao($request));
        } catch (CartStockChangedException $e) {
            return response()->json(['message' => $e->getMessage(), 'estoque' => true], 409);
        } catch (PrecoMudouException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'resumo' => $this->checkout->resumo($dados['itens'], $pedido['endereco']['zip'], $dados['frete'] ?? null, $dados['cupom'] ?? null, $user->email),
            ], 409);
        } catch (MercadoPagoException $e) {
            if ($e instanceof PagamentoRecusadoException) {
                rescue(fn () => $e->registrar());
            }

            Log::channel('mercadopago')->error('mercadopago.app.payment_failed', [
                'metodo' => $dados['pagamento']['metodo'],
                'message' => $e->getMessage(),
                'context' => $e->context(),
            ]);

            if (str_contains(strtolower($e->getMessage()), 'identification')) {
                return response()->json(['message' => 'O Mercado Pago recusou o CPF da sua conta. Confira o CPF e tente de novo.', 'errors' => ['cpf' => ['Confira o CPF.']]], 422);
            }

            return response()->json(['message' => $dados['pagamento']['metodo'] === Payment::METHOD_CARD
                ? 'O pagamento no cartão não foi aprovado. Confira os dados, tente outro cartão ou pague no Pix.'
                : 'Não foi possível gerar o Pix agora. Tente de novo em instantes.'], 422);
        }

        $qr = $mp['point_of_interaction']['transaction_data'] ?? [];
        $pix = $dados['pagamento']['metodo'] === Payment::METHOD_PIX ? [
            'copia_e_cola' => $qr['qr_code'] ?? null,
            'qr_code_base64' => $qr['qr_code_base64'] ?? null,
            'expira_em' => $mp['date_of_expiration'] ?? null,
        ] : null;

        return response()->json([
            'pedido' => FormatoApp::pedido($order->fresh(), true, $pix),
            'pix' => $pix,
        ], 201);
    }

    /** Igual ao checkout da loja: latitude/longitude do IP pela Cloudflare. */
    private function localizacao(Request $request): array
    {
        $lat = $request->header('CF-IPLatitude');
        $lng = $request->header('CF-IPLongitude');

        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            return ['visitante_latitude' => null, 'visitante_longitude' => null];
        }

        return ['visitante_latitude' => (float) $lat, 'visitante_longitude' => (float) $lng];
    }
}
