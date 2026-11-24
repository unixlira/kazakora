<?php

namespace App\Modules\Catalog\Support;

use App\Modules\Catalog\Models\Review;
use App\Modules\Catalog\Models\ReviewImage;
use App\Modules\Checkout\Mail\PedidoAvaliacao;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\WhatsApp\Models\WhatsAppConversation;
use App\Modules\WhatsApp\Models\WhatsAppMessage;
use App\Modules\WhatsApp\Services\WhatsAppCloudApiClient;
use App\Modules\WhatsApp\Services\WhatsAppModelos;
use App\Modules\WhatsApp\Support\WhatsAppNumeros;
use App\Modules\WhatsApp\Support\WhatsAppPhone;
use App\Modules\WhatsApp\Support\WhatsAppSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pedido de avaliação depois da entrega (pedido do Lira 2026-11-23): a nota
 * dos produtos tem que vir de quem comprou de verdade. Nada de avaliação
 * inventada ou copiada de outra loja.
 *
 * Pedido do site (KazaKora ou FerraKora) entregue há 5 dias ou mais recebe um
 * link assinado (30 dias) por e-mail e, se houver modelo aprovado na Meta, por
 * WhatsApp. No link o cliente dá de 1 a 5 estrelas, comentário e fotos para
 * cada produto. A avaliação fica ligada ao pedido (compra verificada) e é
 * publicada direto, como as do site: nota baixa também, a nota tem que ser
 * honesta.
 */
class PedidoDeAvaliacao
{
    public const ROTA = 'avaliacao-pedido.ver';

    /** Caminho do link; o modelo do WhatsApp usa https://kazakora.com/avaliar-pedido/{{1}}. */
    public const CAMINHO = '/avaliar-pedido/';

    public function __construct(
        private readonly WhatsAppCloudApiClient $whatsapp,
        private readonly WhatsAppSettings $settings,
        private readonly ClubeDeAvaliacoes $clube,
    ) {
    }

    /** Pedidos na vez: do site, entregues há `dias` (até `max_dias`), sem pedido de avaliação, sem problema. */
    public static function naVez(): Builder
    {
        $dias = max(0, (int) config('loja.pedido_avaliacao.dias', 5));
        $maxDias = max($dias, (int) config('loja.pedido_avaliacao.max_dias', 60));

        return Order::query()
            ->where('origin', Order::ORIGIN_STORE)
            ->where(fn ($q) => $q->whereNull('site_origem')->orWhere('site_origem', Order::SITE_FERRAKORA))
            ->where('status', Order::STATUS_COMPLETED)
            ->whereNull('pedido_avaliacao_enviado_em')
            ->whereNotNull('entregue_em')
            ->where('entregue_em', '<=', now()->subDays($dias))
            ->where('entregue_em', '>=', now()->subDays($maxDias))
            ->semAmostra()
            // Devolvido, em disputa ou "não recebi": não pede avaliação.
            ->whereNull('disputed_at')
            ->whereNull('stock_restored_at')
            ->whereNull('whatsapp_nao_recebi_em')
            ->whereDoesntHave('marketplaceClaims')
            ->whereHas('items', fn ($q) => $q->whereNotNull('product_id'))
            ->orderBy('entregue_em');
    }

    public static function link(Order $order, ?\DateTimeInterface $expira = null): string
    {
        return URL::temporarySignedRoute(
            self::ROTA,
            $expira ?? now()->addDays((int) config('loja.pedido_avaliacao.validade_dias', 30)),
            ['pedido' => $order->id],
        );
    }

    /** Parte do link depois de /avaliar-pedido/ (variável do botão URL do modelo). */
    public static function sufixo(string $link): string
    {
        return Str::after($link, self::CAMINHO);
    }

    public static function ferrakora(Order $order): bool
    {
        return $order->site_origem === Order::SITE_FERRAKORA;
    }

    public static function primeiroNome(Order $order): string
    {
        $nome = trim((string) ($order->shipping_name ?: $order->user?->name ?: $order->ferrakoraCliente?->name));

        return Str::of($nome)->explode(' ')->first() ?: 'cliente';
    }

    /** Itens avaliáveis do pedido, um por produto. */
    public static function itens(Order $order): Collection
    {
        return $order->items()
            ->whereNotNull('product_id')
            ->with([
                'product' => fn ($q) => $q->withTrashed(),
                'product.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
                'product.parent.images' => fn ($q) => $q->orderByDesc('is_primary')->orderBy('position'),
            ])
            ->orderBy('id')
            ->get()
            ->filter(fn (OrderItem $item) => $item->product !== null)
            ->unique('product_id')
            ->values();
    }

    public static function nomeDoItem(OrderItem $item): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) ($item->product_name ?: $item->product?->name ?: 'produto')));
    }

    /** {{2}} do modelo: "Produto" ou "Produto e mais 2 produtos". */
    public static function textoDosProdutos(Collection $itens): string
    {
        $primeiro = Str::limit(self::nomeDoItem($itens->first()), 60, '…');
        $outros = $itens->count() - 1;

        return match (true) {
            $outros <= 0 => $primeiro,
            $outros === 1 => "{$primeiro} e mais 1 produto",
            default => "{$primeiro} e mais {$outros} produtos",
        };
    }

    public static function email(Order $order): ?string
    {
        $email = $order->user?->email ?: ($order->shipping_email ?: $order->ferrakoraCliente?->email);

        return filled($email) ? (string) $email : null;
    }

    public static function telefone(Order $order): ?string
    {
        return WhatsAppPhone::normalize($order->shipping_whatsapp ?: $order->shipping_phone ?: $order->user?->phone ?: $order->ferrakoraCliente?->phone);
    }

    /** Modelo do WhatsApp da loja do pedido, ou null (sai só o e-mail). FerraKora só pelo número dela. */
    public function modelo(Order $order): ?array
    {
        $loja = self::ferrakora($order) ? WhatsAppNumeros::FERRAKORA : WhatsAppNumeros::KAZAKORA;
        $nome = WhatsAppNumeros::template($loja, 'pedido_avaliacao');

        if (! filled($nome) || WhatsAppNumeros::normalizar($loja) !== $loja || ! $this->settings->isReadyToSend($loja)) {
            return null;
        }

        return ['loja' => $loja, 'nome' => $nome, 'idioma' => (string) WhatsAppNumeros::template($loja, 'pedido_avaliacao_idioma', 'pt_BR')];
    }

    /** @return list<string> canais que sairiam agora */
    public function canais(Order $order): array
    {
        return array_values(array_filter([
            self::email($order) ? 'email' : null,
            self::telefone($order) && $this->modelo($order) ? 'whatsapp' : null,
        ]));
    }

    /** @return string enviado|ja_enviado|sem_canal|falhou */
    public function enviar(Order $order): string
    {
        $order->loadMissing(['user', 'ferrakoraCliente']);
        $canais = $this->canais($order);
        $itens = self::itens($order);

        if ($canais === [] || $itens->isEmpty()) {
            return 'sem_canal';
        }

        // Trava: só quem preenche a marca envia (rodada repetida não duplica).
        $marcado = Order::query()->whereKey($order->id)->whereNull('pedido_avaliacao_enviado_em')
            ->update(['pedido_avaliacao_enviado_em' => now()]);
        if ($marcado !== 1) {
            return 'ja_enviado';
        }

        $link = self::link($order);
        $ok = false;

        foreach ($canais as $canal) {
            try {
                if ($canal === 'email') {
                    Mail::to(self::email($order))->send(new PedidoAvaliacao($order, $itens, $link));
                } else {
                    $this->mandarWhatsApp($order, $itens, $link);
                }
                $ok = true;
            } catch (Throwable $e) {
                Log::warning('pedido_avaliacao_falhou', ['order_id' => $order->id, 'canal' => $canal, 'erro' => $e->getMessage()]);
            }
        }

        if (! $ok) {
            // Nenhum canal saiu: tenta de novo na próxima rodada.
            Order::query()->whereKey($order->id)->update(['pedido_avaliacao_enviado_em' => null]);

            return 'falhou';
        }

        return 'enviado';
    }

    private function mandarWhatsApp(Order $order, Collection $itens, string $link): void
    {
        $modelo = $this->modelo($order);
        $telefone = self::telefone($order);
        $corpo = [self::primeiroNome($order), self::textoDosProdutos($itens)];
        $sufixo = self::sufixo($link);

        $resposta = $this->whatsapp->paraLoja($modelo['loja'])->sendTemplate(
            $telefone,
            $modelo['nome'],
            $modelo['idioma'],
            bodyParams: $corpo,
            urlButtonParams: [0 => $sufixo],
        );

        // Conversa: o envio aparece na tela de Conversas, com o balão do modelo.
        try {
            $conversa = WhatsAppConversation::paraNumero($telefone, $modelo['loja'], [
                'phone' => $telefone,
                'profile_name' => $order->shipping_name,
                'last_message_at' => now(),
                'unread_count' => 0,
            ]);
            $mensagem = WhatsAppMessage::query()->create([
                'conversation_id' => $conversa->id,
                'wa_message_id' => $resposta['messages'][0]['id'] ?? null,
                'direction' => 'outbound',
                'type' => 'template',
                'body' => "Pedido de avaliação do pedido #{$order->numero}: {$corpo[1]} {$link}",
                'status' => 'sent',
                'sent_by' => 'system',
                'payload' => WhatsAppModelos::payloadDoEnvio($modelo['nome'], $modelo['idioma'], $corpo, null, [0 => $sufixo])
                    + ['order_id' => $order->id],
                'sent_at' => now(),
            ]);
            $conversa->registerMessage($mensagem);
        } catch (Throwable $e) {
            Log::info('pedido_avaliacao_conversa_nao_registrada', ['order_id' => $order->id, 'erro' => $e::class]);
        }
    }

    /** Avaliação já feita deste produto por quem fez o pedido (conta do site, conta FerraKora ou o próprio pedido). */
    public static function avaliacaoExistente(Order $order, int $productId): ?Review
    {
        $query = Review::query()->where('product_id', $productId);

        if ($order->user_id) {
            return $query->where('user_id', $order->user_id)->first();
        }

        if ($order->ferrakora_cliente_id) {
            return $query->where('ferrakora_cliente_id', $order->ferrakora_cliente_id)->first();
        }

        return $query->where('order_id', $order->id)->first();
    }

    /**
     * Grava as notas do link. $avaliacoes: product_id => [nota, comentario];
     * $fotos: product_id => UploadedFile[]. Produto sem nota fica de fora.
     *
     * @return int quantas avaliações gravou
     */
    public function avaliar(Order $order, array $avaliacoes, array $fotos = []): int
    {
        $order->loadMissing(['user', 'ferrakoraCliente']);
        $gravadas = 0;

        foreach (self::itens($order) as $item) {
            $dados = $avaliacoes[$item->product_id] ?? null;
            $nota = (int) ($dados['nota'] ?? 0);
            if ($nota < 1 || $nota > 5) {
                continue;
            }

            $review = self::avaliacaoExistente($order, (int) $item->product_id) ?? new Review;
            $review->forceFill([
                'user_id' => $order->user_id,
                'ferrakora_cliente_id' => $order->user_id ? null : $order->ferrakora_cliente_id,
                'order_id' => $order->id,
                'product_id' => $item->product_id,
                'reviewer_name' => $order->user ? null : (trim((string) ($order->ferrakoraCliente?->name ?: $order->shipping_name)) ?: null),
                'rating' => $nota,
                'comment' => filled($dados['comentario'] ?? null) ? trim((string) $dados['comentario']) : null,
                'ocultar_nome' => false,
                'uf' => $order->shipping_state ?: null,
            ])->save();

            $this->guardarFotos($review, $fotos[$item->product_id] ?? []);
            $gravadas++;
        }

        // Clube de Avaliações: cliente com conta no site ganha o cupom do nível na hora.
        if ($gravadas > 0 && $order->user) {
            rescue(fn () => $this->clube->conceder($order->user), null, false);
        }

        return $gravadas;
    }

    /** @param  array<int, UploadedFile>  $fotos */
    private function guardarFotos(Review $review, array $fotos): void
    {
        $vagas = max(0, ModalAvaliacao::MAX_FOTOS - $review->images()->count());
        $posicao = (int) $review->images()->max('position');

        foreach (array_slice(array_values($fotos), 0, $vagas) as $foto) {
            $extensao = match ($foto->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $caminho = $foto->storeAs("avaliacoes/{$review->id}", Str::random(32).'.'.$extensao, 'public');
            if (! $caminho) {
                continue;
            }
            $caminho = ProductImageOptimizer::optimize($caminho) ?? $caminho;

            ReviewImage::create(['review_id' => $review->id, 'image_url' => asset('storage/'.$caminho), 'position' => ++$posicao]);
        }
    }
}
