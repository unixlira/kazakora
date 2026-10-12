<?php

namespace App\Modules\BeautyKora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente do site Beauty Kora (pedido 2026-10-12). Não é User da loja: entra
 * só pela API /api/beautykora/* com token do Sanctum, nunca no painel.
 */
class BeautyKoraCliente extends Model
{
    use HasApiTokens;

    protected $table = 'beautykora_clientes';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    public function enderecos(): HasMany
    {
        return $this->hasMany(BeautyKoraEndereco::class, 'cliente_id');
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(BeautyKoraPedido::class, 'cliente_id');
    }

    /** Formato que o site espera (era o usuário do PocketBase). */
    public function paraSite(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => null,
            'role' => 'customer',
            'created' => $this->created_at?->toIso8601String(),
        ];
    }
}
