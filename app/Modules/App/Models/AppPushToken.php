<?php

namespace App\Modules\App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Token de notificação (Expo) de um aparelho do app (2026-10-18). Só guardado. */
class AppPushToken extends Model
{
    public const PLATAFORMAS = ['ios', 'android', 'web'];

    protected $fillable = ['user_id', 'token', 'plataforma'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
