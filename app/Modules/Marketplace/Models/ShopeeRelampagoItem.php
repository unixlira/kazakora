<?php

namespace App\Modules\Marketplace\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Item da lista da Oferta Relâmpago da Shopee (pedido 2026-10-17). Às 22h o
 * shopee:relampago-agendar põe os itens do dia seguinte na Relâmpago de
 * 00h a 24h. Preço fixo (preco) ou desconto % sobre o preço do anúncio na
 * hora (desconto_pct). Regras da Shopee: pelo menos 1% abaixo do preço
 * atual e não acima do menor preço dos últimos 7 dias; quem recusa é ela,
 * o motivo fica em ultima_mensagem.
 *
 * @property int $id
 * @property int $shopee_item_id
 * @property int|null $shopee_model_id
 * @property int|null $product_id
 * @property string|null $nome
 * @property float|null $preco
 * @property float|null $desconto_pct
 * @property int $unidades
 * @property int $limite_comprador 0 = sem limite por comprador
 * @property Carbon|null $dia
 * @property bool $ativo
 * @property Carbon|null $ultimo_dia
 * @property string|null $ultimo_resultado agendado | recusado | erro
 * @property string|null $ultima_mensagem
 * @property int|null $ultimo_flash_sale_id
 */
class ShopeeRelampagoItem extends Model
{
    public const AGENDADO = 'agendado';

    public const RECUSADO = 'recusado';

    public const ERRO = 'erro';

    protected $table = 'shopee_relampago_itens';

    protected $fillable = [
        'shopee_item_id', 'shopee_model_id', 'product_id', 'nome', 'preco', 'desconto_pct',
        'unidades', 'limite_comprador', 'dia', 'ativo',
        'ultimo_dia', 'ultimo_resultado', 'ultima_mensagem', 'ultimo_flash_sale_id',
    ];

    protected function casts(): array
    {
        return [
            'shopee_item_id' => 'integer',
            'shopee_model_id' => 'integer',
            'preco' => 'float',
            'desconto_pct' => 'float',
            'unidades' => 'integer',
            'limite_comprador' => 'integer',
            'dia' => 'date',
            'ativo' => 'boolean',
            'ultimo_dia' => 'date',
            'ultimo_flash_sale_id' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** Ativos que entram na Relâmpago de $dia: os de todo dia e os marcados para ele. */
    public function scopeDoDia(Builder $query, Carbon $dia): Builder
    {
        return $query->where('ativo', true)
            ->where(fn (Builder $q) => $q->whereNull('dia')->orWhereDate('dia', $dia->toDateString()));
    }

    /** Preço na Relâmpago: o fixo ou o desconto sobre o preço atual do anúncio. */
    public function precoPara(?float $precoAtual): ?float
    {
        if ($this->preco !== null) {
            return round($this->preco, 2);
        }

        if ($this->desconto_pct === null || $precoAtual === null) {
            return null;
        }

        return round($precoAtual * (1 - $this->desconto_pct / 100), 2);
    }

    public function chave(): string
    {
        return $this->shopee_item_id.':'.($this->shopee_model_id ?? 0);
    }
}
