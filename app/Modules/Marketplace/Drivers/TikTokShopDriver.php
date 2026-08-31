<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Checkout\Models\OrderItem;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Bling\BlingOrderService;
use App\Services\Bling\Exceptions\BlingException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

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

        // Desempate: quem já tem MAIS pedidos reais deste canal apontando
        // pra ele (histórico de verdade > estoque atual — ver docblock
        // acima). whereIn + groupBy numa query só, em vez de 1 count() por
        // candidato.
        $historyCounts = OrderItem::query()
            ->whereIn('product_id', $tied->pluck('product.id'))
            ->whereHas('order', fn ($query) => $query->where('origin', MarketplaceAccount::CHANNEL_TIKTOK_SHOP))
            ->selectRaw('product_id, count(*) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id');

        $bestHistory = $historyCounts->max() ?? 0;

        if ($bestHistory > 0) {
            $tied = $tied->filter(fn ($row) => ($historyCounts[$row['product']->id] ?? 0) === $bestHistory);
        }

        // Ainda empatado (ou sem histórico nenhum pra nenhum candidato,
        // ex: variações genuinamente novas sem venda prévia) — estoque
        // como último critério, só pra ter alguma resposta determinística.
        return $tied->sortByDesc(fn ($row) => $row['product']->stock)->first()['product'];
    }

    /**
     * $externalOrderId aqui é o número do pedido no TikTok Shop (o que o
     * Bling chama de `numeroLoja`), não um id interno do Bling nem do
     * TikTok — é esse número que fica salvo em orders.external_order_id,
     * pra bater com o que aparece de verdade pro vendedor no painel do
     * TikTok Shop.
     */
    public function importOrder(string $externalOrderId): array
    {
        $order = $this->blingOrders->findByOrderNumber($externalOrderId);

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na loja do TikTok Shop conectada ao Bling.");
        }

        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['itens'] ?? [] as $item) {
            $quantity = (int) ($item['quantidade'] ?? 0);
            $unitPrice = (float) ($item['valor'] ?? 0);
            // Prioriza o código/SKU real do produto — é ele que casa direto
            // com Product::sku (ver autoImportProduct() acima). Cai pro id
            // interno do Bling só se o item não tiver código cadastrado lá.
            $externalId = $item['codigo'] ?? (isset($item['produto']['id']) ? 'BLING-'.$item['produto']['id'] : null);

            if ($externalId === null || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;

            // autoImportProduct() (chamado depois, separado, só com o
            // external_id) não recebe o nome do item — o Bling só expõe
            // isso DENTRO do pedido, não por código sozinho (ver docblock
            // completo lá). Guarda aqui pra ele conseguir consultar.
            if (! empty($item['descricao'])) {
                cache()->put("bling.tiktok_item_name.{$externalId}", $item['descricao'], now()->addDay());
            }

            $items[] = [
                'external_id' => (string) $externalId,
                'external_name' => $item['descricao'] ?? null,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $buyerName = $order['contato']['nome'] ?? 'Comprador TikTok Shop';
        $buyerDocument = isset($order['contato']['numeroDocumento'])
            ? preg_replace('/\D/', '', (string) $order['contato']['numeroDocumento'])
            : null;

        // Telefone/e-mail não vêm no pedido em si (só id/nome/documento do
        // contato) — precisa da chamada complementar, mesmo padrão já
        // usado pra CPF/CNPJ do Mercado Livre/Shopee. Nunca derruba a
        // importação se falhar (mesma cautela dos outros drivers): sem
        // telefone/e-mail o pedido ainda é importável, só com esses campos
        // vazios.
        $contact = null;

        if (isset($order['contato']['id'])) {
            try {
                $contact = $this->blingOrders->findContact((int) $order['contato']['id']);
            } catch (BlingException) {
                $contact = null;
            }
        }

        $address = $order['transporte']['etiqueta'] ?? [];
        $trackingCode = $order['transporte']['volumes'][0]['codigoRastreamento'] ?? null;

        return [
            'external_order_id' => (string) ($order['numeroLoja'] ?? $externalOrderId),
            'status' => $this->mapOrderStatus($order),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round((float) ($order['transporte']['frete'] ?? 0), 2),
            'total' => round((float) ($order['total'] ?? $itemsSubtotal), 2),
            // BUG REAL 2026-08-31 (achado ao vivo — 3 notas rejeitadas pela
            // SEFAZ, "Total da NF difere do somatório dos valores"): quando o
            // TikTok Shop dá cupom/desconto, `total` do Bling já vem COM o
            // desconto aplicado, mas `itens[].valor` continua o preço CHEIO
            // — sem informar discount_amount, o XML monta vProd (soma dos
            // itens, cheio) e vNF (=order.total, já descontado) sem nenhum
            // vDesc pra explicar a diferença, e a SEFAZ recusa a conta.
            // `desconto.valor` do Bling é exatamente esse valor.
            'discount_amount' => round((float) ($order['desconto']['valor'] ?? 0), 2),
            'buyer_name' => $buyerName,
            'buyer_document' => $buyerDocument,
            'buyer_phone' => $contact['celular'] ?? $contact['telefone'] ?? null,
            'buyer_email' => $contact['email'] ?? null,
            'buyer_whatsapp' => null,
            'shipping_zip' => $address['cep'] ?? '00000000',
            'shipping_street' => $address['endereco'] ?? 'Não informado',
            'shipping_number' => $address['numero'] ?? 'S/N',
            'shipping_complement' => $address['complemento'] ?? null,
            'shipping_neighborhood' => $address['bairro'] ?? 'Não informado',
            'shipping_city' => $address['municipio'] ?? 'Não informado',
            'shipping_state' => $address['uf'] ?? 'SP',
            'external_shipment_id' => $trackingCode,
            // Bling só dá a DATA (sem hora) do pedido — mesmo assim melhor
            // que now() pra backfill (ver o mesmo argumento em
            // MercadoLivreDriver::importOrder()/ShopeeDriver::importOrder()
            // sobre created_at errado inflar métricas do dia errado).
            'placed_at' => isset($order['data']) ? \Illuminate\Support\Carbon::parse($order['data'], config('app.timezone')) : null,
            'items' => $items,
        ];
    }

    /**
     * Situações do Bling são personalizáveis por conta (ver
     * BlingOrderService::situacaoName()) — resolve pelo NOME real em vez
     * de um id numérico fixo, que poderia variar de conta pra conta.
     * Default seguro: PAID — pelo momento em que um pedido de marketplace
     * chega até aqui (já sincronizado do TikTok Shop pro Bling), a venda
     * quase sempre já foi paga do lado do canal; só rebaixa pra CANCELLED
     * quando o nome da situação deixa isso explícito.
     */
    private function mapOrderStatus(array $order): string
    {
        $situacaoId = $order['situacao']['id'] ?? null;

        if ($situacaoId === null) {
            return Order::STATUS_PAID;
        }

        try {
            $nome = mb_strtolower($this->blingOrders->situacaoName((int) $situacaoId) ?? '');
        } catch (BlingException) {
            return Order::STATUS_PAID;
        }

        return str_contains($nome, 'cancel') ? Order::STATUS_CANCELLED : Order::STATUS_PAID;
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

    /**
     * Ao contrário do Mercado Livre/Shopee, aqui não CONFIRMAMOS nada —
     * o pickup/entrega é 100% da logística própria do TikTok Shop (o
     * pedido real já vem com `transporte.volumes[0].servico` tipo
     * "LSV-Standard-BR PICKUP", achado ao vivo 2026-08-31), Bling só
     * reflete o que o TikTok já decidiu. Isto é pura CONSULTA (mesmo
     * espírito do comentário original sobre Flex x padrão do Mercado
     * Livre), útil só pra pegar o código de rastreio assim que o TikTok
     * atribuir um.
     */
    public function confirmShipping(Order $order): array
    {
        // BUG REAL 2026-08-31 (achado ao vivo reprocessando o backlog):
        // pedido tiktok_shop sem external_order_id (ex: criado manualmente
        // sem preencher esse campo) estourava TypeError cru aqui em vez de
        // um erro claro — findByOrderNumber() exige string.
        if (! $order->external_order_id) {
            throw new RuntimeException("Pedido #{$order->id} não tem external_order_id — não dá pra consultar o envio no Bling.");
        }

        $blingOrder = $this->blingOrders->findByOrderNumber($order->external_order_id);

        if (! $blingOrder) {
            throw new RuntimeException("Pedido {$order->external_order_id} não encontrado no Bling ao consultar o envio.");
        }

        $volume = $blingOrder['transporte']['volumes'][0] ?? [];
        $trackingCode = $volume['codigoRastreamento'] ?: null;

        return [
            'external_shipment_id' => (string) $blingOrder['id'],
            'tracking_code' => $trackingCode,
            'shipping_method' => $volume['servico'] ?? 'TikTok Shop Logistics',
            'status' => $trackingCode ? 'confirmed' : 'pending',
        ];
    }

    /**
     * `logisticas/etiquetas` real do Bling (ver BlingOrderService::
     * fetchLabel() pro docblock completo, incluindo o aviso de que isto
     * NÃO foi testado contra um PDF de verdade ainda — nenhum pedido da
     * conta do usuário tinha logística cadastrada no momento em que foi
     * escrito). `ready: false` tanto pra "pedido ainda sem rastreio" (não
     * é erro, CheckShipmentLabelJob tenta de novo) quanto pra falha real
     * no download do PDF em si — mesma cautela de nunca derrubar o
     * pipeline por uma etiqueta que só ainda não chegou.
     */
    public function fetchLabel(Order $order): array
    {
        $blingOrder = $this->blingOrders->findByOrderNumber($order->external_order_id);

        if (! $blingOrder) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->blingOrders->fetchLabel((int) $blingOrder['id']);

        if (! $label) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $response = Http::timeout(20)->get($label['link']);

        if ($response->failed()) {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        return [
            'ready' => true,
            'contents' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'application/pdf',
        ];
    }
}
