<?php

namespace App\Modules\Cart\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Espelho do carrinho de uma sessão (que hoje vive só em `session()->get('cart')`,
 * sem tabela própria) — existe só pra dar visibilidade agregada (ex: dashboard
 * admin) sobre quantos carrinhos estão ativos, sem virar a fonte de verdade do
 * carrinho em si. Um registro só existe enquanto o carrinho tiver item — ver
 * App\Modules\Cart\Support\CartManager::save()/clear().
 */
class CartSnapshot extends Model
{
    protected $fillable = [
        'session_id',
        'user_id',
        'items_count',
        'total',
    ];

    protected function casts(): array
    {
        return [
            'total' => 'decimal:2',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
