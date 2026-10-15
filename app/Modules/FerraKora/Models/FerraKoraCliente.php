<?php

namespace App\Modules\FerraKora\Models;

use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente do site FerraKora (pedido 2026-10-14). Não é User da loja: entra
 * só pela API /api/ferrakora/* com token do Sanctum, nunca no painel.
 */
class FerraKoraCliente extends Model
{
    use HasApiTokens;

    protected $table = 'ferrakora_clientes';

    protected $fillable = ['name', 'email', 'password'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed'];
    }

    /** Formato que o site espera. */
    public function paraSite(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'created' => $this->created_at?->toIso8601String(),
        ];
    }
}
