<?php

namespace App\Modules\Checkout\Support;

use App\Modules\Analytics\Models\CheckoutEvento;
use App\Modules\Analytics\Models\SiteVisit;
use App\Modules\Checkout\Models\Lead;
use App\Modules\Checkout\Models\Order;
use App\Support\SiteAtual;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leads (pedido do Lira 2026-11-06): cada campo que o cliente preenche no
 * checkout (nome, e-mail, CPF, telefone, CEP) chega por ajax e atualiza a
 * linha aberta da sessão, com os produtos do carrinho naquele momento.
 *
 * - Uma linha aberta por sessão (ou visitante) e loja; nunca duplica.
 * - Guarda o que foi digitado (o e-mail pode estar pela metade); CPF,
 *   telefone e CEP só com dígitos. Campo apagado não apaga o que já se sabia.
 * - Nunca quebra o checkout: falha vira log sem dado pessoal.
 * - Itens e preços vêm do carrinho do servidor, não do navegador.
 * - Não manda mensagem para o cliente.
 */
class LeadsDigitados
{
    public function __construct(private readonly LeadsDoCheckout $checkout)
    {
    }

    /** @param  array<string, mixed>  $campos */
    public function registrar(Request $request, array $campos, ?string $ultimoCampo = null): ?Lead
    {
        try {
            return $this->gravar($request, $campos, $ultimoCampo);
        } catch (Throwable $e) {
            Log::warning('checkout.lead_digitado.falhou', ['erro' => $e::class]);

            return null;
        }
    }

    /** Pedido saiu: leads abertos da mesma sessão, e-mail ou CPF viram "convertido". */
    public function converter(Order $order, ?string $sessionId, ?string $email, ?string $cpf): int
    {
        try {
            if (! $sessionId && ! $email && ! $cpf) {
                return 0;
            }

            return Lead::query()
                ->where('site', $order->site_origem ?: SiteAtual::KAZAKORA)
                ->where('status', Lead::STATUS_ABERTO)
                ->where(fn ($q) => $q
                    ->when($sessionId, fn ($q) => $q->orWhere('session_id', $sessionId))
                    ->when($email, fn ($q) => $q->orWhere('email', $email))
                    ->when($cpf, fn ($q) => $q->orWhere('cpf', $cpf)))
                ->update([
                    'status' => Lead::STATUS_CONVERTIDO,
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'convertido_em' => now(),
                    'updated_at' => now(),
                ]);
        } catch (Throwable $e) {
            Log::warning('checkout.lead_digitado.converter_falhou', ['erro' => $e::class, 'order_id' => $order->id]);

            return 0;
        }
    }

    /** @return array<string, ?string> */
    public static function normalizar(array $campos): array
    {
        $texto = fn ($v, int $max) => is_string($v) && ($v = trim($v)) !== '' ? mb_substr($v, 0, $max) : null;
        $digitos = fn ($v, int $max) => is_string($v) && ($d = preg_replace('/\D/', '', $v)) !== '' ? substr($d, 0, $max) : null;

        $telefone = $digitos($campos['telefone'] ?? null, 15);
        if ($telefone && strlen($telefone) >= 12 && str_starts_with($telefone, '55')) {
            $telefone = substr($telefone, 2);
        }

        $email = $texto($campos['email'] ?? null, 255);

        return [
            'nome' => $texto($campos['nome'] ?? null, 255),
            'email' => $email !== null ? mb_strtolower($email) : null,
            'cpf' => $digitos($campos['cpf'] ?? null, 11),
            'telefone' => $telefone ? substr($telefone, 0, 13) : null,
            'cep' => $digitos($campos['cep'] ?? null, 8),
        ];
    }

    /**
     * Checkout dos outros sites (FerraKora, pedido do Lira 11/11/2026): sem
     * sessão do Laravel nem carrinho no servidor. O site manda um id da aba
     * ($sessao) e os itens já conferidos pelo CheckoutOutroSite; o visitante é
     * o mesmo da visita (md5 do IP), para a origem do anúncio aparecer.
     *
     * @param  array<string, mixed>  $campos
     * @param  list<array<string, mixed>>  $itens  no formato de LeadsDoCheckout::itensDoCarrinho()
     */
    public function registrarDeOutroSite(Request $request, string $site, string $sessao, array $campos, ?string $ultimoCampo, array $itens): ?Lead
    {
        try {
            $dados = array_filter(self::normalizar($campos), fn ($v) => $v !== null);
            if ($dados === [] || $itens === [] || $sessao === '') {
                return null;
            }

            $sessionId = mb_substr($site.'-'.$sessao, 0, 100);
            $visitante = $request->ip() !== null ? substr(md5($request->ip()), 0, 32) : null;

            $valores = [
                ...$dados,
                'site' => $site,
                'session_id' => $sessionId,
                'visitor_id' => $visitante,
                'ultimo_campo' => in_array($ultimoCampo, Lead::CAMPOS, true) ? $ultimoCampo : null,
                'itens' => $itens,
                'quantidade_itens' => array_sum(array_column($itens, 'quantidade')),
                'subtotal' => round(array_sum(array_column($itens, 'subtotal')), 2),
                'ip' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            ];

            return DB::transaction(function () use ($site, $sessionId, $valores, $visitante) {
                $lead = Lead::query()
                    ->where('site', $site)
                    ->where('status', Lead::STATUS_ABERTO)
                    ->where('session_id', $sessionId)
                    ->latest('updated_at')
                    ->lockForUpdate()
                    ->first();

                if ($lead) {
                    $lead->fill(array_filter($valores, fn ($v) => $v !== null))->save();
                    $lead->touch();

                    return $lead;
                }

                return Lead::create([...$valores, ...self::origemNoSite($site, $visitante), 'status' => Lead::STATUS_ABERTO]);
            });
        } catch (Throwable $e) {
            Log::warning('checkout.lead_digitado.falhou', ['erro' => $e::class, 'site' => $site]);

            return null;
        }
    }

    /** Origem e UTM da visita do visitante no outro site (últimas 24 h). */
    private static function origemNoSite(string $site, ?string $visitante): array
    {
        if (! $visitante) {
            return [];
        }

        $visitas = SiteVisit::query()->where('site', $site)->where('visitor_id', $visitante)
            ->where('created_at', '>=', now()->subDay());
        $origem = (clone $visitas)->whereNotNull('origem')->where('origem', '!=', 'interno')->latest('id')->value('origem');
        $utm = (clone $visitas)->whereNotNull('utm_source')->latest('id')->first(['utm_source', 'utm_medium', 'utm_campaign']);

        return [
            'origem' => $origem ? mb_substr((string) $origem, 0, 20) : null,
            'utm_source' => $utm?->utm_source ? mb_substr($utm->utm_source, 0, 100) : null,
            'utm_medium' => $utm?->utm_medium ? mb_substr($utm->utm_medium, 0, 100) : null,
            'utm_campaign' => $utm?->utm_campaign ? mb_substr($utm->utm_campaign, 0, 150) : null,
        ];
    }

    private function gravar(Request $request, array $campos, ?string $ultimoCampo): ?Lead
    {
        if (! $request->hasSession()) {
            return null;
        }

        $dados = array_filter(self::normalizar($campos), fn ($v) => $v !== null);
        if ($dados === []) {
            return null;
        }

        $itens = $this->checkout->itensDoCarrinho();
        if ($itens === []) {
            return null;
        }

        $site = app(SiteAtual::class)->atual() ?? SiteAtual::KAZAKORA;
        $sessionId = $request->session()->getId();
        $visitante = CheckoutEvento::visitante($request);

        $valores = [
            ...$dados,
            'site' => $site,
            'session_id' => $sessionId,
            'visitor_id' => $visitante,
            'ultimo_campo' => in_array($ultimoCampo, Lead::CAMPOS, true) ? $ultimoCampo : null,
            'itens' => $itens,
            'quantidade_itens' => array_sum(array_column($itens, 'quantidade')),
            'subtotal' => round(array_sum(array_column($itens, 'subtotal')), 2),
            'ip' => $request->ip(),
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
        ];
        if ($request->user()) {
            $valores['user_id'] = $request->user()->id;
        }

        return DB::transaction(function () use ($site, $sessionId, $valores, $visitante) {
            // Mesma sessão ou mesmo visitante (cookie kazakora_visitor; o
            // "s-..." tirado da sessão não conta, ele muda com a sessão).
            $cookieVisitante = $visitante && ! str_starts_with($visitante, 's-') ? $visitante : null;
            $lead = Lead::query()
                ->where('site', $site)
                ->where('status', Lead::STATUS_ABERTO)
                ->where(fn ($q) => $q
                    ->where('session_id', $sessionId)
                    ->when($cookieVisitante, fn ($q) => $q->orWhere('visitor_id', $cookieVisitante)))
                ->orderByRaw('session_id = ? desc', [$sessionId])
                ->latest('updated_at')
                ->lockForUpdate()
                ->first();

            if ($lead) {
                $lead->fill(array_filter($valores, fn ($v) => $v !== null))->save();
                $lead->touch();

                return $lead;
            }

            return Lead::create([
                ...$valores,
                ...$this->checkout->origem($visitante),
                'status' => Lead::STATUS_ABERTO,
            ]);
        });
    }
}
