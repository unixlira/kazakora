<?php

namespace App\Modules\BeautyKora\Models;

use App\Support\NomeCapitalizado;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * Cliente do site Beauty Kora (pedido 2026-10-12). Não é User da loja: entra
 * só pela API /api/beautykora/* com token do Sanctum, nunca no painel.
 */
class BeautyKoraCliente extends Model
{
    use HasApiTokens, NomeCapitalizado;

    protected $table = 'beautykora_clientes';

    protected $fillable = ['name', 'email', 'password', 'phone', 'cpf', 'birth_date', 'avatar_path'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'birth_date' => 'date'];
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
            'avatar' => $this->avatarUrl(),
            'role' => 'customer',
            'created' => $this->created_at?->toIso8601String(),
        ];
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path ? asset('storage/'.$this->avatar_path) : null;
    }

    public function iniciais(): string
    {
        $palavras = array_filter(preg_split('/\s+/', trim($this->name ?? '')));
        $letras = array_map(fn (string $palavra) => mb_strtoupper(mb_substr($palavra, 0, 1)), $palavras);

        return implode('', array_slice($letras, 0, 2)) ?: '?';
    }

    /**
     * Selo do Meu Perfil, igual ao da KazaKora (User::seloCliente): "Super
     * Cliente" até passar de config('loja.selo_cliente.pedidos') pedidos
     * finalizados feitos desde config('loja.selo_cliente.desde'); depois, "5 ★".
     */
    public function seloCliente(): string
    {
        $finalizados = $this->pedidos()
            ->whereHas('order', fn ($pedido) => $pedido
                ->where('status', Order::STATUS_COMPLETED)
                ->where('created_at', '>=', config('loja.selo_cliente.desde'))
                ->vendaEfetiva())
            ->distinct()
            ->count('order_id');

        return $finalizados > config('loja.selo_cliente.pedidos') ? 'cinco_estrelas' : 'super_cliente';
    }
}
