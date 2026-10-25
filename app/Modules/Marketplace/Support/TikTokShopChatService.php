<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use App\Services\TikTokShop\TikTokShopClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Chat dos compradores do TikTok Shop na aba "TikTok Shop" de Perguntas MK
 * Place (pedido do Lira 2026-10-25), pela Customer Service API do app próprio.
 * Lê conversas e mensagens e espelha em marketplace_chat_*; só envia quando o
 * atendente clica. A loja tem 7 dias para responder no TikTok.
 */
class TikTokShopChatService
{
    public const LIMITE_TEXTO = 2000;

    public function __construct(private readonly TikTokShopClient $api) {}

    private function base(): string
    {
        return '/customer_service/'.config('services.tiktok_shop.versao_atendimento', '202309');
    }

    /** @return array{conversas: int, mensagens: int, falhas: int} */
    public function sincronizar(int $paginas = 1, int $maximoConversas = 20): array
    {
        $r = ['conversas' => 0, 'mensagens' => 0, 'falhas' => 0];
        $token = null;
        $buscar = [];

        for ($p = 0; $p < max(1, $paginas); $p++) {
            $dados = $this->api->chamar('GET', $this->base().'/conversations', ['page_size' => 20, 'page_token' => $token, 'locale' => 'pt-BR']);
            foreach ($dados['conversations'] ?? [] as $c) {
                $conversa = $this->gravarConversa($c);
                $r['conversas']++;
                if ($conversa->mensagens_sync_id !== ($c['latest_message']['id'] ?? null)) {
                    $buscar[] = [$conversa, $c['latest_message']['id'] ?? null];
                }
            }
            $token = $dados['next_page_token'] ?? null;
            if (! $token) {
                break;
            }
        }

        foreach (array_slice($buscar, 0, $maximoConversas) as [$conversa, $ultimaId]) {
            try {
                $r['mensagens'] += $this->copiarMensagens($conversa);
                $conversa->update(['mensagens_sync_id' => $ultimaId]);
            } catch (Throwable $e) {
                $r['falhas']++;
                Log::channel('tiktok')->warning('tiktok.chat.falha', ['conversa' => $conversa->externo_id, 'erro' => $e->getMessage()]);
            }
        }

        return $r;
    }

    public function enviar(MarketplaceChatConversa $conversa, string $texto, int $userId): MarketplaceChatMensagem
    {
        $dados = $this->api->chamar('POST', $this->base().'/conversations/'.$conversa->externo_id.'/messages', [], [
            'type' => 'TEXT',
            'content' => json_encode(['content' => $texto], JSON_UNESCAPED_UNICODE),
        ]);

        $mensagem = $conversa->mensagens()->create([
            'canal' => MarketplaceChatConversa::TIKTOK,
            'externo_id' => (string) ($dados['message_id'] ?? 'enviada-'.Str::uuid()),
            'tipo' => 'text',
            'do_cliente' => false,
            'texto' => $texto,
            'enviada_em' => now(),
            'enviada_por_user_id' => $userId,
        ]);
        $conversa->update(['ultima_mensagem_texto' => Str::limit($texto, 300), 'ultima_do_cliente' => false, 'ultima_mensagem_em' => now(), 'nao_lidas' => 0]);

        return $mensagem;
    }

    public function marcarLida(MarketplaceChatConversa $conversa): void
    {
        $this->api->chamar('POST', $this->base().'/conversations/'.$conversa->externo_id.'/messages/read', [], []);
        $conversa->update(['nao_lidas' => 0]);
    }

    /** @param array<string, mixed> $c */
    private function gravarConversa(array $c): MarketplaceChatConversa
    {
        $comprador = collect($c['participants'] ?? [])->firstWhere('role', 'BUYER') ?? [];
        $ultima = $c['latest_message'] ?? [];

        $conversa = MarketplaceChatConversa::query()->firstOrNew(['canal' => MarketplaceChatConversa::TIKTOK, 'externo_id' => (string) $c['id']]);
        $conversa->fill([
            'comprador_nome' => $comprador['nickname'] ?? $conversa->comprador_nome,
            'comprador_avatar' => $comprador['avatar'] ?? $conversa->comprador_avatar,
            'nao_lidas' => (int) ($c['unread_count'] ?? 0),
            'sincronizada_em' => now(),
        ]);
        if ($ultima) {
            $conversa->fill([
                'ultima_mensagem_texto' => Str::limit(self::textoDa($ultima), 300),
                'ultima_do_cliente' => ($ultima['sender']['role'] ?? 'BUYER') === 'BUYER',
                'ultima_mensagem_em' => self::quando($ultima['create_time'] ?? null),
            ]);
        }
        $conversa->save();

        return $conversa;
    }

    private function copiarMensagens(MarketplaceChatConversa $conversa): int
    {
        $novas = 0;
        $token = null;
        for ($p = 0; $p < 3; $p++) {
            $dados = $this->api->chamar('GET', $this->base().'/conversations/'.$conversa->externo_id.'/messages', ['page_size' => 10, 'sort_order' => 'DESC', 'page_token' => $token, 'locale' => 'pt-BR']);
            $lista = $dados['messages'] ?? [];
            $jaTinha = false;
            foreach ($lista as $m) {
                if (($m['is_visible'] ?? true) === false) {
                    continue;
                }
                $existe = MarketplaceChatMensagem::query()->where('canal', MarketplaceChatConversa::TIKTOK)->where('externo_id', (string) $m['id'])->exists();
                if ($existe) {
                    $jaTinha = true;

                    continue;
                }
                $conversa->mensagens()->create([
                    'canal' => MarketplaceChatConversa::TIKTOK,
                    'externo_id' => (string) $m['id'],
                    'tipo' => strtolower((string) ($m['type'] ?? 'text')),
                    'do_cliente' => ($m['sender']['role'] ?? 'BUYER') === 'BUYER',
                    'texto' => self::textoDa($m),
                    'conteudo' => self::conteudo($m),
                    'enviada_em' => self::quando($m['create_time'] ?? null),
                ]);
                $novas++;
            }
            $token = $dados['next_page_token'] ?? null;
            if ($jaTinha || ! $token || ! $lista) {
                break;
            }
        }

        return $novas;
    }

    /** @param array<string, mixed> $m */
    private static function conteudo(array $m): array
    {
        $c = $m['content'] ?? null;

        return is_string($c) ? (json_decode($c, true) ?: ['bruto' => $c]) : (array) $c;
    }

    /** @param array<string, mixed> $m */
    public static function textoDa(array $m): string
    {
        $c = self::conteudo($m);

        return match (strtoupper((string) ($m['type'] ?? 'TEXT'))) {
            'TEXT' => (string) ($c['content'] ?? ''),
            'IMAGE' => '[imagem]',
            'VIDEO' => '[vídeo]',
            'PRODUCT_CARD' => '[produto] '.($c['product_name'] ?? $c['product_id'] ?? ''),
            'ORDER_CARD' => '[pedido] '.($c['order_id'] ?? ''),
            'EMOTICONS' => '[figurinha]',
            default => (string) ($c['content'] ?? '['.strtolower((string) ($m['type'] ?? 'mensagem')).']'),
        };
    }

    private static function quando(mixed $ts): ?Carbon
    {
        if (! $ts) {
            return null;
        }
        $ts = (int) $ts;
        // A API devolve segundos; alguns campos vêm em milissegundos.
        if ($ts > 20000000000) {
            $ts = intdiv($ts, 1000);
        }

        return Carbon::createFromTimestamp($ts, config('app.timezone'));
    }
}
