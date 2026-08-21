<?php

namespace App\Modules\Marketplace\Drivers;

use App\Modules\Catalog\Models\Product;
use App\Modules\Checkout\Models\Order;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\ProductFiscalData;
use App\Modules\Marketplace\Models\ChannelInvoiceSubmission;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\ProductChannelListing;
use App\Services\Shopee\Exceptions\ShopeeException;
use App\Services\Shopee\ShopeeClient;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shopee — API docs: https://open.shopee.com (Open Platform, partner approval required).
 *
 * OAuth2 authorization + HMAC-signed requests. Product publishing goes
 * through v2.product.add_item, images are uploaded first via
 * v2.media_space.upload_image and referenced by image id, stock through
 * v2.product.update_stock.
 */
class ShopeeDriver extends AbstractMarketplaceDriver
{
    public function __construct(private readonly ShopeeClient $client) {}

    public function channel(): string
    {
        return MarketplaceAccount::CHANNEL_SHOPEE;
    }

    /**
     * Lista todos os itens ativos (NORMAL) já publicados na loja Shopee,
     * direto pela API — usado por ShopeeProductImportService pra vincular
     * anúncios já existentes na Shopee (feitos fora do Kazakora) a produtos
     * locais, sem precisar cadastrar tudo nem republicar nada. Confirmado
     * contra a conta real 2026-08-06 (get_item_list + get_item_base_info,
     * paginado — a loja tinha 33 itens, has_next_page=true na primeira
     * página de 20).
     *
     * @return array<int, array{external_id: string, name: string, price: ?float}>
     */
    public function fetchOwnItems(): array
    {
        $this->ensureConfigured();

        $itemIds = [];
        $offset = 0;
        $pageSize = 50;

        do {
            $page = $this->client->get('/api/v2/product/get_item_list', [
                'offset' => $offset,
                'page_size' => $pageSize,
                'item_status' => 'NORMAL',
            ]);

            $items = $page['response']['item'] ?? [];

            foreach ($items as $item) {
                if (isset($item['item_id'])) {
                    $itemIds[] = (int) $item['item_id'];
                }
            }

            $hasNext = (bool) ($page['response']['has_next_page'] ?? false);
            $offset += $pageSize;
        } while ($hasNext);

        $result = [];

        // get_item_base_info aceita no máximo 50 ids por chamada.
        foreach (array_chunk($itemIds, 50) as $chunk) {
            $base = $this->client->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => implode(',', $chunk),
            ]);

            foreach ($base['response']['item_list'] ?? [] as $item) {
                $result[] = [
                    'external_id' => (string) $item['item_id'],
                    'name' => (string) ($item['item_name'] ?? ''),
                    'price' => isset($item['price_info'][0]['current_price'])
                        ? (float) $item['price_info'][0]['current_price']
                        : null,
                ];
            }
        }

        return $result;
    }

    /**
     * SKU real cadastrado na Shopee (`item_sku`, campo padrão do
     * get_item_base_info, não precisa de response_optional_fields) pra um
     * lote de item_id — usado por ShopeeImportMissingProducts pra detectar
     * quando 2 anúncios diferentes são o MESMO produto físico (achado real
     * 2026-08-16: a loja tem 6 pares de anúncio com SKU idêntico, um deles
     * já vinculado a um produto local em cada par) e não criar um segundo
     * produto local pro que já existe. Devolve só os ids que realmente têm
     * SKU preenchido — Shopee permite item sem SKU (vem `""`), esses ficam
     * de fora do mapa em vez de virar uma chave com valor vazio.
     *
     * @param  array<int, string>  $externalIds
     * @return array<string, string> external_id => sku
     */
    public function fetchItemSkus(array $externalIds): array
    {
        $this->ensureConfigured();

        $skus = [];

        foreach (array_chunk($externalIds, 50) as $chunk) {
            $base = $this->client->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => implode(',', $chunk),
            ]);

            foreach ($base['response']['item_list'] ?? [] as $item) {
                $sku = trim((string) ($item['item_sku'] ?? ''));

                if ($sku !== '' && isset($item['item_id'])) {
                    $skus[(string) $item['item_id']] = $sku;
                }
            }
        }

        return $skus;
    }

    /**
     * Mesma chamada base de fetchOwnItems(), só que pra 1 item_id — usado
     * quando um pedido chega com um item ainda não vinculado a nenhum
     * produto local (anúncio feito direto na Shopee, nunca trazido pro
     * Kazakora) e precisa dos dados reais pra importar na hora, sem
     * esperar o próximo `php artisan shopee:import-products` manual. Não
     * lança exceção — item sumido/erro de rede vira null, quem chama
     * decide o que fazer (OrderImportService deixa o pedido sem produto
     * vinculado igual já fazia antes, só não trava o import).
     *
     * Pede o máximo de campo útil que dá numa chamada só (achado real
     * 2026-08-08, pedido #188: a versão anterior só pedia o básico
     * nome/preço, achando por cautela que o resto — principalmente
     * `tax_info` — "a Shopee não manda isso". Errado: ela manda sim quando
     * o vendedor já preencheu no Seller Center, só não vem por padrão sem
     * pedir explicitamente). `weight`/`dimension` e imagens ficam de fora
     * de propósito, não por falta de pedir: `weight` volta sem unidade
     * confirmada (kg vs g ambíguo pro schema documentado) e um valor
     * errado ali estraga cotação de frete real silenciosamente — pior que
     * deixar em branco (o formulário de Logística já lista esses 4 campos
     * como obrigatórios, então a lacuna fica visível); imagens exigem
     * baixar+re-hospedar arquivo de verdade, fora do escopo de "puxar
     * dados do item", tratar como feature própria se for pedida depois.
     *
     * @return ?array{external_id: string, name: string, price: ?float, description: ?string, stock: ?int, tax_info: ?array<string, mixed>, sku: ?string}
     */
    public function fetchItemDetail(string $externalId): ?array
    {
        $this->ensureConfigured();

        try {
            $base = $this->client->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => $externalId,
                'need_tax_info' => 'true',
                'response_optional_fields' => 'tax_info,description,stock_info_v2',
            ]);
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.item_detail.lookup_failed', [
                'external_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        $item = $base['response']['item_list'][0] ?? null;

        if (! $item) {
            return null;
        }

        return [
            'external_id' => (string) $item['item_id'],
            'name' => (string) ($item['item_name'] ?? ''),
            'price' => isset($item['price_info'][0]['current_price'])
                ? (float) $item['price_info'][0]['current_price']
                : null,
            'description' => $item['description'] ?? null,
            'stock' => isset($item['stock_info_v2']['summary_info']['total_available_stock'])
                ? (int) $item['stock_info_v2']['summary_info']['total_available_stock']
                : null,
            'tax_info' => $item['tax_info'] ?? null,
            // SKU real cadastrado na Shopee (`item_sku`, já vem no
            // get_item_base_info sem precisar de response_optional_fields —
            // ver fetchItemSkus()) — usado por autoImportProduct() pra
            // achar um produto local JÁ existente com esse mesmo SKU antes
            // de criar um produto novo (achado real 2026-08-21: pedido
            // criava produto duplicado com SKU sintético "SHOPEE-{id}"
            // mesmo quando o produto certo, com o SKU de verdade, já
            // existia — nunca checava isso).
            'sku' => trim((string) ($item['item_sku'] ?? '')) ?: null,
        ];
    }

    /**
     * Cria um produto local do zero a partir de 1 item da Shopee — chamado
     * por OrderImportService quando um pedido chega pra um anúncio que
     * nunca foi trazido pro catálogo (feito direto na Shopee, fora do
     * Kazakora; ShopeeProductImportService::import() só vincula anúncios a
     * produtos locais JÁ existentes por similaridade de nome, não cria
     * nenhum). Entra sempre como rascunho (is_active=false — preço/categoria
     * ainda precisam de revisão manual). Os dados fiscais (NCM/CFOP/CSOSN
     * etc.) vêm do `tax_info` da Shopee quando o vendedor já preencheu isso
     * lá (ver fetchItemDetail()) — é dado real que o próprio usuário já
     * cadastrou pra emitir nota, não uma invenção; só fica sem dados
     * fiscais mesmo (e soma o aviso pra revisão manual, ver
     * ProductAutoImportedNotification) se a Shopee não tiver NCM preenchido
     * pra esse anúncio.
     *
     * Retorna null (sem lançar) se a Shopee não devolver o item — quem
     * chama já sabia lidar com "sem produto vinculado" antes disso existir,
     * um retorno null só mantém esse mesmo comportamento em vez de travar
     * o pedido inteiro.
     *
     * BUG REAL 2026-08-21: criava produto NOVO (SKU sintético
     * "SHOPEE-{id}") mesmo quando o produto certo já existia no catálogo
     * local com o SKU de verdade (`item_sku`) cadastrado — nunca checava
     * isso antes de criar. Gerava produto duplicado com estoque
     * desconectado do produto real (a baixa de estoque de uma venda caía
     * no duplicado, nunca no produto de verdade). Casa pelo SKU real da
     * Shopee primeiro — só cai pro SKU sintético (cria produto novo mesmo)
     * quando a Shopee não tem SKU cadastrado pra esse anúncio ou quando não
     * existe produto local com esse SKU ainda.
     */
    public function autoImportProduct(string $externalId, int $quantitySold = 0, ?string $externalModelId = null): ?Product
    {
        $item = $this->fetchItemDetail($externalId);

        if (! $item || $item['name'] === '' || $item['price'] === null) {
            return null;
        }

        if ($item['sku'] && $existing = Product::where('sku', $item['sku'])->first()) {
            ProductChannelListing::query()->firstOrCreate(
                ['channel' => MarketplaceAccount::CHANNEL_SHOPEE, 'external_id' => $externalId, 'external_model_id' => $externalModelId],
                ['product_id' => $existing->id, 'is_enabled' => true, 'status' => ProductChannelListing::STATUS_PUBLISHED, 'last_synced_at' => now()],
            );

            return $existing;
        }

        $sku = 'SHOPEE-'.$externalId;
        $slugBase = Str::slug($item['name']);

        // $item['stock'] é o disponível ATUAL na Shopee (já líquido desta
        // própria venda, que acabou de acontecer lá) — soma $quantitySold
        // de volta antes de gravar, porque OrderImportService ainda vai
        // debitar essa mesma quantidade logo em seguida (fluxo normal, sem
        // caso especial); sem isso a venda seria contada 2x. Sem estoque
        // pra puxar (Shopee não devolveu), fica 0 e a baixa seguinte deixa
        // negativo/clampado, disparando OversellDetectedNotification
        // sozinho — mesma rede de segurança de sempre.
        $initialStock = $item['stock'] !== null ? max(0, $item['stock'] + $quantitySold) : 0;

        // BUG REAL 2026-08-17 (pedido 260817JCXFKP1R, Fernando Prado, nunca
        // importado): a causa de verdade não era só a corrida do webhook
        // duplicado (isso também existia, ver ProcessShopeeWebhook) — é que
        // o Product #46 pra esse exato item Shopee (SKU SHOPEE-58265922084)
        // já tinha sido auto-importado antes e foi EXCLUÍDO (soft delete,
        // Product usa SoftDeletes) em 2026-08-16. A linha soft-deletada
        // continua ocupando sku/slug na constraint única do MySQL —
        // invisível pra `Product::where(...)->exists()` (o scope do
        // SoftDeletes filtra trashed por padrão), mas bem real pro
        // `Product::create()`, que sempre falhava. O `while(exists())` de
        // antes pro slug tinha o mesmo ponto cego, e ainda era um
        // `exists()`-então-`create()` TOCTOU (a Shopee reentregou o mesmo
        // webhook 2x em 11s, ambas as execuções passaram no `exists()`
        // antes de qualquer uma comitar). Fix: nunca mais um pré-check —
        // reage à constraint de verdade (sku OU slug) e regenera os dois
        // juntos com um sufixo aleatório, quantas vezes for preciso. Nunca
        // reaproveita/restaura o Product antigo (às vezes uma exclusão foi
        // deliberada) — sempre um SKU e slug novos e válidos.
        $product = null;

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $suffix = $attempt === 1 ? '' : '-'.Str::random(4);

            try {
                $product = Product::create([
                    'sku' => $sku.$suffix,
                    'name' => $item['name'],
                    'slug' => $slugBase.$suffix,
                    'description' => $item['description'] ?: null,
                    'price' => $item['price'],
                    'stock' => $initialStock,
                    'is_active' => false,
                ]);

                break;
            } catch (QueryException $exception) {
                $isUniqueCollision = str_contains($exception->getMessage(), 'products_sku_unique')
                    || str_contains($exception->getMessage(), 'products_slug_unique');

                if ($attempt === 5 || ! $isUniqueCollision) {
                    throw $exception;
                }
            }
        }

        ProductChannelListing::query()->create([
            'product_id' => $product->id,
            'channel' => MarketplaceAccount::CHANNEL_SHOPEE,
            'is_enabled' => true,
            'status' => ProductChannelListing::STATUS_PUBLISHED,
            'external_id' => $externalId,
            'external_model_id' => $externalModelId,
            'last_synced_at' => now(),
        ]);

        $this->importFiscalData($product, $item['tax_info'] ?? null);

        return $product;
    }

    /**
     * Pedido explícito 2026-08-21: reaproveitado por
     * ShopeeSyncFiscalData (`shopee:sync-fiscal-data`) pra fazer o mesmo
     * preenchimento em produtos JÁ existentes (não só recém-auto-importados
     * por autoImportProduct() acima) — mesma regra, mesmo comportamento
     * testado, só exposto como público e devolvendo se deu certo (tem NCM
     * na Shopee) pra quem chama poder reportar quais produtos ficaram sem
     * dado fiscal por falta dele lá na origem.
     *
     * @param  ?array<string, mixed>  $taxInfo
     */
    public function importFiscalData(Product $product, ?array $taxInfo): bool
    {
        $ncm = trim((string) ($taxInfo['ncm'] ?? ''));

        // Sem NCM não tem nota fiscal — os outros campos sozinhos não
        // servem pra nada (mesma checagem que NFeXmlBuilderService faria).
        if ($ncm === '') {
            return false;
        }

        // Achado real 2026-08-08 (pedido #188): a Shopee às vezes deixa
        // pis_cofins_cst vazio no tax_info mesmo com NCM/CFOP/CSOSN
        // preenchidos, e NFeXmlBuilderService rejeita um XML com PIS/COFINS
        // sem CST nenhum. A loja é MEI (confirmado pelo usuário) — MEI não
        // destaca PIS/COFINS por item, fica coberto pelo DAS fixo mensal —
        // então "08" (Operação sem Incidência da Contribuição) é o CST
        // correto pra esse regime, não um palpite genérico; só entra como
        // fallback quando a Shopee não mandou nada.
        $pisCofinsCst = trim((string) ($taxInfo['pis_cofins_cst'] ?? '')) ?: '08';

        ProductFiscalData::query()->updateOrCreate(['product_id' => $product->id], [
            'ncm' => $ncm,
            'origem' => (int) ($taxInfo['origin'] ?? 0),
            'cfop' => (string) ($taxInfo['same_state_cfop'] ?? ''),
            'cfop_outros_estados' => (string) ($taxInfo['diff_state_cfop'] ?? ($taxInfo['same_state_cfop'] ?? '')),
            'icms_situacao_tributaria' => (string) ($taxInfo['csosn'] ?? ''),
            'unidade_tributavel' => (string) ($taxInfo['measure_unit'] ?? 'UN'),
            'pis_situacao_tributaria' => $pisCofinsCst,
            'cofins_situacao_tributaria' => $pisCofinsCst,
            'cest' => trim((string) ($taxInfo['cest'] ?? '')) ?: null,
            // BUG REAL 2026-08-08 (venda travada, pedido nunca nem chegou a
            // ser criado): product_fiscal_data.tipo_operacao é NOT NULL
            // (default 1 na migration) — mandar null explícito aqui vence o
            // default da coluna, e o INSERT inteiro falhava com "Column
            // 'tipo_operacao' cannot be null" sempre que a Shopee não
            // mandava operation_type. Como isso roda DENTRO da mesma
            // transação de OrderImportService::createOrder() (autoImport
            // de produto acontece no meio do processamento do pedido), o
            // erro derrubava o pedido INTEIRO — nada era criado (Order,
            // OrderItem, Product, tudo), e o retry do webhook sempre caía
            // no branch de "corrida de duplicidade" (firstOrFail não achava
            // nada, porque o pedido nunca existiu de verdade), mascarando
            // o erro real como ModelNotFoundException. 1 é o mesmo default
            // que a coluna já usa — nunca manda null.
            'tipo_operacao' => is_numeric($taxInfo['operation_type'] ?? null) ? (int) $taxInfo['operation_type'] : 1,
            'recopi_numero' => trim((string) ($taxInfo['recopi_num'] ?? '')) ?: null,
            'ex_tipi' => trim((string) ($taxInfo['ex_tipi'] ?? '')) ?: null,
            'fci_numero' => trim((string) ($taxInfo['fci_num'] ?? '')) ?: null,
            'informacoes_adicionais' => trim((string) ($taxInfo['additional_info'] ?? '')) ?: null,
        ]);

        return true;
    }

    /**
     * Fotos e vídeo cadastrados no anúncio da Shopee — pedido explícito
     * 2026-08-16: importar pro catálogo local os produtos que ainda não
     * têm nenhuma foto/vídeo próprio (ver App\Console\Commands\
     * ImportShopeeProductMedia). Mesma chamada base de fetchItemDetail(),
     * pedindo os campos opcionais `image`/`video_info` em vez de
     * `tax_info,description,stock_info_v2` — a Shopee só devolve o que for
     * pedido explicitamente em response_optional_fields.
     *
     * video_info vem como lista (Shopee permite mais de um vídeo por
     * anúncio em algumas contas) — usa só o primeiro, já que Product só
     * tem 1 vídeo (video_path). Não lança exceção: item sem mídia, ou
     * chamada com erro, vira listas vazias — quem chama decide se isso é
     * "nada a importar" ou tenta de novo depois.
     *
     * @return array{images: array<int, string>, video: ?array{url: string, duration: ?int}}
     */
    public function fetchItemMedia(string $externalId): array
    {
        $this->ensureConfigured();

        try {
            $base = $this->client->get('/api/v2/product/get_item_base_info', [
                'item_id_list' => $externalId,
                'response_optional_fields' => 'image,video_info',
            ]);
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.item_media.lookup_failed', [
                'external_id' => $externalId,
                'message' => $exception->getMessage(),
            ]);

            return ['images' => [], 'video' => null];
        }

        $item = $base['response']['item_list'][0] ?? null;

        if (! $item) {
            return ['images' => [], 'video' => null];
        }

        $images = array_values(array_filter((array) ($item['image']['image_url_list'] ?? [])));

        $video = null;
        $videoInfo = $item['video_info'][0] ?? null;

        if ($videoInfo && ! empty($videoInfo['video_url'])) {
            $video = [
                'url' => (string) $videoInfo['video_url'],
                'duration' => isset($videoInfo['duration']) ? (int) $videoInfo['duration'] : null,
            ];
        }

        return ['images' => $images, 'video' => $video];
    }

    public function publishProduct(Product $product, ProductChannelListing $listing): string
    {
        $this->ensureConfigured();

        // TODO: upload $product->images via v2.media_space.upload_image,
        // then call v2.product.add_item with the returned image ids and
        // the category-specific attributes from $listing->attributes.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function updateStock(Product $product, ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.update_stock with $product->stock.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    public function unpublishProduct(ProductChannelListing $listing): void
    {
        $this->ensureConfigured();

        // TODO: call v2.product.unlist_item.
        throw new \RuntimeException('Integração com Shopee ainda não implementada.');
    }

    /**
     * order_sn de todos os pedidos num intervalo de datas — usado pelo
     * backfill (App\Console\Commands\SyncShopeeOrders, 2026-08-06, refeito
     * no mesmo dia pra escopar por data em vez de trazer o histórico
     * inteiro — pedido explícito do usuário). get_order_list só aceita
     * janelas de até 15 dias por chamada (confirmado ao vivo — erro
     * explícito da API acima disso), então varre $from..$to em fatias de
     * 15 dias. Paginação dentro de cada fatia via next_cursor/more.
     *
     * @return array<int, string>
     */
    public function listOrderSns(\Carbon\CarbonInterface $from, \Carbon\CarbonInterface $to): array
    {
        $this->ensureConfigured();

        $sns = [];
        $sliceStart = $from->copy();

        while ($sliceStart->lt($to)) {
            $sliceEnd = $sliceStart->copy()->addDays(15)->min($to);
            $cursor = '';

            do {
                $page = $this->client->get('/api/v2/order/get_order_list', array_filter([
                    'time_range_field' => 'create_time',
                    'time_from' => $sliceStart->timestamp,
                    'time_to' => $sliceEnd->timestamp,
                    'page_size' => 50,
                    'cursor' => $cursor,
                ], fn ($value) => $value !== ''));

                $orders = $page['response']['order_list'] ?? [];

                foreach ($orders as $order) {
                    if (isset($order['order_sn'])) {
                        $sns[] = (string) $order['order_sn'];
                    }
                }

                $more = (bool) ($page['response']['more'] ?? false);
                $cursor = (string) ($page['response']['next_cursor'] ?? '');
            } while ($more && $cursor !== '');

            $sliceStart = $sliceEnd;
        }

        return array_values(array_unique($sns));
    }

    /**
     * `v2.order.get_order_detail` — confirmado ao vivo 2026-08-06 contra
     * 93 pedidos reais da loja conectada; os nomes de campo abaixo batem
     * com o payload de verdade, não só com a doc.
     */
    public function importOrder(string $externalOrderId): array
    {
        $this->ensureConfigured();

        $response = $this->client->get('/api/v2/order/get_order_detail', [
            'order_sn_list' => $externalOrderId,
            // buyer_cpf_id adicionado 2026-08-06 — achado real: pedidos
            // Shopee reais ficavam presos pra sempre no pipeline (nota
            // fiscal nunca emitida por falta de CPF/CNPJ do comprador,
            // etiqueta nunca liberada por consequência, já que a Shopee
            // exige a nota antes — ver ConfirmChannelShippingJob) porque
            // esse campo nunca era pedido à API, mesmo a Shopee mandando o
            // CPF de verdade quando solicitado (confirmado ao vivo contra
            // um pedido real).
            'response_optional_fields' => 'buyer_username,recipient_address,item_list,total_amount,order_status,estimated_shipping_fee,actual_shipping_fee,buyer_cpf_id',
        ]);

        $order = $response['response']['order_list'][0] ?? null;

        if (! $order) {
            throw new RuntimeException("Pedido {$externalOrderId} não encontrado na Shopee.");
        }

        $address = $order['recipient_address'] ?? [];
        $itemsSubtotal = 0.0;
        $items = [];

        foreach ($order['item_list'] ?? [] as $item) {
            $externalItemId = (string) ($item['item_id'] ?? '');
            $quantity = (int) ($item['model_quantity_purchased'] ?? 0);
            $unitPrice = (float) ($item['model_discounted_price'] ?? $item['model_original_price'] ?? 0);

            if ($externalItemId === '' || $quantity < 1) {
                continue;
            }

            $itemsSubtotal += $unitPrice * $quantity;
            // external_name é o nome real do anúncio na Shopee (item_name)
            // + a variação (model_name, quando existe e não é o valor
            // padrão "-" que a Shopee usa pra produto sem variação de
            // verdade) — usado como fallback pelo OrderImportService
            // quando o item ainda não tem produto local mapeado (achado
            // real 2026-08-06, mesmo gap existia no driver do Mercado
            // Livre — corrigido nos dois juntos).
            $itemName = (string) ($item['item_name'] ?? '');
            $modelName = (string) ($item['model_name'] ?? '');
            $externalName = $modelName !== '' && $modelName !== '-'
                ? trim("{$itemName} - {$modelName}")
                : ($itemName ?: null);

            // Achado real 2026-08-15 (Ring Light 8" vs 10", pedido #376):
            // item_id é o anúncio INTEIRO — quando ele tem variação de
            // verdade, a Shopee manda model_id/model_sku por item do
            // PEDIDO (não por anúncio), únicos por variação. Sem isso,
            // qualquer anúncio com variação sempre resolvia pro mesmo
            // produto local (o que por acaso tinha o listing cadastrado),
            // não importa qual variação o cliente realmente comprou —
            // errava SKU, imagem e (mais grave) baixava estoque do produto
            // errado. "0" é o valor da Shopee pra "sem variação de
            // verdade" (mesmo padrão de model_name="-"), tratado como null
            // pra não criar um external_model_id falso em produto sem
            // variação nenhuma.
            $modelId = (string) ($item['model_id'] ?? '0');

            $items[] = [
                'external_id' => $externalItemId,
                'external_model_id' => $modelId !== '' && $modelId !== '0' ? $modelId : null,
                'external_name' => $externalName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
            ];
        }

        $shippingCost = (float) ($order['actual_shipping_fee'] ?? $order['estimated_shipping_fee'] ?? 0);

        // Achado real 2026-08-06 (rejeição 610 da SEFAZ investigando o
        // pedido #180 revelou isso): total_amount da Shopee é só o valor
        // dos ITENS, nunca inclui o frete (confirmado contra o payload
        // real — total_amount idêntico ao subtotal dos itens mesmo com
        // actual_shipping_fee > 0). O código antes preferia total_amount
        // (sempre presente, então o fallback nunca disparava) e todo
        // pedido Shopee com frete ficava sub-contado no total — impacto
        // financeiro real, não só um detalhe de nota fiscal. Sempre
        // recalculamos aqui, nunca confiamos em total_amount pra isso.
        $total = round($itemsSubtotal + $shippingCost, 2);

        // Pedido explícito 2026-08-09: taxa real da Shopee (comissão +
        // taxa de serviço) pro painel de lucro líquido — confirmado ao vivo
        // contra v2.payment.get_escrow_detail (pedido real 260810009CGXBN:
        // commission_fee=8.82, service_fee=4.98). O escrow só fecha depois
        // que a Shopee processa o pedido — pedido recém-criado (READY_TO_
        // SHIP) pode não ter isso pronto ainda, e como este método roda de
        // novo a cada sync horário (ver SyncShopeeOrders), uma falha aqui
        // não é definitiva: só tenta de novo na próxima passada. Nunca
        // inventa um valor — sem key 'marketplace_fee' no retorno, o
        // OrderImportService simplesmente não grava OrderChannelFee ainda
        // pra este pedido (mesmo padrão que os outros campos "quando
        // disponível" deste driver).
        $marketplaceFee = $this->resolveMarketplaceFee((string) ($order['order_sn'] ?? $externalOrderId));

        return [
            'external_order_id' => (string) ($order['order_sn'] ?? $externalOrderId),
            'status' => $this->mapOrderStatus((string) ($order['order_status'] ?? '')),
            // Status BRUTO da Shopee, sem passar por mapOrderStatus() —
            // achado real 2026-08-15: TO_RETURN (devolução de verdade,
            // produto já enviado) colapsa pro mesmo Order::STATUS_CANCELLED
            // genérico (decisão certa pro ciclo de vida do pedido, ver
            // comentário em mapOrderStatus()), mas isso sozinho deixava o
            // card "Devoluções do mês" cego pra qualquer devolução da
            // Shopee — repassado pra OrderImportService poder registrar
            // isso separadamente (ver recordReturnClaimIfNeeded()) sem
            // duplicar/mexer no mapeamento de status em si.
            'channel_status' => (string) ($order['order_status'] ?? ''),
            'subtotal' => round($itemsSubtotal, 2),
            'shipping_cost' => round($shippingCost, 2),
            'total' => round($total, 2),
            'buyer_name' => $address['name'] ?? ($order['buyer_username'] ?? 'Comprador Shopee'),
            'buyer_phone' => $address['phone'] ?? null,
            // Sem isso, InvoiceService::issue() sempre falhava com "não foi
            // possível identificar o CPF/CNPJ do comprador" — nunca emitia
            // nota, nunca liberava etiqueta na Shopee (achado real
            // 2026-08-06, ver comentário em response_optional_fields acima).
            'buyer_document' => isset($order['buyer_cpf_id']) ? preg_replace('/\D/', '', (string) $order['buyer_cpf_id']) : null,
            // BUG REAL 2026-08-15 (pedido #370): `??` só cai no fallback
            // quando a chave é null/ausente — a Shopee mandou 'district'
            // como STRING VAZIA (não ausente) pra esse pedido, então
            // "Não informado" nunca entrava, shipping_neighborhood ficava
            // realmente vazio, e a NF-e rejeitava na hora de montar o XML
            // ("Preenchimento Obrigatório! [xBairro]") — pedido nunca
            // conseguia nota nem etiqueta. Trocado pra `(?? '') ?:` nos
            // campos de endereço — vazio E ausente caem no mesmo fallback
            // agora (só `?:` sozinho geraria warning de índice ausente
            // quando a chave nem existe, por isso o `?? ''` antes) — mesma
            // classe de bug já vista aqui antes (telefone mascarado,
            // buyer_cpf_id ausente).
            'shipping_zip' => ($address['zipcode'] ?? '') ?: '00000000',
            'shipping_street' => ($address['full_address'] ?? '') ?: 'Não informado',
            'shipping_number' => 'S/N',
            'shipping_complement' => null,
            'shipping_neighborhood' => ($address['district'] ?? '') ?: 'Não informado',
            'shipping_city' => ($address['city'] ?? '') ?: 'Não informado',
            'shipping_state' => $this->extractState(($address['state'] ?? '') ?: 'NA'),
            'external_shipment_id' => null,
            // Mesmo motivo do MercadoLivreDriver — create_time é a data real
            // da venda na Shopee (unix timestamp, campo base do
            // get_order_detail), usado como created_at real do pedido em vez
            // de now() no backfill.
            //
            // Segundo argumento (timezone) NÃO é opcional na prática: achado
            // real 2026-08-13, Carbon::createFromTimestamp() (Carbon 3, ver
            // Traits\Timestamp) só converte pro timezone padrão do PHP
            // quando você passa um — sem ele, o objeto fica em UTC e é
            // gravado assim (Eloquent formata na timezone que o Carbon já
            // tem, nunca converte sozinho), 3h à FRENTE da hora real de São
            // Paulo. Resultado ao vivo: pedido feito ontem à noite virava
            // "hoje de madrugada" no banco e vazava pra fila "só hoje" do
            // KoraSync (DashboardAgentController::queue()) — provavelmente a
            // origem real dos pedidos de outro dia aparecendo na fila,
            // já que Shopee é canal ativo (ao contrário do Amazon, ainda em
            // sandbox).
            'placed_at' => isset($order['create_time'])
                ? \Illuminate\Support\Carbon::createFromTimestamp((int) $order['create_time'], config('app.timezone'))
                : null,
            'items' => $items,
            ...($marketplaceFee !== null ? ['marketplace_fee' => $marketplaceFee] : []),
        ];
    }

    /**
     * `commission_fee` + `service_fee` do escrow — os dois valores que a
     * Shopee de fato desconta do vendedor por usar a plataforma (frete e
     * promoções são componentes separados, não entram aqui). Retorna null
     * (nunca 0.0) quando o escrow ainda não fechou ou a chamada falha —
     * distinção importante pro chamador saber "sem dado" de "taxa zero".
     *
     * Público (era privado) desde 2026-08-14 — pedido explícito do
     * usuário auditando o dashboard financeiro: achado real que 57
     * pedidos Shopee (integração de taxa real só entrou em 2026-08-09,
     * ver OrderImportService) nunca tiveram taxa gravada, inflando o
     * lucro líquido mostrado. O escrow continua disponível pra pedido
     * antigo já liquidado (confirmado ao vivo) — reaproveitado por
     * BackfillShopeeOrderFeesCommand pra buscar a taxa real retroativa em
     * vez de estimar.
     */
    public function resolveMarketplaceFee(string $orderSn): ?float
    {
        try {
            $response = $this->client->get('/api/v2/payment/get_escrow_detail', ['order_sn' => $orderSn]);
            $income = $response['response']['order_income'] ?? null;

            if (! $income || ! isset($income['commission_fee'], $income['service_fee'])) {
                return null;
            }

            return round((float) $income['commission_fee'] + (float) $income['service_fee'], 2);
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.escrow_detail.lookup_failed', ['order_sn' => $orderSn, 'message' => $exception->getMessage()]);

            return null;
        }
    }

    /**
     * Vocabulário real de `order_status` da Shopee: UNPAID, READY_TO_SHIP,
     * PROCESSED, SHIPPED, TO_CONFIRM_RECEIVE, COMPLETED, CANCELLED,
     * TO_RETURN, IN_CANCEL. Mapeamento conservador — qualquer coisa não
     * reconhecida cai em "aguardando pagamento" em vez de assumir que já
     * foi pago (mesma cautela que MercadoLivreDriver::mapOrderStatus() já
     * aplica).
     *
     * BUG REAL corrigido 2026-08-07: `TO_CONFIRM_RECEIVE` estava mapeado
     * pra `STATUS_PAID` — errado, esse status só existe DEPOIS do produto
     * já ter sido enviado (é o comprador confirmando que recebeu, não uma
     * venda nova). Pro pedido #158 (real), esse webhook chegou depois do
     * pedido já estar `shipped`, e o mapeamento pra `paid` fez o status
     * REGREDIR (shipped → paid) — como OrderImportService::syncStatus()
     * dispara ConfirmChannelShippingJob/GenerateInvoiceJob sempre que o
     * status novo é `paid` e o antigo não era, isso reprocessou envio e
     * nota fiscal de um pedido que já tinha etiqueta impressa há dias.
     * Corrigido pro mapeamento certo (`SHIPPED`) — ver também a trava geral
     * em OrderImportService::syncStatus() contra qualquer regressão de
     * status, que blinda esse mesmo tipo de bug pra qualquer canal, não só
     * a Shopee.
     */
    private function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'READY_TO_SHIP', 'PROCESSED' => Order::STATUS_PAID,
            'SHIPPED', 'TO_CONFIRM_RECEIVE' => Order::STATUS_SHIPPED,
            'COMPLETED' => Order::STATUS_COMPLETED,
            'CANCELLED', 'IN_CANCEL', 'TO_RETURN' => Order::STATUS_CANCELLED,
            default => Order::STATUS_AWAITING_PAYMENT,
        };
    }

    /**
     * `shipping_state` na tabela orders é varchar(2) (limite que já mordeu
     * o Mercado Livre uma vez, que devolvia "BR-SP" em vez da sigla — ver
     * histórico). Achado real 2026-08-06, rodando o backfill contra pedidos
     * reais: a Shopee devolve o NOME COMPLETO do estado ("São Paulo"), não
     * a sigla — e `substr($state, 0, 2)` num nome acentuado corta no meio
     * do caractere multi-byte de "ã"/"é"/etc., gerando um byte UTF-8
     * inválido que o MySQL rejeita na hora do insert (33 de 93 pedidos
     * reais falharam com "Incorrect string value" antes desse fix). Mapa
     * nome completo → sigla, comparado sem acento (evita depender de a
     * Shopee mandar sempre com a acentuação "certa"); mb_substr como
     * fallback pra qualquer coisa não reconhecida, pelo menos não quebra
     * o insert mesmo se vier um valor novo/inesperado.
     */
    private const STATE_NAMES_TO_UF = [
        'ACRE' => 'AC', 'ALAGOAS' => 'AL', 'AMAPA' => 'AP', 'AMAZONAS' => 'AM',
        'BAHIA' => 'BA', 'CEARA' => 'CE', 'DISTRITO FEDERAL' => 'DF',
        'ESPIRITO SANTO' => 'ES', 'GOIAS' => 'GO', 'MARANHAO' => 'MA',
        'MATO GROSSO' => 'MT', 'MATO GROSSO DO SUL' => 'MS', 'MINAS GERAIS' => 'MG',
        'PARA' => 'PA', 'PARAIBA' => 'PB', 'PARANA' => 'PR', 'PERNAMBUCO' => 'PE',
        'PIAUI' => 'PI', 'RIO DE JANEIRO' => 'RJ', 'RIO GRANDE DO NORTE' => 'RN',
        'RIO GRANDE DO SUL' => 'RS', 'RONDONIA' => 'RO', 'RORAIMA' => 'RR',
        'SANTA CATARINA' => 'SC', 'SAO PAULO' => 'SP', 'SERGIPE' => 'SE',
        'TOCANTINS' => 'TO',
    ];

    private function extractState(string $state): string
    {
        $normalized = strtoupper(trim(Str::ascii($state)));

        if (isset(self::STATE_NAMES_TO_UF[$normalized])) {
            return self::STATE_NAMES_TO_UF[$normalized];
        }

        return mb_substr($normalized, 0, 2) ?: 'NA';
    }

    /**
     * `v2.order.upload_invoice_doc` (POST /api/v2/order/upload_invoice_doc),
     * multipart: order_sn, file_type=4 (XML), file. Aceita o XML da NF-e
     * direto, sem precisar do DANFE. Endpoint específico BR (e PH),
     * confirmado no schema oficial da Open Platform.
     */
    public function submitInvoice(Order $order, Invoice $invoice): array
    {
        $this->ensureConfigured();

        if (! $invoice->xml_path) {
            throw new RuntimeException('Nota fiscal sem XML disponível para envio.');
        }

        $xml = Storage::disk('local')->get($invoice->xml_path);
        $filename = "nfe-{$invoice->chave_acesso}.xml";

        try {
            $response = $this->client->postMultipart(
                '/api/v2/order/upload_invoice_doc',
                ['order_sn' => $order->external_order_id, 'file_type' => 4],
                ['contents' => $xml, 'filename' => $filename],
            );

            return ['status' => 'sent', 'external_reference' => $order->external_order_id, 'response' => $response];
        } catch (ShopeeException $exception) {
            return ['status' => 'error', 'external_reference' => null, 'response' => ['error' => $exception->getMessage(), 'context' => $exception->context]];
        }
    }

    /**
     * Padrão aceito como "Expresso" no nome do transportador que a Shopee
     * devolve em `shipping_carrier` (ex: "SPX Express", "Entrega Expressa").
     * A loja só opera com Shopee Express habilitado no Seller Center — o
     * comprador escolhe a transportadora no checkout da Shopee, o vendedor
     * não escolhe via API (get_shipping_parameter só junta o que falta pra
     * UM transportador já decidido, não deixa trocar). Esse regex é uma
     * checagem defensiva de que a suposição continua válida, não uma
     * seleção — se um pedido vier com outro transportador, o envio ainda é
     * confirmado normalmente (não vale travar uma venda real por causa
     * disso), só fica registrado no log e na linha do tempo do pedido pra
     * o usuário perceber e investigar no Seller Center.
     */
    private const EXPRESS_CARRIER_PATTERN = '/express|expresso|expressa|spx/i';

    /**
     * get_shipping_parameter diz quais métodos o pedido suporta e, pro
     * dropoff, devolve os branch_id disponíveis — usa o primeiro (conta com
     * um único ponto de coleta configurado não precisa de escolha real).
     * ship_order confirma de fato o método.
     *
     * BUG REAL corrigido 2026-08-07 (pedido #183, achado ao vivo — venda
     * ficou travada sem etiqueta, painel da Shopee mostrando "Organizar
     * Envio" ainda pendente enquanto o Kazakora achava que já estava
     * confirmado): a versão anterior deste método pulava ship_order sempre
     * que `logistics_status` não era `LOGISTICS_PENDING` — heurística
     * criada a partir de 3 vendas anteriores (#180/#181/#182) onde
     * `LOGISTICS_READY`/`LOGISTICS_REQUEST_CREATED` de fato já significavam
     * "feito". Pro pedido #183 isso era falso: `logistics_status` veio
     * `LOGISTICS_READY` MESMO SEM ship_order nunca ter sido chamado —
     * chamando manualmente ao vivo pra diagnosticar, o status só avançou
     * de verdade pra `LOGISTICS_REQUEST_CREATED` depois disso. Ou seja,
     * `LOGISTICS_READY` não é garantia de nada, invalidando a heurística.
     *
     * Correção: chama ship_order SEMPRE, sem pular com base em status. Pro
     * caso em que já estava mesmo feito (as 3 vendas originais que
     * motivaram a heurística errada), a Shopee rejeita a chamada
     * ("nenhum branch_id disponível"/"not eligible for rescheduling") —
     * isso é tratado como não-erro aqui (só loga), porque quem realmente
     * decide se a etiqueta sai ou não é get_shipping_document_result via
     * CheckShipmentLabelJob, não esse retorno.
     */
    public function confirmShipping(Order $order): array
    {
        $this->ensureConfigured();

        // Achado real 2026-08-08 (pedido #188): a Shopee exige a NF-e já
        // enviada pra liberar o envio de verdade — confirmado no próprio
        // texto que ela devolve quando isso falta ("...flagged as invalid
        // by SEFAZ. Correction is required to release the shipment"), que
        // o código antigo tratava como "ship_order redundante" igual às 3
        // vendas que motivaram aquela heurística (ver comentário abaixo),
        // silenciando o erro real e deixando CheckShipmentLabelJob tentando
        // buscar uma etiqueta que nunca ia existir por até 4h sem avisar
        // ninguém. Checar isso ANTES de chamar ship_order evita depender de
        // reconhecer o texto exato do erro da Shopee (o mesmo tipo de
        // heurística frágil que já deu errado uma vez, ver #183 abaixo) —
        // é uma regra de negócio já confirmada, não um palpite sobre a
        // mensagem de erro. ChannelShippingService::confirm() já sabe
        // tratar essa exceção (marca o shipment como erro e NÃO dispara
        // CheckShipmentLabelJob) e ConfirmChannelShippingJob já tem retry
        // de até ~3h com backoff crescente — janela suficiente pra nota
        // fiscal terminar de processar em paralelo.
        $invoiceSubmitted = ChannelInvoiceSubmission::query()
            ->where('order_id', $order->id)
            ->where('channel', $this->channel())
            ->where('status', ChannelInvoiceSubmission::STATUS_SENT)
            ->exists();

        if (! $invoiceSubmitted) {
            throw new RuntimeException("Shopee exige a nota fiscal enviada antes de liberar o envio do pedido #{$order->id}, e ela ainda não foi enviada ao canal.");
        }

        $carrier = $this->resolveShippingCarrier($order);

        // BUG REAL 2026-08-08 (pedido #200): confirmShipping() pode ser
        // chamado mais de uma vez pro mesmo pedido de propósito (retry do
        // próprio job + o gatilho novo de "reconfirma assim que a nota é
        // aceita", ver commit 831521b) — isso é intencional (idempotente
        // por design, ship_order redundante já é tolerado logo abaixo).
        // Mas get_shipping_parameter (chamada ANTES de ship_order) não
        // tinha essa tolerância: numa segunda chamada, depois que o envio
        // já estava totalmente arranjado, a Shopee rejeitava com "Package
        // ... not eligible for rescheduling" — isso não é try/catch'd, o
        // erro subia inteiro, ChannelShippingService::confirm() marcava o
        // shipment como 'error' (sobrescrevendo o 'confirmed' que já
        // existia de verdade), mesmo o envio já estando 100% arranjado do
        // lado da Shopee. Mesma tolerância do ship_order abaixo: se essa
        // consulta falhar, segue sem escolha de branch (a Shopee decide
        // sozinha, mesmo comportamento de quando branch_list vem vazio,
        // ver comentário de 2026-08-07 logo abaixo) em vez de travar tudo.
        try {
            $params = $this->client->get('/api/v2/logistics/get_shipping_parameter', [
                'order_sn' => $order->external_order_id,
            ]);
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->info('shopee.get_shipping_parameter.rejected_likely_already_done', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'message' => $exception->getMessage(),
            ]);

            $params = [];
        }

        $branchId = $params['response']['info_needed']['dropoff']['branch_list'][0]['branch_id'] ?? null;

        // Achado real 2026-08-07 (pedido #182): branch_list vem null/vazio
        // quando não há ponto de coleta pra escolher — não é um erro, é só
        // que essa venda/transportadora não precisa de escolha de branch
        // (confirmado testando ao vivo: ship_order com dropoff VAZIO
        // funciona igual, a Shopee que decide o ponto sozinha). Antes disso
        // travava aqui achando que faltava dado, quando na verdade só
        // faltava mandar o dropoff sem branch_id.
        $dropoff = $branchId ? ['branch_id' => $branchId] : new \stdClass();

        try {
            $this->client->post('/api/v2/logistics/ship_order', [
                'order_sn' => $order->external_order_id,
                'dropoff' => $dropoff,
            ]);
        } catch (ShopeeException $exception) {
            // Sinal conhecido de "já estava feito" (as 3 vendas que
            // motivaram a heurística errada acima sempre caíam aqui) — não
            // é uma falha real do envio, só ship_order sendo redundante.
            Log::channel('shopee')->info('shopee.ship_order.rejected_likely_already_done', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'message' => $exception->getMessage(),
            ]);
        }

        return [
            'external_shipment_id' => $order->external_order_id,
            'tracking_code' => $this->resolveTrackingNumber($order),
            'shipping_method' => $carrier ?? 'drop_off',
            'status' => 'confirmed',
        ];
    }

    /**
     * Endpoint dedicado (não é o mesmo que get_tracking_info, que só traz o
     * status/histórico) — pedido explícito 2026-08-06: arquivar o zip da
     * etiqueta localmente usando o código de rastreio como nome do arquivo.
     * Não lança exceção: o rastreio pode ainda não existir no instante em
     * que ship_order acabou de confirmar (Shopee gera de forma assíncrona
     * em alguns casos) — nesse caso fica null e o arquivamento local só
     * ganha o nome real na próxima vez que confirmShipping rodar pra esse
     * pedido (CheckShipmentLabelJob mantém tentando via retry).
     */
    private function resolveTrackingNumber(Order $order): ?string
    {
        try {
            $response = $this->client->get('/api/v2/logistics/get_tracking_number', [
                'order_sn' => $order->external_order_id,
            ]);

            return $response['response']['tracking_number'] ?? null;
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.tracking_number.lookup_failed', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Consulta `shipping_carrier` no detalhe do pedido e loga um alerta se
     * não bater com o padrão "Expresso" esperado — ver comentário de
     * EXPRESS_CARRIER_PATTERN. Nunca lança exceção: uma falha aqui (campo
     * ausente, erro de rede) não pode derrubar a confirmação de envio real,
     * só faz a verificação virar "não verificado" em vez de travar o
     * pedido.
     */
    private function resolveShippingCarrier(Order $order): ?string
    {
        try {
            $response = $this->client->get('/api/v2/order/get_order_detail', [
                'order_sn_list' => $order->external_order_id,
                'response_optional_fields' => 'shipping_carrier',
            ]);

            $carrier = $response['response']['order_list'][0]['shipping_carrier'] ?? null;
        } catch (ShopeeException $exception) {
            Log::channel('shopee')->warning('shopee.shipping_carrier.lookup_failed', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }

        if ($carrier && ! preg_match(self::EXPRESS_CARRIER_PATTERN, $carrier)) {
            Log::channel('shopee')->warning('shopee.shipping_carrier.not_express', [
                'order_id' => $order->id,
                'order_sn' => $order->external_order_id,
                'carrier' => $carrier,
            ]);
        }

        return $carrier;
    }

    /**
     * 3 chamadas: create_shipping_document cria a tarefa (só na PRIMEIRA
     * tentativa) → get_shipping_document_result até status=READY
     * (processamento assíncrono do lado da Shopee) → download_shipping_document
     * devolve o binário. `ready: false` enquanto ainda está PROCESSING.
     *
     * Achado real 2026-08-07 (pedido #182): create_shipping_document NÃO é
     * seguro chamar de novo depois que o documento já existe — descrito
     * como "idempotente" mas repetir a chamada (CheckShipmentLabelJob tenta
     * de 5 em 5s) quebrava um documento que já estava com status=READY,
     * sempre devolvendo "package_can_not_print"/"All failed" mesmo com o
     * documento pronto pra baixar. Confirmado ao vivo: chamando só
     * get_shipping_document_result (sem create) direto, o status já vinha
     * READY e o download funcionou na hora. Agora só chama create quando
     * get_shipping_document_result falha (documento ainda nem existe).
     */
    public function fetchLabel(Order $order): array
    {
        $this->ensureConfigured();

        try {
            $result = $this->client->post('/api/v2/logistics/get_shipping_document_result', [
                'order_list' => [['order_sn' => $order->external_order_id]],
            ]);
        } catch (ShopeeException) {
            // Documento ainda não existe — cria a tarefa e volta "não
            // pronto ainda" pra esse ciclo, deixa o retry natural do
            // CheckShipmentLabelJob (5s) consultar de novo em seguida, sem
            // criar duas vezes.
            //
            // BUG REAL 2026-08-12 (pedido #247): esse create_shipping_document
            // também pode ser rejeitado pela Shopee (confirmado ao vivo:
            // "common.batch_api_all_failed" / "tracking_number_invalid" —
            // o rastreio ainda não tinha sido atribuído nesse instante,
            // condição transitória, não permanente). Sem try/catch aqui,
            // essa segunda chamada propagava um ShopeeException cru pra
            // fora de fetchLabel(), em vez do "não pronto ainda" limpo que
            // o resto do pipeline já sabe tratar — o retry natural do
            // CheckShipmentLabelJob ainda acontecia (Throwable genérico não
            // impede o backoff do Laravel), só com um erro mais confuso nos
            // logs/failed_jobs no meio do caminho. Trata igual: se a
            // criação também falhar, ainda é "não pronto", não um erro
            // definitivo.
            try {
                $this->client->post('/api/v2/logistics/create_shipping_document', [
                    'order_list' => [['order_sn' => $order->external_order_id]],
                ]);
            } catch (ShopeeException) {
                // Ignorado de propósito — ver comentário acima.
            }

            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $status = $result['response']['result_list'][0]['status'] ?? null;

        if ($status !== 'READY') {
            return ['ready' => false, 'contents' => null, 'content_type' => null];
        }

        $label = $this->client->getBinary('/api/v2/logistics/download_shipping_document', [
            'order_list' => [['order_sn' => $order->external_order_id]],
            'shipping_document_type' => 'THERMAL_AIR_WAYBILL',
        ]);

        return ['ready' => true, 'contents' => $label['contents'], 'content_type' => $label['content_type']];
    }

    // fetchContentDeclaration() removido 2026-08-21 (mesmo dia): era um
    // chute (shipping_document_type=NON_INTEGRATED_INVOICE numa 2ª chamada
    // à API, sem confirmação contra doc oficial) pra baixar a declaração
    // separada. Confirmado pelo usuário que NÃO precisa disso — o zip que
    // fetchLabel() já baixa (download_shipping_document normal, ver acima)
    // VEM com os dois arquivos juntos: o .txt do ZPL da etiqueta térmica E
    // um PDF da declaração de conteúdo, no mesmo zip. Ver
    // LabelFetchService::extractShopeeZipContents() — extrai os dois do
    // mesmo download, sem chamada extra nenhuma.

    /**
     * `v2.product.get_comment` — nota (rating_star, 1-5), comentário, nome
     * do comprador (buyer_username, já vem mascarado pela própria Shopee
     * quando aplicável — não é mascaramento nosso) e mídia anexada
     * (media.image_url_list/video_url_list) por item_id. Paginação por
     * cursor, mesmo padrão de listOrderSns() (more/next_cursor).
     *
     * Uma falha de rede numa página no meio da varredura devolve o que já
     * foi coletado até ali em vez de derrubar tudo — a próxima execução do
     * cron (reviews:sync) completa o resto; melhor um resultado parcial
     * hoje do que nada até a próxima falha não acontecer de novo.
     */
    public function fetchReviews(string $externalId): array
    {
        $this->ensureConfigured();

        $reviews = [];
        $cursor = '';

        do {
            try {
                $page = $this->client->get('/api/v2/product/get_comment', array_filter([
                    'item_id' => $externalId,
                    'cursor' => $cursor,
                    'page_size' => 100,
                ], fn ($value) => $value !== ''));
            } catch (ShopeeException $exception) {
                Log::channel('shopee')->warning('shopee.get_comment.failed', [
                    'external_id' => $externalId,
                    'message' => $exception->getMessage(),
                ]);

                break;
            }

            foreach ($page['response']['item_comment_list'] ?? [] as $comment) {
                $commentId = (string) ($comment['comment_id'] ?? '');
                $rating = (int) ($comment['rating_star'] ?? 0);

                if ($commentId === '' || $rating < 1 || $rating > 5) {
                    continue;
                }

                $reviews[] = [
                    'external_id' => $commentId,
                    'rating' => $rating,
                    'comment' => trim((string) ($comment['comment'] ?? '')) ?: null,
                    'reviewer_name' => trim((string) ($comment['buyer_username'] ?? '')) ?: 'Cliente Shopee',
                    'images' => array_values(array_filter((array) ($comment['media']['image_url_list'] ?? []))),
                    // Achado real 2026-08-16: um item_id só (anúncio inteiro)
                    // pode ter mais de um produto local vinculado quando tem
                    // variação de verdade (ver external_model_id em
                    // product_channel_listings, mesmo caso do Ring Light
                    // 8"/10" já documentado em ShopeeDriver::importOrder()).
                    // As avaliações da Shopee são por ITEM, não por
                    // variação — model_id_list diz quais variações a compra
                    // que gerou o comentário incluía, usado por
                    // ReviewImportService::resolveProductId() pra rotear a
                    // avaliação pro produto local certo em vez de sempre
                    // cair no último processado.
                    'model_ids' => array_map('strval', (array) ($comment['model_id_list'] ?? [])),
                    'created_at' => isset($comment['create_time'])
                        ? \Illuminate\Support\Carbon::createFromTimestamp((int) $comment['create_time'], config('app.timezone'))
                        : null,
                ];
            }

            $more = (bool) ($page['response']['more'] ?? false);
            $cursor = (string) ($page['response']['next_cursor'] ?? '');
        } while ($more && $cursor !== '');

        return $reviews;
    }
}
