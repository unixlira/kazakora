<?php

namespace App\Modules\Fiscal\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * O que aconteceu fora do normal na numeração da NF-e: número pulado por
 * duplicidade (SEFAZ 539) ou faixa inutilizada. Entra no fechamento do mês
 * que vai pro contador.
 */
class NumeracaoOcorrencia extends Model
{
    public const TIPO_DUPLICIDADE = 'duplicidade';

    public const TIPO_INUTILIZACAO = 'inutilizacao';

    protected $table = 'nfe_numeracao_ocorrencias';

    protected $fillable = [
        'tipo', 'ambiente', 'serie', 'numero_inicial', 'numero_final', 'motivo',
        'invoice_id', 'order_id', 'user_id', 'protocolo', 'xml_path', 'resolvido_em',
    ];

    protected function casts(): array
    {
        return [
            'serie' => 'integer',
            'numero_inicial' => 'integer',
            'numero_final' => 'integer',
            'resolvido_em' => 'datetime',
        ];
    }

    public function scopeDuplicidadesAbertas(Builder $query): Builder
    {
        return $query->where('tipo', self::TIPO_DUPLICIDADE)->whereNull('resolvido_em');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
