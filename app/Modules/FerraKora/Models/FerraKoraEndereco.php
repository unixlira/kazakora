<?php

namespace App\Modules\FerraKora\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Endereço de um cliente do FerraKora (pedido do Lira 2026-10-15: dados
 * separados da KazaKora). Mesmos campos do addresses da KazaKora, que o
 * Meu Perfil e o checkout do site já usam.
 */
class FerraKoraEndereco extends Model
{
    public const CAMPOS = ['label', 'recipient_name', 'phone', 'zip', 'street', 'number', 'complement', 'neighborhood', 'city', 'state', 'is_default'];

    protected $table = 'ferrakora_enderecos';

    protected $fillable = ['cliente_id', ...self::CAMPOS];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    /** Pedidos entregues neste endereço: com algum, ele não pode ser excluído. */
    public function pedidos(): HasMany
    {
        return $this->hasMany(Order::class, 'ferrakora_endereco_id');
    }

    /** Formato do Meu Perfil, igual ao da KazaKora. */
    public function paraPerfil(): array
    {
        return [
            ...$this->only(['id', ...self::CAMPOS]),
            'tem_pedido' => (bool) ($this->tem_pedido ?? $this->pedidos()->exists()),
        ];
    }
}
