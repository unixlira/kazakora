<?php

namespace App\Services\Bling;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Services\Bling\Exceptions\BlingException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Pedidos de venda do Bling, filtrados pra só trazer os que vieram do
 * TikTok Shop — Bling é usado como PONTE (ver BlingAuthService), então uma
 * mesma conta Bling pode ter vários canais conectados (loja própria,
 * Mercado Livre, Shopee, TikTok Shop...), e este serviço só quer o que veio
 * de um canal específico: a "loja" (número interno do Bling) que o usuário
 * já configurou como TikTok Shop dentro do próprio painel do Bling (ver
 * "Configuração do TikTok Shop" na ajuda do Bling).
 */
class BlingOrderService
{
    public function __construct(private readonly BlingClient $client) {}

    /**
     * ID da loja do Bling que corresponde ao TikTok Shop — configurado uma
     * vez pelo admin (tela de Integrações) depois de conectar, guardado em
     * MarketplaceAccount(channel=bling).metadata. Sem essa configuração,
     * não dá pra saber com segurança quais pedidos entre TODOS os canais
     * que o Bling sincroniza são de fato do TikTok Shop — melhor recusar
     * explicitamente do que arriscar importar pedido de outro canal como
     * se fosse TikTok Shop.
     */
    public function tiktokLojaId(): ?int
    {
        $metadata = MarketplaceAccount::query()->where('channel', MarketplaceAccount::CHANNEL_BLING)->value('metadata');

        return $metadata['tiktok_loja_id'] ?? null;
    }

    /**
     * Números de pedido (numeroLoja — o próprio número do pedido no
     * TikTok Shop, não o id interno do Bling) da loja do TikTok Shop num
     * intervalo de datas. Pagina de verdade (a API do Bling devolve
     * `data` vazio quando a página passa do fim, não um total explícito).
     *
     * @return array<int, string>
     */
    public function listRecentOrderNumbers(Carbon $from, Carbon $to): array
    {
        return collect($this->listOrders($from, $to))
            ->pluck('numeroLoja')
            ->filter()
            ->map(fn ($n) => (string) $n)
            ->values()
            ->all();
    }


    /**
     * Guarda "numeroLoja (número do pedido no TikTok Shop) → id interno do
     * Bling", correspondência que só o webhook entrega de graça (ver
     * BlingWebhookController). O teto do Bling é 3 req/s pra conta INTEIRA,
     * disputados com emissão de nota e etiqueta — cada varredura de 60 dias
     * evitada conta.
     */
    public function rememberOrderId(string $orderNumber, int $blingOrderId): void
    {
        Cache::put($this->orderIdCacheKey($orderNumber), $blingOrderId, now()->addDays(30));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $blingOrderId): ?array
    {
        try {
            $response = $this->client->get("pedidos/vendas/{$blingOrderId}");
        } catch (BlingException $exception) {
            Log::warning('bling.order.find_by_id_failed', [
                'bling_order_id' => $blingOrderId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        return $response['data'] ?? null;
    }

    private function orderIdCacheKey(string $orderNumber): string
    {
        return 'bling.order_id.'.$orderNumber;
    }

    /**
     * BUG REAL 2026-08-31 (achado testando ao vivo contra a conta real do
     * usuário — os 16 pedidos listados por listRecentOrderNumbers() vinham
     * todos como "não encontrado" aqui): o filtro `numero` da API do Bling
     * é o número SEQUENCIAL INTERNO do Bling (o pequeno, tipo 16/15/14...),
     * não o `numeroLoja` (o número real do pedido no TikTok Shop, uma
     * string longa tipo "585827527600211202") — mandar o numeroLoja no
     * parâmetro `numero` simplesmente nunca casava com nada, 100% dos
     * pedidos reais falhavam. O Bling não tem um filtro de busca direto
     * por numeroLoja; a única forma confiável é listar pela loja/período
     * (mesma chamada de listRecentOrderNumbers()) e casar pelo numeroLoja
     * no lado de cá — por isso os dois métodos agora reaproveitam
     * listOrders(), com cache curto (ver lá) pra não multiplicar chamada
     * por pedido (achado no mesmo teste: 2 dos 16 pedidos bateram "Limite
     * de requisições atingido" só de cada um fazer sua própria varredura
     * separada de 60 dias).
     *
     * @return array<string, mixed>|null
     */
    public function findByOrderNumber(string $orderNumber): ?array
    {
        // Atalho alimentado pelo webhook (ProcessBlingOrderWebhook), que já
        // recebe o id interno do Bling no próprio payload: vai direto no
        // pedido em vez de varrer 60 dias da loja atrás do numeroLoja.
        // Também é o único caminho que funciona pra pedido FORA dessa
        // janela de 60 dias.
        if ($blingId = Cache::get($this->orderIdCacheKey($orderNumber))) {
            if ($order = $this->findById((int) $blingId)) {
                return $order;
            }
        }

        $match = collect($this->listOrders(now()->subDays(60), now()))
            ->first(fn ($order) => (string) ($order['numeroLoja'] ?? '') === $orderNumber);

        if (! $match) {
            return null;
        }

        return $this->client->get("pedidos/vendas/{$match['id']}")['data'] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listOrders(Carbon $from, Carbon $to): array
    {
        $lojaId = $this->tiktokLojaId();

        if (! $lojaId) {
            return [];
        }

        // Cache curto (2min) por loja+período — um orders:sync-tiktok
        // chama findByOrderNumber() uma vez por pedido encontrado; sem
        // isso, cada chamada refaz a varredura paginada inteira do
        // período, multiplicando requisições à toa e batendo no rate
        // limit do Bling (achado real, ver docblock de findByOrderNumber()).
        $cacheKey = "bling.orders.{$lojaId}.{$from->toDateString()}.{$to->toDateString()}";

        return Cache::remember($cacheKey, now()->addMinutes(2), function () use ($lojaId, $from, $to) {
            $orders = [];
            $page = 1;

            do {
                $response = $this->client->get('pedidos/vendas', [
                    'idLoja' => $lojaId,
                    'dataInicial' => $from->toDateString(),
                    'dataFinal' => $to->toDateString(),
                    'pagina' => $page,
                    'limite' => 100,
                ]);

                $results = $response['data'] ?? [];
                array_push($orders, ...$results);

                $page++;
            } while (count($results) === 100);

            return $orders;
        });
    }

    /**
     * Nome real da situação (ex: "Cancelado", "Atendido") — `situacao.id`
     * vem no pedido, mas as situações do Bling são personalizáveis por
     * conta (o próprio Bling expõe uma API de CRUD pra elas,
     * situacoesModulos/situacoesTransicoes), então não dá pra confiar num
     * número fixo tipo "12 = cancelado" — isso pode variar de conta pra
     * conta. Resolve pelo NOME de verdade, que é estável independente de
     * customização.
     */
    public function situacaoName(int $situacaoId): ?string
    {
        return $this->client->get("situacoes/{$situacaoId}")['data']['nome'] ?? null;
    }

    /**
     * Link de download da etiqueta de envio já gerada pelo Bling pro
     * pedido — endpoint real `GET logisticas/etiquetas`, confirmado ao
     * vivo 2026-08-31 contra a conta real do usuário. Descoberta real no
     * mesmo teste: só devolve algo quando o pedido já tem "logística
     * cadastrada" no Bling (volume/rastreio atribuído do lado do
     * TikTok Shop) — pedido recém-importado, sem rastreio ainda, dá
     * RESOURCE_NOT_FOUND ("ids informados são inválidos ou não possuem
     * logística cadastrada"). Trata como "ainda não pronta" (null), não
     * como erro — mesma distinção que TODO fetchLabel() de canal já faz
     * (ver ShopeeDriver::fetchLabel()).
     *
     * NÃO TESTADO com uma etiqueta de verdade ainda: nenhum pedido da
     * conta do usuário tinha logística cadastrada no momento em que isso
     * foi escrito (todos com codigoRastreamento vazio) — o endpoint e o
     * tratamento de erro são reais/confirmados, mas o link de fato
     * baixando um PDF válido ainda não foi verificado.
     *
     * @return array{link: string}|null
     */
    public function fetchLabel(int $blingOrderId): ?array
    {
        try {
            $response = $this->client->get('logisticas/etiquetas', [
                'formato' => 'PDF',
                'idsVendas' => [$blingOrderId],
            ]);
        } catch (\App\Services\Bling\Exceptions\BlingException $exception) {
            if ($exception->getCode() === 404) {
                return null;
            }

            throw $exception;
        }

        $label = $response['data'][0] ?? null;

        return $label && ! empty($label['link']) ? $label : null;
    }

    /**
     * Dados do contato (telefone/celular/e-mail/endereço) — a listagem de
     * pedidos só traz nome/documento do comprador; o resto exige esta
     * chamada extra por contato (mesmo padrão de 1 chamada complementar já
     * usado pra CPF/CNPJ do Mercado Livre/Shopee).
     *
     * @return array<string, mixed>|null
     */
    public function findContact(int $contactId): ?array
    {
        return $this->client->get("contatos/{$contactId}")['data'] ?? null;
    }
}
