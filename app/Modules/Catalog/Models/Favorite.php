<?php

namespace App\Modules\Catalog\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    protected $fillable = [
        'user_id',
        'product_id',
    ];

    /**
     * Ids dos produtos favoritos da conta, mais recente primeiro.
     *
     * @return list<int>
     */
    public static function idsDoUsuario(int $userId): array
    {
        return static::query()->where('user_id', $userId)->latest('id')->pluck('product_id')->all();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
