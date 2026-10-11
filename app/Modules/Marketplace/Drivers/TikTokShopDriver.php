<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Marketplace\Drivers\Concerns\ReadsOrdersFromBling;
use App\Models\User;
use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Notifications\WebhookImportFailedNotification;
use App\Services\Bling\BlingOrderService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * TikTok Shop — API direta docs: https://partner.tiktokshop.com (Partner
 * Center, aprovação de parceiro exigida pra vendedor BR, ainda pendente).
 *
 * IMPORTAÇÃO DE PEDIDO (pedido explícito 2026-08-31): em vez de esperar
 * essa aprovação, os pedidos chegam via BLING — o vendedor conecta o TikTok
 * Shop uma vez DENTRO do painel do Bling (ver ajuda.bling.com.br
 * "Autenticação com o TikTok Shop"/"Configuração do TikTok Shop"), e este
 * driver consulta os pedidos já sincronizados lá (BlingOrderService),
 * filtrados pela loja configurada como TikTok Shop. `origin` do Order
 * continua sendo CHANNEL_TIKTOK_SHOP (é uma venda do TikTok Shop de
 * verdade) — Bling é só o cano, não aparece em lugar nenhum pro resto do
 * sistema (KoraSync, NF-e, etc. não sabem nem precisam saber que passou
 * pelo Bling).
 *
 * ENVIO/ETIQUETA (achado real 2026-08-31, mesmo dia): a NF-e já sai
 * automática assim que o pedido entra como PAID (mesmo gatilho de sempre,
 * ver OrderImportService::createOrder() — GenerateInvoiceJob) — nenhum
 * código novo precisou disso. Etiqueta é diferente: confirmShipping()/
 * fetchLabel() agora usam endpoints REAIS do Bling
 * (logisticas/etiquetas, confirmado existir contra a conta do usuário),
 * mas NENHUM pedido da conta tinha "logística cadastrada" no momento em
 * que isso foi escrito (rastreio ainda vazio em todos — a atribuição é
 * feita pelo TikTok Shop/Bling de forma assíncrona, fora do nosso
 * controle) — então o caminho de baixar um PDF de verdade não foi
 * exercido ainda. Uma vez que algum pedido tiver rastreio, o pipeline
 * padrão (CheckShipmentLabelJob/LabelFetchService, o mesmo de qualquer
 * canal) deve simplesmente funcionar — vale conferir o resultado real na
 * primeira vez que isso acontecer.
 *
 * O que continua REALMENTE não implementado: publishProduct/updateStock/
 * unpublishProduct/submitInvoice-ao-canal (esse último é diferente de
 * "gerar a NF-e", que já funciona — é o envio do XML/PDF autorizado PRO
 * TikTok Shop, exigido antes de liberar o envio; nenhum endpoint do Bling
 * pra isso foi identificado ainda). Documentado como TODO, não como
 * "funciona" — não é regressão: já não funcionava antes.
 */
class TikTokShopDriver extends AbstractMarketplaceDriver
{
    use ReadsOrdersFromBling;

    public function __construct(private readonly BlingOrderService $blingOrders) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_TIKTOK_SHOP;
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via /product/202309/images/upload,
        // then call /product/202309/products with the returned image ids
        // and the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/inventory/update with $product->stock.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call /product/202309/products/deactivate.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    /**
     * Achado real 2026-08-31, resolvendo pedidos reais da conta do
     * usuário ("arrumar todos os pedidos e produtos... nada manual, tudo
     * auto"): o código que o TikTok manda pro Bling (`itens[].codigo`,
     * usado como external_id) NUNCA é um produto Bling de verdade
     * (`produto.id` sempre veio 0 em todo pedido real conferido, e
     * `GET produtos?codigo=X` nunca acha nada — confirmado ao vivo) — não
     * existe endpoint do Bling pra "buscar item por código" fora de um
     * pedido específico, então SKU exato sozinho não é suficiente. 3
     * tentativas em ordem, cada uma só avança se a anterior não achou
     * nada, todas determinísticas (nunca uma escolha aleatória):
     *
     * 1) SKU exato (Product::sku === external_id) — caso ideal, cadastro
     *    já bate certinho.
     * 2) SUFIXO de SKU — achado real: o código às vezes chega TRUNCADO,
     *    faltando o prefixo (ex: "-8-POLEGADAS" em vez de
     *    "RING-LIGTH-8-POLEGADAS-SOLO") — casa contra o final do SKU
     *    local. Só aceita se achar exatamente 1 produto (ambíguo demais
     *    com 2+, não arrisca).
     * 3) NOME do item (guardado em cache por importOrder(), já que o
     *    Bling só expõe o nome DENTRO do pedido, não por código sozinho)
     *    contra TODO Product::name do catálogo, por SIMILARIDADE (não
     *    exato — achado real testando: o título do anúncio no TikTok
     *    quase nunca é idêntico ao nome cadastrado aqui, ex. "Mini
     *    Carregador Portátil Power Bank 10000mAh 2 em 1 para iPhone e
     *    Tipo C com Suporte" no TikTok vs "Carregador Portátil Power Bank
     *    10000mah Para iPhone E Tipo C Rosa" no catálogo — match exato
     *    nunca acharia isso). Usa similar_text() (percentual de
     *    semelhança) contra cada produto ativo, pega o(s) de maior
     *    pontuação acima de MIN_NAME_SIMILARITY:
     *      - só 1 no topo → usa direto.
     *      - vários empatados no topo (ex: mesmo produto em 4 cores —
     *        Rosa/Preto/Branco/Verde, caso real do Carregador Power Bank
     *        — e o TikTok não informa QUAL cor foi vendida, só o nome
     *        genérico) → NÃO chuta uma cor à toa: escolhe automaticamente
     *        a variação com MAIS estoque agora (minimiza a chance de
     *        zerar uma variação específica sem querer, distribui a venda
     *        entre as variações que existem de verdade).
     *    Qualquer que seja a escolha, esse código específico do TikTok
     *    fica PERMANENTEMENTE vinculado a ela (ProductChannelListing
     *    abaixo) — nunca mais precisa decidir de novo pra esse código.
     */
    private const MIN_NAME_SIMILARITY = 55.0;

    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        // BUG REAL 2026-08-31 (achado ao vivo, reconciliando pedidos
        // reais): sem checar isto primeiro, uma 2ª venda do MESMO código
        // do TikTok — normalmente resolvida pelo listing já existente,
        // via OrderImportService, que só chama autoImportProduct() quando
        // NÃO acha nenhum listing — mas se este método for chamado direto
        // (como aconteceu numa reconciliação manual desta sessão) recalcula
        // a similaridade do zero e pode escolher outra variação empatada
        // (a antiga já ficou sem estoque suficiente pra ser a "melhor"),
        // e o firstOrCreate() mais abaixo silenciosamente IGNORA esse
        // resultado novo (acha a linha antiga, não atualiza) enquanto o
        // método ainda devolve o produto ERRADO pro chamador debitar
        // estoque. Checar aqui garante consistência sempre, não só
        // quando chamado pelo caminho normal.
        $existingListing = ProductChannelListing::query()
            ->where('channel', MarketplaceAccount::CHANNEL_TIKTOK_SHOP)
            ->where('external_id', $externalId)
            ->first();

        if ($existingListing) {
            return $existingListing->product;
        }

        $product = Product::where('sku', $externalId)->first();

        if (! $product) {
            $bySuffix = Product::where('sku', 'like', '%'.$externalId)->get();
            $product = $bySuffix->count() === 1 ? $bySuffix->first() : null;
        }

        if (! $product) {
            $itemName = cache()->get("bling.tiktok_item_name.{$externalId}");
            $product = $itemName ? $this->matchByNameSimilarity($itemName) : null;
        }

        if (! $product) {
            return null;
        }

        // BUG REAL 2026-08-31 (achado ao vivo): product_channel_listings só
        // permite 1 linha por (produto, canal) — mas o TikTok pode mandar
        // VÁRIOS códigos diferentes que todos resolvem pro MESMO produto
        // (achado real: o Power Bank Preto tem pelo menos 2 códigos
        // distintos do TikTok, cada venda desse item físico aparentemente
        // gera/usa um código próprio, não um id de anúncio fixo reaproveitado).
        // Criar o listing é só uma otimização (evita recalcular a
        // similaridade de novo pro mesmo código) — se já existe um listing
        // pra este produto neste canal (com outro external_id), não é
        // erro: ignora e segue, o produto já foi encontrado certo mesmo
        // assim.
        try {
            ProductChannelListing::query()->firstOrCreate(
                ['channel' => MarketplaceAccount::CHANNEL_TIKTOK_SHOP, 'external_id' => $externalId],
                ['product_id' => $product->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
            );
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            // Ignorado de propósito — ver comentário acima.
        }

        return $product;
    }

    /**
     * Margem de tolerância pra considerar 2 produtos "a mesma família de
     * variação" — achado real testando: as 4 cores do Carregador Power
     * Bank pontuam 82.9%~84.8% contra o mesmo nome de anúncio do TikTok
     * (nunca EXATAMENTE iguais, cada nome de produto tem uma diferença
     * de 1-2 caracteres — a cor em si), enquanto o produto não
     * relacionado mais próximo fica em 48.2%. Uma margem de 10 pontos a
     * partir do melhor score agrupa as 4 cores de verdade sem puxar nada
     * de fora.
     */
    private const NAME_SIMILARITY_TIE_MARGIN = 10.0;

    /**
     * BUG REAL 2026-08-31 (relatado pelo usuário, "é preto" — errei):
     * desempatar por MAIS estoque agora é o critério ERRADO — penaliza
     * exatamente a variação mais vendida de verdade (menos estoque
     * sobrando É o sinal de que é a popular). O histórico real de vendas
     * (quantos itens desta MESMA venda genérica pelo TikTok já foram
     * vinculados a cada variação, em pedidos anteriores) é o sinal
     * correto — direto confirmado ao vivo: entre as 4 cores do Power
     * Bank, "Preto" já tinha ~90 pedidos reais vinculados antes desta
     * sessão, as outras cores quase nenhum. Só cai pra estoque como
     * último recurso, quando NENHuma variação tem histórico nenhum ainda
     * (produto novo de verdade, sem venda prévia pra guiar a escolha).
     */
    private function matchByNameSimilarity(string $itemName): ?Product
    {
        $scored = Product::query()->where('is_active', true)->get(['id', 'name', 'sku', 'stock'])
            ->map(function (Product $candidate) use ($itemName) {
                similar_text(mb_strtolower($itemName), mb_strtolower($candidate->name), $percent);

                return ['product' => $candidate, 'score' => $percent];
            })
            ->filter(fn ($row) => $row['score'] >= self::MIN_NAME_SIMILARITY);

        if ($scored->isEmpty()) {
            return null;
        }

        $bestScore = $scored->max('score');
        $tied = $scored->filter(fn ($row) => $row['score'] >= $bestScore - self::NAME_SIMILARITY_TIE_MARGIN);

        if ($tied->count() === 1) {
            return $tied->first()['product'];
        }

        // BUG REAL 2026-09-02 (relatado pelo usuário: pedido com 1 Power
        // Bank PRETO e 1 ROSA aparecendo como 2 PRETOS no KoraSync): até
        // aqui, empate era desempatado por histórico de vendas — e o
        // histórico puxa pra variação mais vendida (Preto, ~90 pedidos),
        // então a venda de qualquer OUTRA cor virava Preto silenciosamente.
        // O operador embala a cor errada e o cliente recebe errado.
        //
        // O empate acontece porque as 4 cores têm o MESMO nome no catálogo
        // (a cor só existe no SKU: -PRE-, -ROSA-, -BRA-, -VERDE-) e o
        // TikTok, via Bling, não manda a cor em campo nenhum: `descricao` é
        // igual pros dois itens e `produto.id` vem 0. Não existe sinal
        // nenhum no dado que diga qual cor foi vendida.
        //
        // Então NÃO ESCOLHE. Sem sinal, chutar é pior que parar: um item
        // sem produto vinculado trava a nota (alguém conserta em 30s), um
        // item com a COR ERRADA vira encomenda errada na casa do cliente.
        // O código do TikTok é estável por variação, então basta alguém
        // vincular UMA vez (ProductChannelListing) e nunca mais se decide
        // isso pra esse código.
        Log::warning('tiktok.item.variacao_ambigua', [
            'item_name' => $itemName,
            'candidatos' => $tied->map(fn ($row) => ['id' => $row['product']->id, 'sku' => $row['product']->sku])->values()->all(),
        ]);

        $admins = User::query()->where('role', User::ROLE_ADMIN)->get();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new WebhookImportFailedNotification(
                MarketplaceAccount::CHANNEL_TIKTOK_SHOP,
                null,
                'Item "'.mb_substr($itemName, 0, 60).'" casa com '.$tied->count().' variações do mesmo produto ('
                    .$tied->map(fn ($row) => $row['product']->sku)->implode(', ')
                    .') e o canal não informa qual. Vincule o SKU do TikTok ao produto certo — nenhum produto foi escolhido.',
            ));
        }

        return null;
    }

    /** Pedido lido do Bling — ver ReadsOrdersFromBling::importOrderFromBling(). */
    public function importOrder(string $externalOrderId): array
    {
        return $this->importOrderFromBling($externalOrderId);
    }

    protected function blingBuyerFallbackName(): string
    {
        return 'Comprador TikTok Shop';
    }

    protected function blingShippingMethodFallback(): string
    {
        return 'TikTok Shop Logistics';
    }

    /**
     * autoImportProduct() casa por similaridade de nome (ver docblock lá),
     * e o nome só existe dentro do pedido — guarda aqui pra ele consultar.
     */
    protected function rememberBlingItemName(string $externalId, string $name): void
    {
        cache()->put("bling.tiktok_item_name.{$externalId}", $name, now()->addDay());
    }

    /**
     * Confirmado (2026-08-01): TikTok Shop BR exige NF-e (XML, <10MB) via
     * "Gerenciar Pedidos" antes do pedido poder virar "Pronto para envio" —
     * existe uma página de doc dedicada "BR market - Updated API workflow
     * to support Order, Invoice and Warehouse", mas o conteúdo fica atrás de
     * login de parceiro aprovado — endpoint exato não confirmado ainda.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        // TODO: endpoint exato só visível com credencial de parceiro
        // aprovada — ver "BR market - Order, Invoice and Warehouse" no
        // Partner Center quando a conta existir.
        throw new \RuntimeException('Integração com TikTok Shop ainda não implementada.');
    }

    /** Pura consulta ao Bling — ver ReadsOrdersFromBling::confirmShippingFromBling(). */
    public function confirmShipping(Order $order): array
    {
        return $this->confirmShippingFromBling($order);
    }

    public function fetchLabel(Order $order): array
    {
        return $this->fetchLabelFromBling($order);
    }
}
