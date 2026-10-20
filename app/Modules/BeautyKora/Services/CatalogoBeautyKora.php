<?php

namespace App\Modules\BeautyKora\Services;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Models\Product;
use App\Modules\Catalog\Services\CatalogoOutroSite;
use App\Modules\Checkout\Support\FreteEmbutido;
use App\Support\DescontoPix;
use App\Support\SiteAtual;
use Illuminate\Database\Eloquent\Model;

/**
 * Catálogo do Beauty Kora vindo do KazaKora (pedido 2026-10-12). O produto
 * é cadastrado no KazaKora (pai + variações), enviado em JSON para
 * POST /api/beautykora/produtos e o site lê GET /api/beautykora/produtos no
 * MESMO formato da vitrine da Hostinger. A formatação é a de
 * CatalogoOutroSite (2026-10-13), compartilhada com o FerraKora.
 *
 * Preço pela região do visitante (pedido 2026-10-15): o checkout do Beauty
 * Kora já cobra com o frete embutido do CEP, então o card mostra o mesmo
 * final_price da loja KazaKora para a região (IP ou CEP) e frete grátis.
 * Produto clonado (anúncio importado do KazaKora) leva +5% sobre o preço
 * original do anúncio, sem mexer no frete.
 */
class CatalogoBeautyKora extends CatalogoOutroSite
{
    public function site(): string
    {
        return SiteAtual::BEAUTYKORA;
    }

    protected function modelo(): string
    {
        return BeautyKoraProduto::class;
    }

    protected function comFreteEmbutido(): bool
    {
        return true;
    }

    /** "clonado" só muda quando vem no JSON; sem ele fica o que está gravado (novo = clonado). */
    protected function camposDoItem(array $item): array
    {
        return array_key_exists('clonado', $item) && $item['clonado'] !== null
            ? ['clonado' => (bool) $item['clonado']]
            : [];
    }

    protected function acrescimo(Model $registro): float
    {
        return $registro instanceof BeautyKoraProduto ? $registro->acrescimo() : 0.0;
    }

    protected function camposExtras(Model $registro, Product $pai): array
    {
        return [
            'clonado' => (bool) $registro->clonado,
            'frete_gratis' => app(FreteEmbutido::class)->ligado(),
            'desconto_pix' => DescontoPix::percentualDo($pai),
        ];
    }
}
