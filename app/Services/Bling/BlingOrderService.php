<?php

namespace App\Services\Bling;

use App\Modules\Marketplace\Models\MarketplaceAccount;
use Illuminate\Support\Carbon;

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
        $lojaId = $this->tiktokLojaId();

        if (! $lojaId) {
            return [];
        }

        $numbers = [];
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

            foreach ($results as $order) {
                if (! empty($order['numeroLoja'])) {
                    $numbers[] = (string) $order['numeroLoja'];
                }
            }

            $page++;
        } while (count($results) === 100);

        return $numbers;
    }

    /**
     * Acha o pedido pelo número de loja (numeroLoja) — a API do Bling só
     * busca por idPedidoVenda (interno do Bling) direto, não por
     * numeroLoja, então localiza primeiro dentro da mesma loja/intervalo
     * recente e resolve pro id interno antes de buscar os detalhes
     * completos.
     *
     * @return array<string, mixed>|null
     */
    public function findByOrderNumber(string $orderNumber): ?array
    {
        $lojaId = $this->tiktokLojaId();

        if (! $lojaId) {
            return null;
        }

        // Janela de 60 dias pra trás — cobre qualquer backfill razoável
        // sem varrer o histórico inteiro da loja a cada consulta.
        $response = $this->client->get('pedidos/vendas', [
            'idLoja' => $lojaId,
            'numero' => $orderNumber,
            'dataInicial' => now()->subDays(60)->toDateString(),
            'dataFinal' => now()->toDateString(),
            'limite' => 10,
        ]);

        $match = collect($response['data'] ?? [])->firstWhere('numeroLoja', $orderNumber);

        if (! $match) {
            return null;
        }

        return $this->client->get("pedidos/vendas/{$match['id']}")['data'] ?? null;
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
