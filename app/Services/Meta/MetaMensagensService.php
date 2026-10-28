<?php

namespace App\Services\Meta;

use App\Modules\Marketplace\Models\MarketplaceChatConversa;
use App\Modules\Marketplace\Models\MarketplaceChatMensagem;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Direct do Instagram e Messenger do Facebook em Atendimento (pedido do Lira
 * 2026-10-27: trazer as mensagens de quem chama pelos anúncios, separando o
 * que vem de cada rede). Lê pela Conversations API da página "Kaza Kora & CO"
 * com o mesmo token do Meta ADS (services.meta_ads) e espelha em
 * marketplace_chat_* com canal instagram ou facebook. Só leitura: nada é
 * enviado ao cliente por aqui.
 *
 * O token precisa das permissões de mensagem (PERMISSOES); sem elas a tela
 * mostra o que falta e o sync pula a rede sem erro.
 */
class MetaMensagensService
{
    /** Rede => platform da Conversations API. */
    public const PLATAFORMA = [
        MarketplaceChatConversa::INSTAGRAM => 'instagram',
        MarketplaceChatConversa::FACEBOOK => 'messenger',
    ];

    /** Permissões do token exigidas por rede. */
    public const PERMISSOES = [
        MarketplaceChatConversa::INSTAGRAM => ['instagram_basic', 'instagram_manage_messages', 'pages_manage_metadata'],
        MarketplaceChatConversa::FACEBOOK => ['pages_messaging', 'pages_manage_metadata', 'pages_read_engagement'],
    ];

    /** @return list<string> o que falta para ler a rede (vazio = pronto) */
    public static function faltando(string $rede): array
    {
        $faltando = [];
        if (blank(config('services.meta_ads.access_token'))) {
            $faltando[] = 'META_ADS_ACCESS_TOKEN no .env';
        }
        if (blank(config('services.meta_ads.page_id'))) {
            $faltando[] = 'META_ADS_PAGE_ID no .env';
        }
        if ($faltando !== []) {
            return $faltando;
        }

        $concedidas = self::permissoesConcedidas();
        if ($concedidas === null) {
            return ['Não foi possível consultar as permissões do token na Meta agora.'];
        }

        return collect(self::PERMISSOES[$rede] ?? [])
            ->reject(fn (string $p) => in_array($p, $concedidas, true))
            ->map(fn (string $p) => "Permissão {$p} no token da Meta")
            ->values()->all();
    }

    /** @return list<string>|null permissões concedidas ao token (cache 10 min) */
    public static function permissoesConcedidas(): ?array
    {
        return Cache::remember('meta_mensagens:permissoes', 600, function () {
            try {
                $r = self::cliente((string) config('services.meta_ads.access_token'))->get(self::url('me/permissions'));
            } catch (ConnectionException) {
                return null;
            }
            if (! $r->successful()) {
                return null;
            }

            return collect($r->json('data', []))->where('status', 'granted')->pluck('permission')->values()->all();
        });
    }

    /** @return array{conversas: int, mensagens: int, falhas: int} */
    public function sincronizar(string $rede, int $maximoConversas = 25): array
    {
        $r = ['conversas' => 0, 'mensagens' => 0, 'falhas' => 0];
        [$tokenPagina, $idsDaLoja] = $this->pagina();

        $dados = $this->get($tokenPagina, config('services.meta_ads.page_id').'/conversations', [
            'platform' => self::PLATAFORMA[$rede],
            'fields' => 'id,updated_time,participants',
            'limit' => $maximoConversas,
        ]);

        foreach ($dados['data'] ?? [] as $c) {
            $r['conversas']++;
            $conversa = MarketplaceChatConversa::query()->firstOrNew(['canal' => $rede, 'externo_id' => (string) $c['id']]);
            if ($conversa->exists && $conversa->mensagens_sync_id === ($c['updated_time'] ?? null)) {
                continue;
            }

            try {
                $r['mensagens'] += $this->copiarConversa($rede, $conversa, $c, $tokenPagina, $idsDaLoja);
            } catch (RuntimeException $e) {
                $r['falhas']++;
                report($e);
            }
        }

        return $r;
    }

    /** Marca como lida só aqui no admin (a Meta não recebe nada). */
    public function marcarLida(MarketplaceChatConversa $conversa): void
    {
        $conversa->update(['nao_lidas' => 0]);
    }

    /**
     * @param  array<string, mixed>  $c
     * @param  list<string>  $idsDaLoja
     */
    private function copiarConversa(string $rede, MarketplaceChatConversa $conversa, array $c, string $token, array $idsDaLoja): int
    {
        $cliente = collect($c['participants']['data'] ?? [])->first(fn ($p) => ! in_array((string) ($p['id'] ?? ''), $idsDaLoja, true)) ?? [];
        $primeiraCarga = ! $conversa->exists;

        $conversa->fill([
            'comprador_nome' => $cliente['username'] ?? $cliente['name'] ?? $conversa->comprador_nome,
            'sincronizada_em' => now(),
        ]);
        $conversa->save();

        $lista = $this->get($token, (string) $c['id'], ['fields' => 'messages.limit(25){id,created_time,from,message,attachments}'])['messages']['data'] ?? [];

        $novas = 0;
        $novasDoCliente = 0;
        foreach (array_reverse($lista) as $m) {
            $externo = (string) ($m['id'] ?? '');
            if ($externo === '' || MarketplaceChatMensagem::query()->where('canal', $rede)->where('externo_id', $externo)->exists()) {
                continue;
            }
            $doCliente = ! in_array((string) ($m['from']['id'] ?? ''), $idsDaLoja, true);
            [$tipo, $texto, $conteudo] = self::lerMensagem($m);

            $conversa->mensagens()->create([
                'canal' => $rede,
                'externo_id' => $externo,
                'tipo' => $tipo,
                'do_cliente' => $doCliente,
                'texto' => $texto,
                'conteudo' => $conteudo,
                'enviada_em' => self::quando($m['created_time'] ?? null),
            ]);
            $novas++;
            $novasDoCliente += $doCliente ? 1 : 0;
        }

        $ultima = $lista[0] ?? null;
        $doClienteUltima = $ultima ? ! in_array((string) ($ultima['from']['id'] ?? ''), $idsDaLoja, true) : true;
        $conversa->fill([
            'mensagens_sync_id' => $c['updated_time'] ?? null,
            // Primeira carga: só a conversa em que o cliente falou por último fica não lida.
            'nao_lidas' => $primeiraCarga ? ($ultima && $doClienteUltima ? 1 : 0) : $conversa->nao_lidas + $novasDoCliente,
        ]);
        if ($ultima) {
            $conversa->fill([
                'ultima_mensagem_texto' => Str::limit(self::lerMensagem($ultima)[1], 300),
                'ultima_do_cliente' => $doClienteUltima,
                'ultima_mensagem_em' => self::quando($ultima['created_time'] ?? null),
            ]);
        }
        $conversa->save();

        return $novas;
    }

    /**
     * @param  array<string, mixed>  $m
     * @return array{0: string, 1: string, 2: array<string, mixed>|null}
     */
    private static function lerMensagem(array $m): array
    {
        $texto = trim((string) ($m['message'] ?? ''));
        $anexo = $m['attachments']['data'][0] ?? null;
        if ($anexo) {
            $imagem = $anexo['image_data']['url'] ?? null;
            if ($imagem) {
                return ['image', $texto !== '' ? $texto : '[imagem]', ['url' => $imagem]];
            }
            if ($texto === '') {
                return ['anexo', '[anexo: '.($anexo['mime_type'] ?? $anexo['name'] ?? 'arquivo').']', ['url' => $anexo['file_url'] ?? $anexo['video_data']['url'] ?? null]];
            }
        }

        return ['text', $texto !== '' ? $texto : '[mensagem sem texto]', null];
    }

    /**
     * Token da página e ids que são da loja (página e Instagram ligado a ela).
     *
     * @return array{0: string, 1: list<string>}
     */
    private function pagina(): array
    {
        $pageId = (string) config('services.meta_ads.page_id');

        return Cache::remember('meta_mensagens:pagina:'.md5($pageId), 3000, function () use ($pageId) {
            $p = $this->get((string) config('services.meta_ads.access_token'), $pageId, ['fields' => 'access_token,instagram_business_account']);
            if (blank($p['access_token'] ?? null)) {
                throw new RuntimeException('A Meta não devolveu o token da página.');
            }

            return [$p['access_token'], array_values(array_filter([$pageId, (string) ($p['instagram_business_account']['id'] ?? '')]))];
        });
    }

    /** @return array<string, mixed> */
    private function get(string $token, string $caminho, array $params): array
    {
        try {
            $resposta = self::cliente($token)->get(self::url($caminho), $params);
        } catch (ConnectionException) {
            throw new RuntimeException('Sem conexão com a Meta.');
        }

        return self::ler($resposta);
    }

    /** @return array<string, mixed> */
    private static function ler(Response $resposta): array
    {
        if ($resposta->successful()) {
            return $resposta->json() ?? [];
        }

        // Nunca repassa o token: só código e mensagem da Meta.
        $erro = $resposta->json('error', []);
        throw new RuntimeException('Meta recusou ('.($erro['code'] ?? $resposta->status()).'): '.Str::limit((string) ($erro['message'] ?? 'erro'), 200));
    }

    private static function cliente(string $token): PendingRequest
    {
        return Http::withToken($token)->acceptJson()->timeout(30);
    }

    private static function url(string $caminho): string
    {
        return rtrim((string) config('services.meta_ads.graph_url'), '/').'/'.ltrim($caminho, '/');
    }

    private static function quando(?string $iso): ?Carbon
    {
        return $iso ? Carbon::parse($iso)->setTimezone('America/Sao_Paulo') : null;
    }
}
