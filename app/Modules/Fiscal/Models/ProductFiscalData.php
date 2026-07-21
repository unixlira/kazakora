<?php

namespace App\Modules\Fiscal\Models;

use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductFiscalData extends Model
{
    protected $table = 'product_fiscal_data';

    protected $fillable = [
        'product_id',
        'ncm',
        'cest',
        'cfop',
        'origem',
        'gtin',
        'unidade_tributavel',
        'icms_situacao_tributaria',
        'icms_aliquota',
        'ipi_situacao_tributaria',
        'ipi_aliquota',
        'pis_situacao_tributaria',
        'pis_aliquota',
        'cofins_situacao_tributaria',
        'cofins_aliquota',
        'peso_bruto',
        'peso_liquido',
        'altura_cm',
        'largura_cm',
        'profundidade_cm',
    ];

    protected function casts(): array
    {
        return [
            'origem' => 'integer',
            'icms_aliquota' => 'decimal:2',
            'ipi_aliquota' => 'decimal:2',
            'pis_aliquota' => 'decimal:2',
            'cofins_aliquota' => 'decimal:2',
            'peso_bruto' => 'decimal:3',
            'peso_liquido' => 'decimal:3',
            'altura_cm' => 'decimal:2',
            'largura_cm' => 'decimal:2',
            'profundidade_cm' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
