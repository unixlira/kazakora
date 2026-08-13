<?php

namespace App\Modules\Checkout\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Address extends Model
{
    protected $fillable = [
        'user_id',
        'label',
        'recipient_name',
        'phone',
        'zip',
        'street',
        'number',
        'complement',
        'neighborhood',
        'city',
        'state',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /**
     * BUG REAL 2026-08-13 (achado no fluxo de cadastro de endereço do
     * checkout): o campo UF no formulário (Checkout/Delivery.vue, também no
     * de Configurações da Empresa) só forçava maiúsculo visualmente via CSS
     * (`class="uppercase"`, puro text-transform) — o valor de verdade que
     * ia pro banco continuava exatamente como o usuário digitou (ex.: "sp"
     * minúsculo, se ele digitasse por cima do que o CEP já tinha
     * preenchido). NFeXmlBuilderService::buildIde()/buildDest() compara
     * `$order->shipping_state === $company->state` (comparação EXATA,
     * sensível a maiúscula/minúscula) pra decidir idDest (mesmo
     * estado/interestadual) e qual CFOP usar — "sp" !== "SP" fazia uma
     * venda dentro do mesmo estado ser tratada como interestadual, CFOP
     * errado numa nota fiscal de verdade. Normaliza aqui, no mutator do
     * model — cobre qualquer caminho de escrita (checkout, e um futuro
     * CRUD de endereços), não só o formulário atual. Ver mesmo fix em
     * Company::setStateAttribute().
     */
    public function setStateAttribute(?string $value): void
    {
        $this->attributes['state'] = $value !== null ? strtoupper(trim($value)) : null;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
