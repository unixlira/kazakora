<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MelhorEnvioToken extends Model
{
    use HasUuids;

    protected $fillable = [
        'user_id',
        'account_label',
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
        return $this->token_expires_at->lte(now()->addMinutes(5));
    }

    public static function current(): ?self
    {
        return static::query()->latest('token_expires_at')->first();
    }
}
