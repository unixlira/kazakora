<?php

namespace App\Models;

use App\Support\Rbac\Permissions;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Sanctum\HasApiTokens;

/**
 * Um parceiro externo (integrador B2B) da API pública — pedido explícito
 * 2026-08-21. NÃO é um User: não faz login no painel admin, não tem senha,
 * existe só pra ser dono de token(s) do Sanctum (HasApiTokens, mesma trait
 * que autentica esses tokens contra QUALQUER model — não precisa ser
 * User). Emitido/gerenciado só por um admin de dentro do painel (ver
 * Admin\ApiPartnerController) — não existe endpoint público de
 * autocadastro, de propósito (superfície de abuso menor).
 */
class ApiPartner extends Model
{
    /** @use HasFactory<\Database\Factories\ApiPartnerFactory> */
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'contact_email',
        'password',
        'abilities',
        'rate_limit_per_minute',
        'is_active',
        'created_by',
        'notes',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            // Mesmo cast nativo do User::casts() — hasheia sozinho na
            // atribuição (Hash::make manual não é mais necessário), e
            // detecta um valor já hasheado pra não hashear 2x.
            'password' => 'hashed',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Único ponto de verdade pra "quais abilities esse parceiro pode
     * receber num token novo" — nunca deixa o admin conceder uma string
     * fora do vocabulário conhecido (Permissions::ALL), mesmo que
     * `abilities` no banco tenha sido editado direto (defesa em
     * profundidade, não só validação na hora do form).
     *
     * @return array<int, string>
     */
    public function allowedAbilities(): array
    {
        return array_values(array_intersect($this->abilities ?? [], Permissions::ALL));
    }
}
