<?php

namespace App\Modules\Admin\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Checkout\Jobs\DispararCupomJob;
use App\Modules\Checkout\Models\Coupon;
use App\Modules\Checkout\Models\CouponDisparo;
use App\Modules\Checkout\Support\PublicoCupom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cupons de desconto (pedido 2026-10-10): cadastro com regras (validade,
 * pedido mínimo, limite de usos, 1 por cliente) e disparo em lote por e-mail
 * e notificação no site.
 */
class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Admin/Cupons/Index', [
            'cupons' => Coupon::query()->comUsos()->withCount('disparos')->latest()->get()
                ->map(fn (Coupon $coupon) => $this->paraTela($coupon)),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Coupon::create($this->validar($request));

        return back()->with('success', 'Cupom criado.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($this->validar($request, $coupon));

        return back()->with('success', 'Cupom atualizado.');
    }

    public function toggle(Coupon $coupon): RedirectResponse
    {
        $coupon->update(['is_active' => ! $coupon->is_active]);

        return back()->with('success', $coupon->is_active ? 'Cupom ativado.' : 'Cupom desativado.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        if ($coupon->totalUsos() > 0) {
            return back()->withErrors(['cupom' => 'Este cupom já foi usado em pedidos — desative em vez de excluir, para manter o histórico.']);
        }

        $coupon->delete();

        return back()->with('success', 'Cupom excluído.');
    }

    public function disparo(Coupon $coupon): Response
    {
        $coupon->loadCount(['pedidos as usos' => fn ($pedidos) => $pedidos->whereIn('status', Coupon::STATUS_USO)]);

        return Inertia::render('Admin/Cupons/Disparo', [
            'cupom' => $this->paraTela($coupon),
            'publicos' => collect(PublicoCupom::OPCOES)->map(fn ($nome, $chave) => ['chave' => $chave, 'nome' => $nome])->values(),
            'ocasioes' => collect(PublicoCupom::OCASIOES)->map(fn ($ocasiao, $chave) => ['chave' => $chave, ...$ocasiao])->values(),
            'historico' => $coupon->disparos()->with('criador:id,name')->latest()->get()->map(fn (CouponDisparo $disparo) => [
                'id' => $disparo->id,
                'publico' => PublicoCupom::OPCOES[$disparo->publico] ?? $disparo->publico,
                'ocasiao' => PublicoCupom::OCASIOES[$disparo->ocasiao]['nome'] ?? null,
                'assunto' => $disparo->assunto,
                'canais' => $disparo->canais,
                'total' => $disparo->total,
                'enviados' => $disparo->enviados,
                'falhas' => $disparo->falhas,
                'status' => $disparo->status,
                'criador' => $disparo->criador?->name,
                'criado_em' => $disparo->created_at?->format('d/m/Y H:i'),
            ]),
        ]);
    }

    /** Quantos clientes o público escolhido alcança (prévia antes de enviar). */
    public function contarPublico(Request $request, Coupon $coupon): JsonResponse
    {
        $data = $request->validate([
            'publico' => ['required', Rule::in(array_keys(PublicoCupom::OPCOES))],
            'dias' => ['nullable', 'integer', 'min:1', 'max:365'],
        ]);

        return response()->json(['total' => PublicoCupom::query($data['publico'], (int) ($data['dias'] ?? 30))->count()]);
    }

    public function disparar(Request $request, Coupon $coupon): RedirectResponse
    {
        $data = $request->validate([
            'publico' => ['required', Rule::in(array_keys(PublicoCupom::OPCOES))],
            'dias' => ['nullable', 'integer', 'min:1', 'max:365'],
            'ocasiao' => ['nullable', Rule::in(array_keys(PublicoCupom::OCASIOES))],
            'assunto' => ['required', 'string', 'max:150'],
            'mensagem' => ['required', 'string', 'max:3000'],
            'canais' => ['required', 'array', 'min:1'],
            'canais.*' => [Rule::in(['email', 'site'])],
        ], [
            'canais.required' => 'Escolha ao menos um canal (e-mail ou notificação no site).',
        ]);

        if (! $coupon->is_active) {
            return back()->withErrors(['publico' => 'Ative o cupom antes de disparar.']);
        }

        $disparo = $coupon->disparos()->create([
            ...$data,
            'dias' => $data['publico'] === PublicoCupom::CARRINHO_ABANDONADO ? ($data['dias'] ?? 30) : null,
            'status' => CouponDisparo::STATUS_PENDENTE,
            'created_by' => $request->user()->id,
        ]);

        DispararCupomJob::dispatch($disparo->id);

        return back()->with('success', 'Disparo agendado — os envios começam em instantes e o andamento aparece no histórico.');
    }

    private function validar(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => Coupon::normalizar($request->input('code'))]);

        $data = $request->validate([
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon?->id)],
            'name' => ['nullable', 'string', 'max:120'],
            'discount_type' => ['required', Rule::in([Coupon::TYPE_PERCENTAGE, Coupon::TYPE_FIXED])],
            'discount_value' => ['required', 'numeric', 'gt:0', Rule::when($request->input('discount_type') === Coupon::TYPE_PERCENTAGE, ['max:100'])],
            'min_order_value' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'one_per_customer' => ['boolean'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'is_active' => ['boolean'],
        ], [
            'code.regex' => 'Use só letras, números, - e _ (sem espaço nem acento).',
            'code.unique' => 'Já existe um cupom com esse código.',
            'discount_value.max' => 'Porcentagem vai até 100.',
            'expires_at.after_or_equal' => 'O fim tem que ser depois do início.',
        ]);

        // Data final vale o dia inteiro.
        if (! empty($data['expires_at'])) {
            $data['expires_at'] = \Illuminate\Support\Carbon::parse($data['expires_at'])->endOfDay();
        }

        return $data;
    }

    private function paraTela(Coupon $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'name' => $coupon->name,
            'discount_type' => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'descricao' => $coupon->descricaoDesconto(),
            'min_order_value' => $coupon->min_order_value !== null ? (float) $coupon->min_order_value : null,
            'max_uses' => $coupon->max_uses,
            'one_per_customer' => $coupon->one_per_customer,
            'starts_at' => $coupon->starts_at?->format('Y-m-d'),
            'expires_at' => $coupon->expires_at?->format('Y-m-d'),
            'is_active' => $coupon->is_active,
            'usos' => $coupon->totalUsos(),
            'disparos' => $coupon->disparos_count ?? null,
            'situacao' => match (true) {
                ! $coupon->is_active => 'inativo',
                $coupon->expires_at?->isPast() ?? false => 'expirado',
                $coupon->starts_at?->isFuture() ?? false => 'agendado',
                $coupon->max_uses && $coupon->totalUsos() >= $coupon->max_uses => 'esgotado',
                default => 'ativo',
            },
            'link' => route('catalogo.inicio', ['cupom' => $coupon->code]),
        ];
    }
}
