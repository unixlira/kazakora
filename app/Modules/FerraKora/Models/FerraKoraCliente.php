<?php

namespace App\Modules\FerraKora\Models;

use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Cliente do site FerraKora (pedido do Lira 2026-10-15: dados separados da
 * KazaKora). Não é User da loja: entra só pela API /api/ferrakora/* com a
 * FerraKoraSessao, e os pedidos dele ficam em orders.ferrakora_cliente_id.
 */
class FerraKoraCliente extends Model
{
    protected $table = 'ferrakora_clientes';

    protected $fillable = ['name', 'email', 'cpf', 'phone', 'password', 'senha_definida'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'senha_definida' => 'boolean'];
    }

    public function pedidos(): HasMany
    {
        return $this->hasMany(Order::class, 'ferrakora_cliente_id');
    }

    /**
     * Conta do comprador no checkout. Mesmo e-mail e mesmo CPF (ou conta sem
     * CPF) = mesma conta; e-mail ou CPF de outra conta = aviso. Conta nova
     * nasce com senha sorteada: o cliente cria a dele em "Esqueci minha senha".
     *
     * @param  array{name: string, email: string, cpf: string, phone: string}  $dados
     */
    public static function doCheckout(array $dados): self
    {
        $cpf = preg_replace('/\D/', '', $dados['cpf']);
        $email = mb_strtolower(trim($dados['email']));
        $conta = static::query()->where('email', $email)->first();

        if ($conta) {
            if ($conta->cpf && $conta->cpf !== $cpf) {
                throw ValidationException::withMessages(['cliente.email' => 'Este e-mail já tem cadastro no FerraKora com outro CPF. Confira o CPF ou use outro e-mail.']);
            }
            if (! $conta->cpf && static::query()->where('cpf', $cpf)->exists()) {
                throw ValidationException::withMessages(['cliente.cpf' => 'Este CPF já está em outra conta. Use o e-mail dessa conta.']);
            }
            $conta->forceFill(['cpf' => $cpf, 'phone' => $conta->phone ?: $dados['phone']])->save();

            return $conta;
        }

        if (static::query()->where('cpf', $cpf)->exists()) {
            throw ValidationException::withMessages(['cliente.cpf' => 'Este CPF já tem cadastro com outro e-mail. Use o e-mail da sua conta.']);
        }

        return static::query()->create([
            'name' => $dados['name'],
            'email' => $email,
            'cpf' => $cpf,
            'phone' => $dados['phone'],
            'password' => Str::random(40),
            'senha_definida' => false,
        ]);
    }

    /** Formato que o site espera (sem CPF, telefone nem endereço). */
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
