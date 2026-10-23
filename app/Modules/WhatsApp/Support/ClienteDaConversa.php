<?php

namespace App\Modules\WhatsApp\Support;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use Illuminate\Support\Collection;

/**
 * Quem é o cliente de uma conversa do WhatsApp e quais as vendas dele (pedido
 * do Lira 2026-10-23: a tela de Conversas mostrava só o nome do perfil e o
 * número, sem dizer de qual venda era o contato).
 *
 * Primeiro valem os pedidos citados nas próprias mensagens (payload.order_id,
 * gravado pela confirmação, pelo rastreio e pelas recuperações de Pix); depois,
 * os pedidos e o cadastro com o mesmo telefone, comparado sem o nono dígito
 * (WhatsAppPhone::mesmoNumero), porque a Meta manda muito número sem ele.
 */
class ClienteDaConversa
{
    private const MAX_PEDIDOS = 5;

    /** Candidatos por telefone: o LIKE só pré-filtra, quem decide é mesmoNumero. */
    private const MAX_CANDIDATOS = 200;

    /**
     * @return array{nome: ?string, userId: ?int, documento: ?string, loja: ?array, pedidos: array<int, array<string, mixed>>}|null
     */
    public function identificar(WhatsAppConversation $conversation): ?array
    {
        // Venda escolhida ao iniciar a conversa pelo "+" fica logo depois das citadas.
        $escolhido = $conversation->metadata['pedido_escolhido'] ?? null;
        $primeiros = $this->pedidosCitados($conversation)
            ->concat($escolhido ? Order::query()->with('user')->whereKey($escolhido)->get() : []);

        return $this->montar($primeiros, $conversation->wa_id ?? $conversation->phone);
    }

    /**
     * Mesma ficha a partir de um telefone digitado na "Nova conversa" (pedido
     * do Lira 2026-10-23). O pedido buscado pelo número vem primeiro.
     */
    public function porTelefone(?string $telefone, ?Order $pedido = null): ?array
    {
        return $this->montar(collect(array_filter([$pedido])), $telefone);
    }

    /**
     * Loja do cliente pela venda principal: FerraKora e Beauty Kora pelo site
     * de origem; o resto (kazakora.com, Shopee, ML, TikTok, Amazon) é KazaKora.
     *
     * @return array{chave: string, nome: string, site: string}
     */
    public static function loja(?string $chave): array
    {
        $sites = [
            Order::SITE_FERRAKORA => ['FerraKora', config('services.ferrakora.url', 'https://ferrakora.devlira.com.br')],
            Order::SITE_BEAUTYKORA => ['Beauty Kora', 'https://'.explode(',', (string) config('services.beautykora.origens', 'beautykora.devlira.com.br'))[0]],
        ];
        [$nome, $site] = $sites[$chave] ?? ['KazaKora', 'https://kazakora.com'];

        return [
            'chave' => isset($sites[$chave]) ? $chave : WhatsAppNumeros::KAZAKORA,
            'nome' => $nome,
            'site' => (string) (parse_url((string) $site, PHP_URL_HOST) ?: $site),
        ];
    }

    public static function lojaDoPedido(?Order $order): string
    {
        return $order?->origin === Order::ORIGIN_STORE && in_array($order->site_origem, Order::SITES, true)
            ? $order->site_origem
            : WhatsAppNumeros::KAZAKORA;
    }

    /** @param  Collection<int, Order>  $primeiros */
    private function montar(Collection $primeiros, ?string $waId): ?array
    {
        $pedidos = $primeiros
            ->concat($this->pedidosPeloTelefone($waId))
            ->unique('id')
            ->take(self::MAX_PEDIDOS)
            ->values();

        $user = $pedidos->first()?->user ?? $this->usuarioPeloTelefone($waId);

        if ($pedidos->isEmpty() && ! $user) {
            return null;
        }

        $principal = $pedidos->first();

        return [
            'nome' => $user?->name ?: $principal?->shipping_name,
            'userId' => $user?->id,
            'documento' => $principal?->buyer_document ?: $user?->cpf,
            // Cadastro sem venda é do kazakora.com (FerraKora tem cadastro próprio).
            'loja' => self::loja(self::lojaDoPedido($principal)),
            'pedidos' => $pedidos->map(fn (Order $o) => [
                'id' => $o->id,
                'numero' => $o->numero,
                'status' => $o->status,
                'canal' => Order::rotuloDoCanal($o->origin, $o->site_origem),
                'total' => (float) $o->total,
                'criadoEm' => $o->created_at?->toISOString(),
            ])->all(),
        ];
    }

    /**
     * Guarda o pedido principal e o nome na conversa, para a lista mostrar sem
     * consultar pedido a pedido. Não mexe no updated_at (a lista atualiza por ele).
     */
    public function lembrar(WhatsAppConversation $conversation, ?array $cliente): void
    {
        $metadata = $conversation->metadata ?? [];
        $novo = array_merge($metadata, [
            'pedido_id' => $cliente['pedidos'][0]['id'] ?? null,
            'cliente_nome' => $cliente['nome'] ?? null,
            'cliente_loja' => $cliente['loja']['chave'] ?? null,
        ]);

        if ($novo === $metadata) {
            return;
        }

        $conversation->metadata = $novo;
        WhatsAppConversation::withoutTimestamps(fn () => $conversation->saveQuietly());
    }

    /** @return Collection<int, Order> */
    private function pedidosCitados(WhatsAppConversation $conversation): Collection
    {
        $ids = WhatsAppMessage::query()
            ->where('conversation_id', $conversation->id)
            ->whereNotNull('payload->order_id')
            ->orderByDesc('id')
            ->limit(50)
            ->pluck('payload')
            ->map(fn ($payload) => (int) (is_array($payload) ? ($payload['order_id'] ?? 0) : 0))
            ->filter()
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return collect();
        }

        $pedidos = Order::query()->with('user')->whereIn('id', $ids)->get()->keyBy('id');

        return $ids->map(fn ($id) => $pedidos->get($id))->filter()->values();
    }

    /** @return Collection<int, Order> */
    private function pedidosPeloTelefone(?string $waId): Collection
    {
        $final = $this->finalDoNumero($waId);
        if ($final === null) {
            return collect();
        }

        return Order::query()
            ->with('user')
            ->where(fn ($q) => $q
                ->where('shipping_phone', 'like', "%{$final}")
                ->orWhere('shipping_whatsapp', 'like', "%{$final}")
                ->orWhereHas('user', fn ($u) => $u->where('phone', 'like', "%{$final}")))
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATOS)
            ->get()
            ->filter(fn (Order $o) => WhatsAppPhone::mesmoNumero($waId, [$o->shipping_whatsapp, $o->shipping_phone, $o->user?->phone]))
            ->values();
    }

    private function usuarioPeloTelefone(?string $waId): ?User
    {
        $final = $this->finalDoNumero($waId);
        if ($final === null) {
            return null;
        }

        return User::query()
            ->where('phone', 'like', "%{$final}")
            ->orderByDesc('id')
            ->limit(self::MAX_CANDIDATOS)
            ->get()
            ->first(fn (User $u) => WhatsAppPhone::mesmoNumero($waId, [$u->phone]));
    }

    /** Últimos 4 dígitos: ficam juntos em qualquer formatação ("(11) 98296-0015"). */
    private function finalDoNumero(?string $waId): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $waId);

        return strlen($digitos) >= 10 ? substr($digitos, -4) : null;
    }
}
