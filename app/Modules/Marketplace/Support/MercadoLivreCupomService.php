<?php

namespace App\Modules\Marketplace\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\MercadoLivreCupom;
use App\Services\MercadoLivre\Exceptions\MercadoLivreException;
use App\Services\MercadoLivre\MercadoLivreClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cupom do vendedor no Mercado Livre (SELLER_COUPON_CAMPAIGN, sub_type
 * FIXED_PERCENTAGE). Documentação: developers.mercadolivre.com.br/pt_br/cupons-do-vendedor
 *
 * Mapeamento do modelo do Lira para a API:
 *  - percentual       -> fixed_percentage (ML aceita 5 a 80)
 *  - desconto máximo  -> max_purchase_amount ("valor máximo de reembolso", > 5 no MLB)
 *  - compra mínima    -> min_purchase_amount (padrão: 2% do ticket médio do ML)
 *  - orçamento        -> budget (obrigatório; acabou, a campanha encerra)
 *  - período          -> start_date / finish_date (máx. 31 dias)
 *
 * Criar a campanha não coloca anúncio nenhum nela: os anúncios candidatos
 * são indicados um a um depois (POST /seller-promotions/items/{id}).
 */
class MercadoLivreCupomService
{
    public function __construct(private readonly MercadoLivreClient $client) {}

    /**
     * Homolog e produção usam a mesma conta do ML: sem ML_CUPOM_ENVIO
     * explícito, só envia de verdade quando o APP_URL é a loja oficial.
     */
    public function envioHabilitado(): bool
    {
        $flag = config('mercadolivre.cupom.envio_habilitado');

        if ($flag !== null && $flag !== '') {
            return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }

        return parse_url((string) config('app.url'), PHP_URL_HOST) === 'kazakora.com';
    }

    /** @return array{valor: float, pedidos: int, dias: int} */
    public function ticketMedio(): array
    {
        $dias = (int) config('mercadolivre.cupom.ticket_dias', 30);

        $pedidos = Order::query()
            ->vendaEfetiva()
            ->where('origin', MarketplaceAccount::CHANNEL_MERCADO_LIVRE)
            ->whereIn('status', [Order::STATUS_PAID, Order::STATUS_SHIPPED, Order::STATUS_COMPLETED])
            ->where('created_at', '>=', Carbon::now()->subDays($dias)->startOfDay());

        $quantidade = (clone $pedidos)->count();

        return [
            'valor' => $quantidade > 0 ? round((float) (clone $pedidos)->avg('subtotal'), 2) : 0.0,
            'pedidos' => $quantidade,
            'dias' => $dias,
        ];
    }

    /** Valores que a tela abre preenchidos. */
    public function modelo(?array $ticket = null): array
    {
        $ticket ??= $this->ticketMedio();
        $c = config('mercadolivre.cupom');
        $hoje = Carbon::today();
        $pctTicket = (float) $c['compra_minima_pct_ticket'];

        return [
            'percentual' => (float) $c['percentual'],
            'desconto_maximo' => (float) $c['desconto_maximo'],
            'compra_minima_pct_ticket' => $pctTicket,
            'compra_minima' => self::compraMinimaPeloTicket($ticket['valor'], $pctTicket),
            'orcamento' => (float) $c['orcamento'],
            'inicio' => $hoje->toDateString(),
            'fim' => $hoje->copy()->addDays(max(1, (int) $c['duracao_dias']) - 1)->toDateString(),
            'codigo' => '',
            'nome' => sprintf('KZ %s%% ate R$%s %s', self::num($c['percentual']), self::num($c['desconto_maximo']), Carbon::now()->format('d/m H\hi')),
            'incluir_anuncios' => true,
        ];
    }

    /** Compra mínima = X% do ticket médio, nunca abaixo de R$ 1. */
    public static function compraMinimaPeloTicket(float $ticket, float $pct): float
    {
        return max(1.0, round($ticket * $pct / 100, 2));
    }

    /** Corpo exato que vai para POST /seller-promotions/promotions. */
    public function payload(array $dados): array
    {
        $payload = [
            'promotion_type' => 'SELLER_COUPON_CAMPAIGN',
            'name' => $dados['nome'],
            'sub_type' => 'FIXED_PERCENTAGE',
            'start_date' => Carbon::parse($dados['inicio'])->format('Y-m-d\T00:00:00'),
            'finish_date' => Carbon::parse($dados['fim'])->format('Y-m-d\T00:00:00'),
            'fixed_percentage' => (float) $dados['percentual'],
            'min_purchase_amount' => round((float) $dados['compra_minima'], 2),
            'max_purchase_amount' => round((float) $dados['desconto_maximo'], 2),
            'budget' => round((float) $dados['orcamento'], 2),
        ];

        if (! empty($dados['codigo'])) {
            $payload['partial_coupon_code'] = strtoupper($dados['codigo']);
        }

        return $payload;
    }

    /**
     * Cria a campanha no ML (uma chamada só, sem repetir) e, se pedido,
     * indica os anúncios candidatos. Tudo fica registrado em
     * mercado_livre_cupons e no log do ML.
     */
    public function criar(array $dados, ?User $user): MercadoLivreCupom
    {
        if (! $this->envioHabilitado()) {
            throw new \RuntimeException('Envio de cupom ao Mercado Livre está desligado neste ambiente.');
        }

        $payload = $this->payload($dados);
        $registro = MercadoLivreCupom::create([
            'user_id' => $user?->id,
            'nome' => $payload['name'],
            'status' => MercadoLivreCupom::STATUS_ERRO,
            'payload' => $payload,
        ]);

        try {
            $resposta = $this->client->postOnce('/seller-promotions/promotions?app_version=v2', $payload);
        } catch (MercadoLivreException $e) {
            $registro->update(['erro' => $e->getMessage(), 'resposta' => $e->context['body'] ?? null]);
            $this->log('mercadolivre.cupom.erro', $registro, $user);

            throw $e;
        }

        $registro->update([
            'status' => MercadoLivreCupom::STATUS_CRIADO,
            'ml_promotion_id' => $resposta['id'] ?? null,
            'resposta' => $resposta,
        ]);

        if (! empty($dados['incluir_anuncios']) && ! empty($resposta['id'])) {
            $this->incluirCandidatos($registro, (string) $resposta['id']);
        }

        $this->log('mercadolivre.cupom.criado', $registro, $user);

        return $registro->fresh();
    }

    private function incluirCandidatos(MercadoLivreCupom $registro, string $promocaoId): void
    {
        $adicionados = 0;
        $erros = [];

        try {
            $candidatos = $this->candidatos($promocaoId);
        } catch (Throwable $e) {
            $registro->update(['erro' => 'Cupom criado, mas não consegui listar os anúncios candidatos: '.$e->getMessage()]);

            return;
        }

        foreach ($candidatos as $itemId) {
            try {
                $this->client->post("/seller-promotions/items/{$itemId}?app_version=v2", [
                    'promotion_id' => $promocaoId,
                    'promotion_type' => 'SELLER_COUPON_CAMPAIGN',
                ]);
                $adicionados++;
            } catch (Throwable $e) {
                $erros[] = "{$itemId}: {$e->getMessage()}";
            }
        }

        $registro->update([
            'itens_adicionados' => $adicionados,
            'itens_com_erro' => count($erros),
            'erro' => $erros ? mb_substr(implode("\n", $erros), 0, 5000) : null,
        ]);
    }

    /** @return list<string> ids dos anúncios candidatos da campanha */
    private function candidatos(string $promocaoId): array
    {
        $ids = [];
        $offset = 0;
        $searchAfter = null;

        for ($pagina = 0; $pagina < 40; $pagina++) {
            $query = ['promotion_type' => 'SELLER_COUPON_CAMPAIGN', 'app_version' => 'v2', 'limit' => 50];
            $searchAfter ? $query['search_after'] = $searchAfter : $query['offset'] = $offset;

            $resposta = $this->client->get("/seller-promotions/promotions/{$promocaoId}/items", $query);
            $resultados = $resposta['results'] ?? [];

            foreach ($resultados as $item) {
                if (! empty($item['id']) && in_array($item['status'] ?? 'candidate', ['candidate', 'candidated'], true)) {
                    $ids[$item['id']] = true;
                }
            }

            $searchAfter = $resposta['paging']['searchAfter'] ?? $resposta['paging']['search_after'] ?? null;
            $offset += 50;
            $total = (int) ($resposta['paging']['total'] ?? 0);

            if ($resultados === [] || (! $searchAfter && $offset >= $total)) {
                break;
            }
        }

        return array_keys($ids);
    }

    private function log(string $evento, MercadoLivreCupom $registro, ?User $user): void
    {
        Log::channel(config('mercadolivre.log_channel'))->info($evento, [
            'cupom_id' => $registro->id,
            'ml_promotion_id' => $registro->ml_promotion_id,
            'usuario' => $user ? "{$user->id} {$user->email}" : null,
            'payload' => $registro->payload,
            'resposta' => $registro->resposta,
            'itens_adicionados' => $registro->itens_adicionados,
            'itens_com_erro' => $registro->itens_com_erro,
            'erro' => $registro->erro,
        ]);
    }

    private static function num(mixed $valor): string
    {
        $v = (float) $valor;

        return $v == floor($v) ? (string) (int) $v : str_replace('.', ',', (string) $v);
    }
}
