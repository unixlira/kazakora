<?php

namespace App\Modules\Infoprodutos\Models;

use Illuminate\Database\Eloquent\Model;

/** Venda da Kiwify pelo id da venda; sem dado pessoal do comprador. */
class InfoprodutoVenda extends Model
{
    public const APROVADA = 'aprovada';

    public const PENDENTE = 'pendente';

    public const RECUSADA = 'recusada';

    public const REEMBOLSADA = 'reembolsada';

    public const CHARGEBACK = 'chargeback';

    protected $table = 'infoproduto_vendas';

    protected $guarded = [];

    protected $casts = [
        'valor' => 'decimal:2',
        'valor_liquido' => 'decimal:2',
        'criada_em' => 'datetime',
        'aprovada_em' => 'datetime',
        'reembolsada_em' => 'datetime',
    ];
}
