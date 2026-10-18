<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use App\Modules\FerraKora\Models\FerraKoraCliente;
use App\Modules\Fiscal\Models\Invoice;
use App\Modules\Fiscal\Models\InvoiceGenerationLog;
use App\Modules\Marketplace\Models\ChannelShipment;
use App\Modules\Marketplace\Models\CorreiosPrePostagem;
use App\Modules\Marketplace\Models\MarketplaceAccount;
use App\Modules\Marketplace\Models\OrderChannelFee;
use App\Support\Rbac\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use Auditable;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AWAITING_PAYMENT = 'awaiting_payment';

    public const STATUS_PAID = 'paid';

    public const STATUS_SHIPPED = 'shipped';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const ORIGIN_STORE = 'loja';

    /** site_origem do pedido feito no checkout do FerraKora (origin continua "loja"). */
    public const SITE_FERRAKORA = 'ferrakora';

    /** site_origem do pedido feito no checkout do Beauty Kora (2026-10-14). */
    public const SITE_BEAUTYKORA = 'beautykora';

    // orders.projeto_origem (pedido 2026-10-14): número do projeto para as
    // métricas separadas. Marketplaces e notas avulsas são da KazaKora.
    public const PROJETO_KAZAKORA = 1;

    public const PROJETO_BEAUTYKORA = 2;

    public const PROJETO_FERRAKORA = 3;

    /** Projeto a partir de site_origem (nulo = kazakora.com e canais). */
    public static function projetoDoSite(?string $site): int
    {
        return match ($site) {
            self::SITE_BEAUTYKORA => self::PROJETO_BEAUTYKORA,
            self::SITE_FERRAKORA => self::PROJETO_FERRAKORA,
            default => self::PROJETO_KAZAKORA,
        };
    }

    protected static function booted(): void
    {
        static::saving(function (Order $order) {
            $order->projeto_origem = self::projetoDoSite($order->site_origem);
        });
    }

    /**
     * Projetos com métrica própria (pedido do Lira 2026-10-14: cada projeto
     * com seus números). O pedido do FerraKora/Beauty Kora continua com
     * origin "loja" na operação (nota, expedição, etiqueta), mas nas
     * métricas vira canal próprio pela chave de canalSql().
     */
    public const SITES = [self::SITE_FERRAKORA, self::SITE_BEAUTYKORA];

    public const ROTULOS_SITE = [
        self::SITE_FERRAKORA => 'FerraKora',
        self::SITE_BEAUTYKORA => 'Beauty Kora',
    ];

    /**
     * Chave de canal para MÉTRICA: o origin, exceto pedido do site com
     * site_origem preenchido, que vira o próprio projeto ('ferrakora',
     * 'beautykora'). 'loja' passa a ser só o kazakora.com.
     */
    public static function canalSql(string $orders = 'orders'): string
    {
        return "(CASE WHEN {$orders}.origin = '".self::ORIGIN_STORE."' AND {$orders}.site_origem IS NOT NULL"
            ." THEN {$orders}.site_origem ELSE {$orders}.origin END)";
    }

    /**
     * Filtra uma query (Eloquent ou Query Builder) pelo canal de métrica:
     * 'loja' = site kazakora.com (site_origem nulo); 'ferrakora'/'beautykora'
     * = loja com aquele site_origem; demais = origin.
     */
    public static function filtrarCanal($query, string $canal, string $orders = 'orders')
    {
        if ($canal === self::ORIGIN_STORE) {
            return $query->where("{$orders}.origin", self::ORIGIN_STORE)->whereNull("{$orders}.site_origem");
        }

        if (in_array($canal, self::SITES, true)) {
            return $query->where("{$orders}.origin", self::ORIGIN_STORE)->where("{$orders}.site_origem", $canal);
        }

        return $query->where("{$orders}.origin", $canal);
    }

    // Mesmo vocabulário de canal que o módulo de Marketplace já usa
    // (product_channel_listings.channel, marketplace_accounts.channel) —
    // não um conjunto paralelo, pra "canal" ser um conceito único no app
    // inteiro e um canal novo (ex: TikTok Shop de verdade) não precisar
    // de mapeamento nenhum entre os dois lados.
    public const ORIGIN_MERCADO_LIVRE = MarketplaceAccount::CHANNEL_MERCADO_LIVRE;

    public const ORIGIN_SHOPEE = MarketplaceAccount::CHANNEL_SHOPEE;

    public const ORIGIN_TIKTOK_SHOP = MarketplaceAccount::CHANNEL_TIKTOK_SHOP;

    public const ORIGIN_AMAZON = MarketplaceAccount::CHANNEL_AMAZON;

    public const ORIGIN_SHEIN = MarketplaceAccount::CHANNEL_SHEIN;

    /**
     * Filtro "Marketplace" da lista de pedidos do admin (pedido do Lira
     * 2026-10-16): fonte única das opções, na chave de canal de métrica de
     * filtrarCanal(). TikTok Shop e Amazon entram pelo Bling mas são gravados
     * com o origin do próprio canal; 'loja' é só o kazakora.com e os sites do
     * FerraKora/Beauty Kora aparecem separados (site_origem).
     */
    public const MARKETPLACES = [
        self::ORIGIN_STORE => 'KazaKora', // era "Site"; só o rótulo mudou (2026-10-16)
        self::ORIGIN_SHOPEE => 'Shopee',
        self::ORIGIN_MERCADO_LIVRE => 'Mercado Livre',
        self::ORIGIN_TIKTOK_SHOP => 'TikTok Shop',
        self::ORIGIN_AMAZON => 'Amazon',
        self::SITE_FERRAKORA => 'FerraKora',
        self::SITE_BEAUTYKORA => 'Beauty Kora',
        // Origem real no banco (nota emitida à mão em Notas fiscais > Emitir):
        // sem ela, esses pedidos não tinham filtro na lista (2026-10-16).
        self::ORIGIN_MANUAL_INVOICE => 'Nota fiscal avulsa',
    ];

    /**
     * Nome do canal para mostrar na tela (2026-10-16): pedido do site sai
     * com o nome da loja (KazaKora, FerraKora ou Beauty Kora, pelo
     * site_origem); marketplace sai com o nome do canal. Só rótulo: o
     * origin gravado continua "loja".
     */
    public static function rotuloDoCanal(?string $origin, ?string $siteOrigem = null): string
    {
        if ($origin === self::ORIGIN_STORE && $siteOrigem !== null && isset(self::ROTULOS_SITE[$siteOrigem])) {
            return self::ROTULOS_SITE[$siteOrigem];
        }

        return self::MARKETPLACES[$origin] ?? (string) $origin;
    }

    // Pedido explícito 2026-08-09: emissão manual de nota fiscal (produto
    // ou serviço avulso) pelo novo menu /admin/notas-fiscais/emitir — não é
    // uma venda de canal nem do site, é só um jeito de ter um Order pra
    // pendurar a Invoice (a arquitetura inteira de NFe já é Order-based).
    // Não entra em nenhum dos switches/mapas de canal de marketplace
    // existentes (comporta-se como um canal desconhecido neles, igual
    // ORIGIN_STORE hoje em qualquer lugar que só trata canal de
    // marketplace de verdade).
    public const ORIGIN_MANUAL_INVOICE = 'nota_fiscal_avulsa';

    // Nota fiscal de devolução de compra: operador comprou mercadoria de
    // fornecedor e precisa devolver itens avariados. Continua Order-based
    // para reaproveitar Invoice/GenerateInvoiceJob, mas a NF-e sai com
    // finNFe=4 e chave referenciada no XML.
    public const ORIGIN_PURCHASE_RETURN_INVOICE = 'nota_devolucao_compra';

    // Nota fiscal de devolução de venda/cliente. É pedido fiscal técnico,
    // separado de venda operacional, expedição, marketplace e métricas.
    public const ORIGIN_SALES_RETURN_INVOICE = 'nota_devolucao_venda';

    /**
     * Número de endereço sem nenhum dígito ("", "S/N", "Lopes") — achado
     * real 2026-10-08: pedidos Amazon saíram com nota e etiqueta "S/N" e
     * voltaram como não entregues. Endereço sem número de verdade existe,
     * mas é raro; nesses casos a etiqueta sai pelo menu Correios.
     */
    public static function numeroSemDigito(?string $numero): bool
    {
        return ! preg_match('/\d/', (string) $numero);
    }

    public function enderecoSemNumero(): bool
    {
        return self::numeroSemDigito($this->shipping_number);
    }

    /**
     * TikTok Shop é operado fiscalmente fora do pipeline automático do
     * KazaKora: a venda entra para separação/estoque, mas a NF-e da venda é
     * tratada na plataforma/Bling pelo operador. Se o KazaKora também emitir
     * aqui, cria duplicidade fiscal para o mesmo pedido.
     */
    /**
     * Número do pedido da loja no formato da Shopee (pedido 2026-10-11):
     * data AAMMDD + 8 letras/números, ex. 261011K7Q2M9XD. É o número de
     * registro do pedido (como o ID do Mercado Livre) e fica em
     * external_order_id. Alfabeto sem I, L, O e U pra não confundir ao ditar.
     */
    /**
     * Etapas do pedido para o cliente (rastreio e detalhe da compra).
     *
     * @return list<array{titulo: string, feito: bool}>
     */
    public function etapas(): array
    {
        $this->loadMissing('invoice', 'channelShipment', 'latestCorreiosPrePostagem');

        $pago = in_array($this->status, [self::STATUS_PAID, self::STATUS_SHIPPED, self::STATUS_COMPLETED], true);
        $enviado = in_array($this->status, [self::STATUS_SHIPPED, self::STATUS_COMPLETED], true) || filled($this->trackingCode());
        // Em preparação: separado/embalado no KoraSync ou com etiqueta pronta.
        $emPreparacao = $enviado || ($pago && ($this->packed_at !== null || filled($this->channelShipment?->label_path)));

        return [
            ['titulo' => 'Pedido recebido', 'feito' => true],
            ['titulo' => 'Pagamento aprovado', 'feito' => $pago],
            ['titulo' => 'Nota fiscal emitida', 'feito' => $this->invoice?->status === \App\Modules\Fiscal\Models\Invoice::STATUS_AUTHORIZED],
            ['titulo' => 'Em preparação', 'feito' => $emPreparacao],
            ['titulo' => 'Pedido enviado', 'feito' => $enviado],
            ['titulo' => 'Entregue', 'feito' => $this->status === self::STATUS_COMPLETED],
        ];
    }

    /** Número que o cliente vê: o do formato Shopee (loja) ou o do canal; sem ele, o ID. */
    public function getNumeroAttribute(): string
    {
        return (string) ($this->external_order_id ?: $this->id);
    }

    public static function gerarNumeroDaLoja(): string
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        do {
            $sufixo = '';
            for ($i = 0; $i < 8; $i++) {
                $sufixo .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
            $numero = now('America/Sao_Paulo')->format('ymd').$sufixo;
        } while (self::query()->where('origin', self::ORIGIN_STORE)->where('external_order_id', $numero)->exists());

        return $numero;
    }

    public function shouldAutoGenerateInvoice(): bool
    {
        return $this->origin !== self::ORIGIN_TIKTOK_SHOP;
    }

    protected $fillable = [
        'user_id',
        'address_id',
        'status',
        'origin',
        'site_origem',
        // Cliente do FerraKora (2026-10-15): conta separada, user_id fica nulo.
        'ferrakora_cliente_id',
        'ferrakora_endereco_id',
        'external_order_id',
        'channel_pack_id',
        'fiscal_operation_type',
        'fiscal_nature_operation',
        'fiscal_finality',
        'fiscal_referenced_nfe_key',
        'fiscal_additional_info',
        'buyer_document',
        'buyer_state_registration',
        'buyer_taxpayer_type',
        'disputed_at',
        'stock_restored_at',
        'packed_at',
        'auto_print_blocked',
        'ready_for_pickup_at',
        'collected_at',
        'pickup_receipt_id',
        'waiting_for_product_at',
        'waiting_for_product_until',
        'shipping_method_id',
        'shipping_carrier_name',
        'shipping_name',
        // Só exibição (KoraSync/admin) — shipping_name continua sendo o
        // nome do comprador que casa com o CPF na NF-e. Ver a migration
        // add_recipient_and_buyer_nickname_to_orders_table.
        'shipping_recipient_name',
        'channel_buyer_nickname',
        'shipping_phone',
        'shipping_email',
        'shipping_whatsapp',
        'shipping_zip',
        'shipping_street',
        'shipping_number',
        'shipping_complement',
        'shipping_neighborhood',
        'shipping_city',
        'shipping_state',
        'visitante_latitude',
        'visitante_longitude',
        'subtotal',
        'shipping_cost',
        'coupon_code',
        'discount_amount',
        'pix_discount_amount',
        'desconto_plataforma_amount',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'shipping_cost' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'pix_discount_amount' => 'decimal:2',
            'desconto_plataforma_amount' => 'decimal:2',
            'total' => 'decimal:2',
            'visitante_latitude' => 'decimal:7',
            'visitante_longitude' => 'decimal:7',
            'fiscal_finality' => 'integer',
            'whatsapp_enviado_em' => 'datetime',
            'projeto_origem' => 'integer',
            'disputed_at' => 'datetime',
            'stock_restored_at' => 'datetime',
            'cancelado_em' => 'datetime',
            'packed_at' => 'datetime',
            'auto_print_blocked' => 'boolean',
            'ready_for_pickup_at' => 'datetime',
            'collected_at' => 'datetime',
            'waiting_for_product_at' => 'datetime',
            'waiting_for_product_until' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ferrakoraCliente(): BelongsTo
    {
        return $this->belongsTo(FerraKoraCliente::class, 'ferrakora_cliente_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function marketplaceClaims(): HasMany
    {
        return $this->hasMany(\App\Modules\Marketplace\Models\MarketplaceClaim::class);
    }

    public function invoice(): HasOne
    {
        return $this->hasOne(Invoice::class);
    }

    public function invoiceGenerationLogs(): HasMany
    {
        return $this->hasMany(InvoiceGenerationLog::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function emailLogs(): HasMany
    {
        return $this->hasMany(OrderEmailLog::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Código público do rastreio (pedido 2026-10-10): "{id}-{assinatura}" —
     * vai no link do WhatsApp/e-mail; sem a assinatura ninguém abre o
     * pedido de outra pessoa só trocando o número.
     */
    public function trackingRef(): string
    {
        return $this->id.'-'.substr(hash_hmac('sha256', 'rastreio:'.$this->id, (string) config('app.key')), 0, 10);
    }

    public static function findByTrackingRef(string $ref): ?self
    {
        if (! preg_match('/^(\d+)-([a-f0-9]{10})$/', $ref, $m)) {
            return null;
        }

        $order = static::query()->find((int) $m[1]);

        return $order && hash_equals($order->trackingRef(), $ref) ? $order : null;
    }

    /** Código dos Correios/transportadora, quando já existe. */
    public function trackingCode(): ?string
    {
        return $this->latestCorreiosPrePostagem?->codigo_objeto
            ?: $this->channelShipment?->tracking_code;
    }

    public function latestEmailLog(): HasOne
    {
        return $this->hasOne(OrderEmailLog::class)->latestOfMany(['created_at', 'id']);
    }

    public function channelShipment(): HasOne
    {
        return $this->hasOne(ChannelShipment::class);
    }

    /** Comprovante da entrega ao entregador do Flex (KoraFlex), quando houve. */
    public function pickupReceipt(): BelongsTo
    {
        return $this->belongsTo(\App\Modules\Marketplace\Models\FlexPickupReceipt::class, 'pickup_receipt_id');
    }

    public function correiosPrePostagens(): HasMany
    {
        return $this->hasMany(CorreiosPrePostagem::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function latestCorreiosPrePostagem(): HasOne
    {
        return $this->hasOne(CorreiosPrePostagem::class)->latestOfMany(['created_at', 'id']);
    }

    public function channelFee(): HasOne
    {
        return $this->hasOne(OrderChannelFee::class);
    }

    /** Vídeos da embalagem gravados no KoraSync (2026-10-11). */
    public function packingVideos(): HasMany
    {
        return $this->hasMany(OrderPackingVideo::class)->latest('id');
    }

    public function fulfillmentEvents(): HasMany
    {
        return $this->hasMany(OrderFulfillmentEvent::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Pedido fiscal de devolução usa a tabela orders só como suporte
     * técnico da NF-e. Não é venda, não deve aparecer na fila/lista operacional
     * nem inflar métricas de pedido/faturamento.
     */
    /**
     * Venda que conta como receita (auditoria 2026-10-11): sem notas de
     * devolução, sem nota avulsa (até hoje toda avulsa foi NOTA REEMITIDA
     * de uma venda que já existe no ML/TikTok — contava em dobro) e sem
     * venda estornada por inteiro na plataforma (estornado_em).
     */
    public function scopeVendaEfetiva($query)
    {
        return $query->nonPurchaseReturn()
            ->where(fn ($q) => $q->where($this->qualifyColumn('origin'), '!=', self::ORIGIN_MANUAL_INVOICE)->orWhereNull($this->qualifyColumn('origin')))
            ->whereNull($this->qualifyColumn('estornado_em'));
    }

    /**
     * Tira o checkout abandonado do site (KazaKora, FerraKora, Beauty Kora):
     * pedido com pagamento local em que NENHUM chegou a ser pago (Pix gerado
     * e não pago, cartão recusado, boleto vencido). Ele nasce "aguardando
     * pagamento" só para reservar estoque e ter número no Mercado Pago, e
     * depois a varredura o cancela: nunca foi venda e não é trabalho da
     * expedição (2026-10-17, apareciam na aba Cancelados do KoraSync).
     * Pedido de marketplace não tem pagamento local e continua passando.
     */
    public function scopeSemCheckoutAbandonado($query)
    {
        return $query->where(fn ($q) => $q
            ->whereDoesntHave('payments')
            ->orWhereHas('payments', fn ($pagamento) => $pagamento->whereIn('status', [
                Payment::STATUS_AUTHORIZED,
                Payment::STATUS_CAPTURED,
                Payment::STATUS_REFUNDED,
            ])));
    }

    /** Canal de métrica (ver canalSql/filtrarCanal). */
    public function scopeDoCanal($query, string $canal)
    {
        return self::filtrarCanal($query, $canal, $this->getTable());
    }

    public function scopeNonPurchaseReturn($query)
    {
        return $query
            ->where(function ($query) {
                $query->whereNotIn($this->qualifyColumn('origin'), [self::ORIGIN_PURCHASE_RETURN_INVOICE, self::ORIGIN_SALES_RETURN_INVOICE])
                    ->orWhereNull($this->qualifyColumn('origin'));
            })
            ->where(function ($query) {
                $query->whereNotIn($this->qualifyColumn('fiscal_operation_type'), ['purchase_return', 'sales_return'])
                    ->orWhereNull($this->qualifyColumn('fiscal_operation_type'));
            });
    }
}
