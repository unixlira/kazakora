<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MercadoLivreToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'ml_user_id',
        'ml_nickname',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
    ];

    protected function casts(): array
    {
        return [
            'access_token' => 'encrypted',
            'refresh_token' => 'encrypted',
            'token_expires_at' => 'datetime',
            'scopes' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->token_expires_at->lte(
            now()->addMinutes(config('mercadolivre.token_refresh_threshold_minutes'))
        );
    }

    public function isActive(): bool
    {
        return ! $this->isExpired();
    }

    public static function current(): ?self
    {
        return static::query()->latest('token_expires_at')->first();
    }
}
