<?php

namespace App\Modules\Marketplace\Support;

use App\Modules\Marketplace\Models\ShopeeChatConversa;
use App\Modules\Marketplace\Models\ShopeeChatMensagem;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Chat da Shopee (sellerchat) no admin. Lê as conversas e mensagens pela API e
 * espelha em shopee_chat_*; só envia mensagem quando o atendente pede na tela
 * (enviar). Nunca responde sozinho.
 */
class ShopeeChatService
{
    private const LISTA = '/api/v2/sellerchat/get_conversation_list';

    private const MENSAGENS = '/api/v2/sellerchat/get_message';

    private const ENVIAR = '/api/v2/sellerchat/send_message';

    private const LER = '/api/v2/sellerchat/read_conversation';

    public const LIMITE_TEXTO = 1000;

    /** Páginas de mensagens por conversa e de conversas por listagem. */
    private const PAGINAS_MENSAGENS = 3;

    private const PAGINAS_LISTA = 3;

    public function __construct(private readonly ShopeeClient $client) {}

    /**
     * Um passe de sincronização. Tolerante a falha parcial: erro numa conversa
     * não derruba as outras; limite de requisições da Shopee interrompe o passe
     * sem erro (o próximo, 2 min depois, continua do que ficou).
     *
     * @return array{conversas: int, mensagens: int, falhas: int, limitado: bool, lista_ok: bool}
     */
    public function sincronizar(int $paginasRecentes = 1, int $maximoConversas = 20): array
    {
        $r = ['conversas' => 0, 'mensagens' => 0, 'falhas' => 0, 'limitado' => false, 'lista_ok' => true];

        // 1) Não lidas (todas) e as mais recentes (para pegar resposta da loja e conversa nova).
        $naoLidasIds = [];
        $naoLidasCompleta = false;
        try {
            $naoLidasIds = $this->listar('unread', self::PAGINAS_LISTA, $r, $naoLidasCompleta);
        } catch (Throwable $e) {
            $this->falhou('lista_nao_lidas', $e, $r);
            $r['lista_ok'] = false;
        }

        if (! $r['limitado']) {
            try {
                $this->listar('all', $paginasRecentes, $r);
            } catch (Throwable $e) {
                $this->falhou('lista_recentes', $e, $r);
                $r['lista_ok'] = $r['lista_ok'] && ! empty($naoLidasIds);
            }
        }

        // Já lida no app da Shopee: zera o contador local. Só com a lista completa.
        if ($naoLidasCompleta) {
            ShopeeChatConversa::query()->comNaoLidas()->whereNotIn('conversation_id', $naoLidasIds)->update(['nao_lidas' => 0]);
        }

        // 2) Mensagens das conversas com novidade, não lidas primeiro.
        $pendentes = ShopeeChatConversa::query()
            ->whereColumn('ultima_mensagem_id', '!=', 'mensagens_sync_id')
            ->orWhereNull('mensagens_sync_id')
            ->orderByDesc('nao_lidas')->orderByDesc('ultima_mensagem_em')
            ->limit($maximoConversas)
            ->get();

        foreach ($pendentes as $conversa) {
            if ($r['limitado']) {
                break;
            }
            try {
                $r['mensagens'] += $this->sincronizarMensagens($conversa);
                $r['conversas']++;
            } catch (Throwable $e) {
                $this->falhou('mensagens', $e, $r, ['conversa' => $conversa->conversation_id]);
            }
        }

        return $r;
    }

    /**
     * Copia as mensagens novas de uma conversa (da mais nova para trás até achar
     * uma que já temos). Devolve quantas entraram.
     */
    public function sincronizarMensagens(ShopeeChatConversa $conversa): int
    {
        $novas = 0;
        $offset = null;
        $achouConhecida = false;

        for ($pagina = 0; $pagina < self::PAGINAS_MENSAGENS && ! $achouConhecida; $pagina++) {
            $resp = $this->client->get(self::MENSAGENS, array_filter([
                'conversation_id' => $conversa->conversation_id,
                'page_size' => 50,
                'offset' => $offset,
            ]))['response'] ?? [];

            $mensagens = $resp['messages'] ?? [];

            foreach ($mensagens as $m) {
                $existente = ShopeeChatMensagem::query()->where('message_id', (string) $m['message_id'])->first();
                if ($existente) {
                    $achouConhecida = true;

                    continue;
                }
                ShopeeChatMensagem::create($this->atributosMensagem($conversa, $m));
                $novas++;
            }

            $offset = $resp['page_result']['next_offset'] ?? null;
            if (! $offset || ! $mensagens) {
                break;
            }
        }

        $conversa->update(['mensagens_sync_id' => $conversa->ultima_mensagem_id, 'sincronizada_em' => now()]);

        return $novas;
    }

    /**
     * Resposta do atendente ao cliente. SÓ chamar por ação explícita na tela.
     */
    public function enviar(ShopeeChatConversa $conversa, string $texto, int $userId): ShopeeChatMensagem
    {
        $texto = trim($texto);
        if ($texto === '' || mb_strlen($texto) > self::LIMITE_TEXTO) {
            throw new ShopeeException('Escreva uma mensagem de até '.self::LIMITE_TEXTO.' caracteres.');
        }

        $resp = $this->client->post(self::ENVIAR, [
            'to_id' => (int) $conversa->comprador_id,
            'message_type' => 'text',
            'content' => ['text' => $texto],
        ])['response'] ?? [];

        $id = (string) ($resp['message_id'] ?? 'local-'.uniqid());

        $mensagem = ShopeeChatMensagem::updateOrCreate(['message_id' => $id], [
            'conversa_id' => $conversa->id,
            'tipo' => 'text',
            'do_cliente' => false,
            'texto' => $texto,
            'conteudo' => ['text' => $texto],
            'origem' => 'admin',
            'enviada_em' => isset($resp['created_timestamp']) ? Carbon::createFromTimestamp((int) $resp['created_timestamp']) : now(),
            'enviada_por_user_id' => $userId,
        ]);

        $conversa->update([
            'nao_lidas' => 0,
            'ultima_mensagem_tipo' => 'text',
            'ultima_mensagem_texto' => $texto,
            'ultima_do_cliente' => false,
            'ultima_mensagem_em' => $mensagem->enviada_em,
        ]);

        return $mensagem;
    }

    /** Marca como lida na Shopee (ação do atendente). */
    public function marcarLida(ShopeeChatConversa $conversa): void
    {
        if ($conversa->ultima_mensagem_id) {
            $this->client->post(self::LER, [
                'conversation_id' => $conversa->conversation_id,
                'last_read_message_id' => $conversa->ultima_mensagem_id,
            ]);
        }

        $conversa->update(['nao_lidas' => 0]);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<int, string> ids vistos
     */
    private function listar(string $tipo, int $paginas, array &$r, bool &$completa = false): array
    {
        $ids = [];
        $cursor = null;
        $completa = false;

        for ($i = 0; $i < $paginas; $i++) {
            $resp = $this->client->get(self::LISTA, array_filter([
                'direction' => 'latest',
                'type' => $tipo,
                'page_size' => 25,
                'next_timestamp_nano' => $cursor,
            ]))['response'] ?? [];

            foreach ($resp['conversations'] ?? [] as $c) {
                $this->gravarConversa($c);
                $ids[] = (string) $c['conversation_id'];
            }

            $mais = (bool) ($resp['page_result']['more'] ?? false);
            $cursor = $resp['page_result']['next_cursor']['next_message_time_nano'] ?? null;
            if (! $mais || ! $cursor) {
                $completa = true;
                break;
            }
        }

        return $ids;
    }

    /** @param array<string, mixed> $c */
    private function gravarConversa(array $c): void
    {
        $id = (string) $c['conversation_id'];
        $tipo = (string) ($c['latest_message_type'] ?? 'text');
        $conteudo = is_array($c['latest_message_content'] ?? null) ? $c['latest_message_content'] : [];
        $compradorId = (int) $c['to_id'];

        $conversa = ShopeeChatConversa::firstOrNew(['conversation_id' => $id]);
        $conversa->fill([
            'comprador_id' => $compradorId,
            'comprador_nome' => $c['to_name'] ?? $conversa->comprador_nome,
            'comprador_avatar' => $c['to_avatar'] ?? $conversa->comprador_avatar,
            'nao_lidas' => (int) ($c['unread_count'] ?? 0),
            'ultima_mensagem_id' => isset($c['latest_message_id']) ? (string) $c['latest_message_id'] : $conversa->ultima_mensagem_id,
            'ultima_mensagem_tipo' => $tipo,
            'ultima_mensagem_texto' => self::resumo($tipo, $conteudo),
            'ultima_do_cliente' => (int) ($c['latest_message_from_id'] ?? $compradorId) === $compradorId,
            'ultima_mensagem_em' => isset($c['last_message_timestamp']) ? Carbon::createFromTimestamp(self::segundos((int) $c['last_message_timestamp'])) : $conversa->ultima_mensagem_em,
        ])->save();
    }

    /** @param array<string, mixed> $m */
    private function atributosMensagem(ShopeeChatConversa $conversa, array $m): array
    {
        $tipo = (string) ($m['message_type'] ?? 'text');
        $conteudo = is_array($m['content'] ?? null) ? $m['content'] : [];

        return [
            'conversa_id' => $conversa->id,
            'message_id' => (string) $m['message_id'],
            'tipo' => $tipo,
            'do_cliente' => (int) ($m['from_id'] ?? 0) === (int) $conversa->comprador_id,
            'texto' => self::resumo($tipo, $conteudo),
            'conteudo' => $conteudo,
            'origem' => $m['source'] ?? null,
            'enviada_em' => isset($m['created_timestamp']) ? Carbon::createFromTimestamp(self::segundos((int) $m['created_timestamp'])) : now(),
        ];
    }

    /** Texto legível de qualquer tipo de mensagem. */
    public static function resumo(string $tipo, array $conteudo): string
    {
        return match ($tipo) {
            'text' => (string) ($conteudo['text'] ?? ''),
            'image' => '[Imagem do cliente]',
            'video' => '[Vídeo]',
            'sticker' => '[Figurinha]',
            'item' => '[Produto compartilhado: '.($conteudo['item_id'] ?? '?').']',
            'order' => '[Pedido compartilhado: '.($conteudo['order_sn'] ?? '?').']',
            'bundle_message' => '[Mensagem com produtos]',
            default => '['.$tipo.']',
        };
    }

    /** A Shopee mistura segundos e nanossegundos nos campos de hora. */
    private static function segundos(int $t): int
    {
        return $t > 99999999999 ? intdiv($t, 1000000000) : $t;
    }

    /** @param array<string, mixed> $r */
    private function falhou(string $etapa, Throwable $e, array &$r, array $extra = []): void
    {
        $r['falhas']++;
        // 429 e mensagens de limite: para o passe e deixa o próximo continuar.
        if ($e->getCode() === 429 || preg_match('/rate|too many|frequen|limit/i', $e->getMessage())) {
            $r['limitado'] = true;
        }
        Log::channel('shopee')->warning('shopee.chat.falha', ['etapa' => $etapa, 'erro' => $e->getMessage()] + $extra);
    }
}
