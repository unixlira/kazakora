<?php

namespace App\Modules\Checkout\Support;

use App\Models\User;
use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Cart\Support\CartManager;
use App\Modules\Checkout\Models\LeadCheckout;
use App\Modules\Checkout\Models\Order;
use App\Rules\CpfValido;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Recuperação de carrinho abandonado (pedido do Lira 2026-10-24): grava o
 * contato e o carrinho de quem clicou em "Ir para Entrega" (e de novo em "Ir
 * para Pagamento") e liga o lead ao pedido quando ele sai.
 *
 * Regras:
 * - Upsert: a mesma sessão, o mesmo e-mail ou o mesmo CPF com lead aberto no
 *   mesmo site atualizam a linha, nunca criam outra.
 * - Nunca quebra nem atrasa o checkout: qualquer falha vira log SEM dado
 *   pessoal (só a classe do erro) e o método devolve null.
 * - Itens e preços vêm do carrinho do servidor, não do navegador.
 * - Não manda mensagem nenhuma para o cliente (isso pede OK do Lira).
 */
class LeadsDoCheckout
{
    public function __construct(private readonly CartManager $cart)
    {
    }

    /**
     * @param  array{nome?: ?string, email?: ?string, cpf?: ?string, telefone?: ?string}  $dados
     */
    public function registrar(Request $request, array $dados, string $etapa = LeadCheckout::ETAPA_ENTREGA): ?LeadCheckout
    {
        try {
            return $this->gravar($request, $dados, $etapa);
        } catch (Throwable $e) {
            Log::warning('checkout.lead.falhou', ['erro' => $e::class]);

            return null;
        }
    }

    /**
     * Pedido criado (ou pago) pelo checkout: os leads abertos da mesma sessão,
     * do mesmo e-mail ou do mesmo CPF viram "convertido" com o order_id.
     * $sessionId é o de ANTES do login do convidado (o login troca o ID).
     */
    public function converter(Order $order, ?string $sessionId = null): int
    {
        try {
            $user = $order->user ?? ($order->user_id ? User::find($order->user_id) : null);
            $email = self::email($user?->email);
            $cpf = self::cpf($user?->cpf);

            if (! $sessionId && ! $email && ! $cpf) {
                return 0;
            }

            return LeadCheckout::query()
                ->where('site', $order->site_origem ?: SiteAtual::KAZAKORA)
                ->where('status', LeadCheckout::STATUS_ABERTO)
                ->where(fn ($q) => $q
                    ->when($sessionId, fn ($q) => $q->orWhere('session_id', $sessionId))
                    ->when($email, fn ($q) => $q->orWhere('email', $email))
                    ->when($cpf, fn ($q) => $q->orWhere('cpf', $cpf)))
                ->update([
                    'status' => LeadCheckout::STATUS_CONVERTIDO,
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'convertido_em' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('checkout.lead.converter_falhou', ['erro' => $e::class, 'order_id' => $order->id]);

            return 0;
        }
    }

    private function gravar(Request $request, array $dados, string $etapa): ?LeadCheckout
    {
        $itens = $this->itensDoCarrinho();

        if ($itens === []) {
            return null;
        }

        $user = $request->user();
        $nome = trim((string) ($dados['nome'] ?? '')) ?: $user?->name;
        $email = self::email($dados['email'] ?? null) ?? self::email($user?->email);
        $cpf = self::cpf($dados['cpf'] ?? null) ?? self::cpf($user?->cpf);
        $telefone = self::telefone($dados['telefone'] ?? null) ?? self::telefone($user?->phone);

        // Sem nenhum jeito de falar com a pessoa, não é lead.
        if (! $email && ! $telefone) {
            return null;
        }

        $site = app(SiteAtual::class)->atual() ?? SiteAtual::KAZAKORA;
        $sessionId = $request->hasSession() ? $request->session()->getId() : null;
        $visitante = $request->hasSession() ? CheckoutEvento::visitante($request) : null;

        $valores = [
            'site' => $site,
            'session_id' => $sessionId,
            'visitor_id' => $visitante,
            'user_id' => $user?->id,
            'nome' => $nome ? mb_substr($nome, 0, 255) : null,
            'email' => $email,
            'cpf' => $cpf,
            'telefone' => $telefone,
            'itens' => $itens,
            'quantidade_itens' => array_sum(array_column($itens, 'quantidade')),
            'subtotal' => round(array_sum(array_column($itens, 'subtotal')), 2),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ];

        return DB::transaction(function () use ($site, $sessionId, $email, $cpf, $valores, $etapa, $visitante) {
            $lead = LeadCheckout::query()
                ->where('site', $site)
                ->where('status', LeadCheckout::STATUS_ABERTO)
                ->where(fn ($q) => $q
                    ->when($sessionId, fn ($q) => $q->orWhere('session_id', $sessionId))
                    ->when($email, fn ($q) => $q->orWhere('email', $email))
                    ->when($cpf, fn ($q) => $q->orWhere('cpf', $cpf)))
                // A mesma sessão vence o e-mail/CPF de outro aparelho.
                ->when($sessionId, fn ($q) => $q->orderByRaw('session_id = ? desc', [$sessionId]))
                ->latest('updated_at')
                ->lockForUpdate()
                ->first();

            if ($lead) {
                // Nunca volta de "pagamento" para "entrega".
                $valores['etapa'] = $lead->etapa === LeadCheckout::ETAPA_PAGAMENTO ? $lead->etapa : $etapa;
                // Campo vazio agora não apaga o que já se sabia.
                $lead->fill(array_filter($valores, fn ($v) => $v !== null && $v !== ''))->save();
                $lead->touch();

                return $lead;
            }

            return LeadCheckout::create([
                ...$valores,
                ...$this->origem($visitante),
                'etapa' => $etapa,
                'status' => LeadCheckout::STATUS_ABERTO,
            ]);
        });
    }

    /** @return list<array<string, mixed>> */
    private function itensDoCarrinho(): array
    {
        return $this->cart->items()
            ->filter(fn ($item) => $item['quantity'] > 0)
            ->map(fn ($item) => [
                'product_id' => $item['product']->id,
                'parent_product_id' => $item['product']->parent_product_id,
                'nome' => $item['product']->name,
                'variacao' => $item['product']->variation ?: null,
                'quantidade' => (int) $item['quantity'],
                'preco_unitario' => round((float) $item['product']->unitPriceForQuantity($item['quantity']), 2),
                'subtotal' => round((float) $item['subtotal'], 2),
            ])
            ->values()
            ->all();
    }

    /** Origem e UTM da visita (log de visitas das últimas 24 h), quando houver. */
    private function origem(?string $visitante): array
    {
        if (! $visitante) {
            return [];
        }

        $utm = SiteVisit::query()
            ->where('site', SiteVisit::SITE_LOJA)
            ->where('visitor_id', $visitante)
            ->where('created_at', '>=', now()->subDay())
            ->whereNotNull('utm_source')
            ->latest('id')
            ->first(['utm_source', 'utm_medium', 'utm_campaign']);

        return [
            'origem' => CheckoutEvento::origemDaVisita($visitante),
            'utm_source' => $utm?->utm_source ? mb_substr($utm->utm_source, 0, 100) : null,
            'utm_medium' => $utm?->utm_medium ? mb_substr($utm->utm_medium, 0, 100) : null,
            'utm_campaign' => $utm?->utm_campaign ? mb_substr($utm->utm_campaign, 0, 150) : null,
        ];
    }

    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && mb_strlen($email) <= 255 ? $email : null;
    }

    /** Só dígitos e só CPF válido (mesma regra do checkout, App\Rules\CpfValido). */
    public static function cpf(?string $cpf): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $cpf);

        return CpfValido::valido($digitos) ? $digitos : null;
    }

    /** Só dígitos, com DDD (10 ou 11), aceitando o 55 na frente. */
    public static function telefone(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $telefone);

        if (strlen($digitos) >= 12 && str_starts_with($digitos, '55')) {
            $digitos = substr($digitos, 2);
        }

        return in_array(strlen($digitos), [10, 11], true) ? $digitos : null;
    }
}
