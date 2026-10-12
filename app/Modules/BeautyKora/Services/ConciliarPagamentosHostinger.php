<?php

namespace App\Modules\BeautyKora\Services;

use App\Modules\Audit\Models\AuditLog;
use App\Modules\BeautyKora\Models\BeautyKoraPedido;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Pagamento confirmado do Beauty Kora (pedido 2026-10-12). O checkout do site
 * é o da Hostinger e ela não tem aviso (webhook) de pedido pago, então a
 * gente pergunta: GET /api/ecommerce/v1/stores/{loja}/orders com
 * payment_status=captured (pago) ou refunded (estornado).
 *
 * O pedido da Hostinger não traz o nosso número. O par é o lote (os itens de
 * um mesmo checkout) com o mesmo e-mail do checkout, criado até 3 h antes do
 * pedido de lá, e cujas variantes estão todas no pedido de lá. Pago vira
 * "paid"; estornado por inteiro vira "canceled". Tudo fica na auditoria.
 */
class ConciliarPagamentosHostinger
{
    private const DIAS = 7;

    private const MAX_PAGINAS = 20;

    /** @return array{pagos: int, estornados: int, sem_par: list<string>} */
    public function executar(): array
    {
        $resultado = ['pagos' => 0, 'estornados' => 0, 'sem_par' => []];
        $loja = $this->loja();

        foreach ($this->pedidosDaHostinger($loja, 'captured') as $resumo) {
            if (BeautyKoraPedido::query()->where('hostinger_pedido_id', $resumo['id'])->exists()) {
                continue;
            }

            $pedido = $this->http()->get("/api/ecommerce/v1/stores/{$loja}/orders/{$resumo['id']}")->throw()->json('data');
            $lote = $this->acharLote($pedido);

            if ($lote === null) {
                $resultado['sem_par'][] = '#'.($pedido['display_id'] ?? $pedido['id']);

                continue;
            }

            $this->marcar($lote, 'paid', $pedido);
            $resultado['pagos']++;
        }

        foreach ($this->pedidosDaHostinger($loja, 'refunded') as $resumo) {
            $itens = BeautyKoraPedido::query()
                ->where('hostinger_pedido_id', $resumo['id'])
                ->where('status', '!=', 'canceled')
                ->get();

            if ($itens->isNotEmpty()) {
                $this->marcar($itens, 'canceled', $resumo);
                $resultado['estornados']++;
            }
        }

        return $resultado;
    }

    /**
     * O lote mais recente que bate com o pedido da Hostinger.
     *
     * @param  array<string, mixed>  $pedido
     * @return Collection<int, BeautyKoraPedido>|null
     */
    private function acharLote(array $pedido): ?Collection
    {
        $email = mb_strtolower(trim((string) ($pedido['customer_email'] ?? '')));
        $criado = Carbon::parse($pedido['created_at'] ?? 'now')->setTimezone(config('app.timezone'));
        $variantes = collect($pedido['items'] ?? [])->pluck('variant_id')->filter()->map(fn ($v) => (string) $v)->all();

        if ($email === '' || $variantes === []) {
            return null;
        }

        $candidatos = BeautyKoraPedido::query()
            ->with('cliente:id,email')
            ->where('status', 'pending')
            ->whereNull('hostinger_pedido_id')
            ->whereNotNull('lote')
            ->whereBetween('created_at', [$criado->copy()->subHours(3), $criado->copy()->addMinutes(30)])
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('lote');

        foreach ($candidatos as $itens) {
            $emails = [mb_strtolower((string) $itens->first()->email_checkout), mb_strtolower((string) $itens->first()->cliente?->email)];
            $nossas = $itens->map(fn (BeautyKoraPedido $p) => (string) ($p->variante_id ?: $p->produto_id))->unique()->all();

            if (in_array($email, $emails, true) && array_diff($nossas, $variantes) === []) {
                return $itens;
            }
        }

        return null;
    }

    /**
     * @param  Collection<int, BeautyKoraPedido>  $itens
     * @param  array<string, mixed>  $pedido
     */
    private function marcar(Collection $itens, string $status, array $pedido): void
    {
        DB::transaction(function () use ($itens, $status, $pedido) {
            foreach ($itens as $item) {
                $antes = $item->status;
                $item->update([
                    'status' => $status,
                    'hostinger_pedido_id' => $pedido['id'],
                    'hostinger_numero' => $pedido['display_id'] ?? null,
                    'pago_em' => $status === 'paid' ? now() : $item->pago_em,
                ]);

                AuditLog::query()->create([
                    'user_id' => null,
                    'action' => AuditLog::ACTION_UPDATE,
                    'entity' => 'BeautyKoraPedido',
                    'entity_id' => $item->id,
                    'old_values' => ['status' => $antes],
                    'new_values' => ['status' => $status, 'origem' => 'Hostinger #'.($pedido['display_id'] ?? $pedido['id'])],
                    'created_at' => now(),
                ]);
            }
        });

        Log::info('Beauty Kora: pedido conciliado com a Hostinger', [
            'status' => $status,
            'hostinger' => $pedido['id'],
            'itens' => $itens->pluck('id')->all(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function pedidosDaHostinger(string $loja, string $pagamento): array
    {
        $pedidos = [];
        for ($pagina = 1; $pagina <= self::MAX_PAGINAS; $pagina++) {
            $resposta = $this->http()->get("/api/ecommerce/v1/stores/{$loja}/orders", [
                'payment_status' => [$pagamento],
                'created_at_from' => now()->subDays(self::DIAS)->toDateString(),
                'page' => $pagina,
            ])->throw();

            $dados = $resposta->json('data') ?? [];
            array_push($pedidos, ...$dados);

            $meta = $resposta->json('meta') ?? [];
            if ($dados === [] || ($meta['current_page'] ?? $pagina) * ($meta['per_page'] ?? PHP_INT_MAX) >= ($meta['total'] ?? 0)) {
                break;
            }
        }

        return $pedidos;
    }

    private function loja(): string
    {
        $loja = config('services.beautykora.hostinger.store_id');
        if ($loja) {
            return $loja;
        }

        $loja = $this->http()->get('/api/ecommerce/v1/stores')->throw()->json('data.0.id');
        if (! $loja) {
            throw new RuntimeException('Nenhuma loja encontrada na conta da Hostinger.');
        }

        return $loja;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.beautykora.hostinger.url'), '/'))
            ->withToken((string) config('services.beautykora.hostinger.token'))
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 1000, throw: false);
    }
}
