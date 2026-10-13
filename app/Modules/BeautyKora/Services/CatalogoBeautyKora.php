<?php

namespace App\Modules\BeautyKora\Services;

use App\Modules\BeautyKora\Models\BeautyKoraProduto;
use App\Modules\Catalog\Services\CatalogoOutroSite;

/**
 * Catálogo do Beauty Kora vindo do KazaKora (pedido 2026-10-12). O produto
 * é cadastrado no KazaKora (pai + variações), enviado em JSON para
 * POST /api/beautykora/produtos e o site lê GET /api/beautykora/produtos no
 * MESMO formato da vitrine da Hostinger. A formatação é a de
 * CatalogoOutroSite (2026-10-13), compartilhada com o FerraKora.
 */
class CatalogoBeautyKora extends CatalogoOutroSite
{
    protected function modelo(): string
    {
        return BeautyKoraProduto::class;
    }
}
