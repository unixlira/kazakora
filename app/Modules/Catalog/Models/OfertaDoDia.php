<?php

namespace App\Modules\Catalog\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * Oferta do dia (pedido 2026-10-10). O desconto extra vale só na loja
 * KazaKora: entra no final_price do produto, mas o preço dos canais
 * (ProductChannelListing) usa o preço sem oferta.
 */
class OfertaDoDia extends Model
{
    protected $table = 'ofertas_do_dia';

    protected $fillable = ['data', 'product_id', 'posicao', 'desconto_extra', 'preco_antes', 'preco_oferta', 'lucro_estimado'];

    protected function casts(): array
    {
        return [
            'data' => 'date',
            'desconto_extra' => 'float',
            'preco_antes' => 'float',
            'preco_oferta' => 'float',
            'lucro_estimado' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public static function cacheKey(?string $data = null): string
    {
        return 'ofertas-do-dia:'.($data ?? now()->toDateString());
    }

    /**
     * @return array<int, float> product_id => preço da oferta de hoje.
     * Guardado no container (uma leitura do cache por requisição/job).
     */
    public static function precosDeHoje(): array
    {
        $hoje = now()->toDateString();
        $chave = 'ofertas-do-dia.precos.'.$hoje;

        if (! app()->bound($chave)) {
            // rescue: no deploy o código chega antes da migration; sem a tabela, sem oferta.
            app()->instance($chave, rescue(fn () => Cache::remember(self::cacheKey($hoje), now()->endOfDay(), fn () => static::query()
                ->whereDate('data', $hoje)
                ->pluck('preco_oferta', 'product_id')
                ->map(fn ($valor) => (float) $valor)
                ->all()), [], false));
        }

        return app($chave);
    }

    public static function esquecer(): void
    {
        app()->forgetInstance('ofertas-do-dia.precos.'.now()->toDateString());
        Cache::forget(self::cacheKey());
    }
}
