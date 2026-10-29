<?php

namespace App\Modules\Catalog\Support;

use App\Models\User;
use App\Modules\Catalog\Models\Review;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Modal "Avalie sua compra" que abre depois do login (pedido 2026-10-23).
 *
 * Regras (revistas pelo Lira em 2026-10-29): pedido do kazakora.com (origin
 * loja, sem site_origem) ENTREGUE, produto ainda sem avaliação do cliente. Um
 * item por vez, o mais recente primeiro, uma vez por login. "Não avaliar
 * agora" (ou fechar no X, Esc, clique fora) fica registrado e esconde aquele
 * produto por 15 dias; depois disso volta no próximo login, sempre, até o
 * cliente avaliar (sem janela de dias e sem teto de aparições). A avaliação em
 * si é gravada pelo mesmo ReviewController::store da tela "Avalie suas compras".
 */
class ModalAvaliacao
{
    public const DIAS_ADIAMENTO = 15;

    public const MAX_FOTOS = 4;

    public const MAX_FOTO_KB = 5120;

    public const MAX_COMENTARIO = 1000;

    /** Sessão: o modal vale para este login (ligado no login, apagado ao fechar). */
    private const ATIVO = 'avaliacao_modal.ativo';

    /** Sessão: produtos que já contaram como "apareceu" neste login. */
    private const MOSTRADOS = 'avaliacao_modal.mostrados';

    public static function ativar(Session $session): void
    {
        $session->put(self::ATIVO, true);
        $session->forget(self::MOSTRADOS);
    }

    public static function encerrar(Session $session): void
    {
        $session->forget(self::ATIVO);
    }

    public static function ativo(Session $session): bool
    {
        return (bool) $session->get(self::ATIVO, false);
    }

    /**
     * Próximo item a avaliar (ou null). Conta a aparição quando o produto
     * aparece pela primeira vez no login.
     *
     * @return array{produto_id:int,nome:string,imagem:?string,pedido:mixed,entregue_em:?string}|null
     */
    public function proximo(User $user, Session $session): ?array
    {
        if (! self::ativo($session)) {
            return null;
        }

        $item = $this->itensPendentes($user)->first();

        if (! $item) {
            self::encerrar($session);

            return null;
        }

        $mostrados = (array) $session->get(self::MOSTRADOS, []);

        if (! in_array($item->product_id, $mostrados, true)) {
            $this->contarAparicao($user, $item->product_id);
            $session->put(self::MOSTRADOS, [...$mostrados, $item->product_id]);
        }

        return [
            'produto_id' => (int) $item->product_id,
            'nome' => $item->product_name ?: $item->product->name,
            'imagem' => $item->product->images->first()?->thumb_url,
            'pedido' => $item->order->numero,
            'entregue_em' => $item->order->updated_at?->toIso8601String(),
        ];
    }

    /**
     * "Não avaliar agora": registra a ação, esconde o produto por 15 dias e
     * fecha o modal neste login. Só vale para produto que está mesmo pendente
     * para o cliente (nada de gravar lembrete de produto qualquer).
     */
    public function naoAvaliarAgora(User $user, int $productId, Session $session): bool
    {
        self::encerrar($session);

        if (! $this->itensPendentes($user)->contains('product_id', $productId)) {
            return false;
        }

        $chave = ['user_id' => $user->id, 'product_id' => $productId];
        $this->garantirLembrete($chave);

        DB::table('avaliacao_lembretes')->where($chave)->update([
            'vezes_nao_avaliar' => DB::raw('vezes_nao_avaliar + 1'),
            'nao_avaliar_em' => now(),
            'adiado_ate' => now()->addDays(self::DIAS_ADIAMENTO),
            'updated_at' => now(),
        ]);

        return true;
    }

    /** Lembretes cujo "Não avaliar agora" já passou dos 15 dias. */
    public function adiamentosVencidos()
    {
        return DB::table('avaliacao_lembretes')->whereNotNull('adiado_ate')->where('adiado_ate', '<=', now());
    }

    /** Rotina avaliacoes:reabrir-lembretes: devolve ao modal os adiamentos vencidos. */
    public function reabrirVencidos(): int
    {
        return $this->adiamentosVencidos()->update([
            'adiado_ate' => null,
            'reaberto_em' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Itens entregues, sem avaliação e fora de um adiamento de 15 dias, mais recente primeiro. */
    private function itensPendentes(User $user)
    {
        $avaliados = Review::query()->where('user_id', $user->id)->pluck('product_id');
        $adiados = DB::table('avaliacao_lembretes')->where('user_id', $user->id)
            ->where('adiado_ate', '>', now())->pluck('product_id');

        return OrderItem::query()
            ->whereNotNull('product_id')
            ->whereNotIn('product_id', $avaliados)
            ->whereNotIn('product_id', $adiados)
            ->whereHas('order', function ($query) use ($user) {
                $query->where('user_id', $user->id)
                    ->where('status', Order::STATUS_COMPLETED);
                Order::filtrarCanal($query, Order::ORIGIN_STORE);
            })
            ->whereHas('product', fn ($query) => $query->where('is_active', true))
            ->with([
                'order:id,external_order_id,updated_at',
                'product:id,name,slug',
                'product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
            ])
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->orderByDesc('orders.updated_at')
            ->orderByDesc('order_items.id')
            ->select('order_items.*')
            ->get()
            ->unique('product_id')
            ->values();
    }

    private function contarAparicao(User $user, int $productId): void
    {
        $chave = ['user_id' => $user->id, 'product_id' => $productId];
        $this->garantirLembrete($chave);

        DB::table('avaliacao_lembretes')->where($chave)->update([
            'aparicoes' => DB::raw('aparicoes + 1'),
            'ultima_aparicao_em' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param  array{user_id:int,product_id:int}  $chave */
    private function garantirLembrete(array $chave): void
    {
        $tabela = DB::table('avaliacao_lembretes');

        if (! (clone $tabela)->where($chave)->exists()) {
            $tabela->insertOrIgnore($chave + ['aparicoes' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
