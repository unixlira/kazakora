<?php

namespace App\Modules\FerraKora\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conta do site FerraKora (pedido do Lira 2026-10-14, dados separados por
 * projeto): e-mail e senha próprios, não é User da KazaKora e não entra no
 * painel. O site guarda o token de uma FerraKoraSessao.
 */
class FerraKoraCliente extends Model
{
    protected $table = 'ferrakora_clientes';

    protected $fillable = ['name', 'email', 'password', 'phone', 'cpf', 'birth_date', 'avatar_path'];

    protected $hidden = ['password', 'reset_token_hash'];

    protected function casts(): array
    {
        return ['password' => 'hashed', 'reset_expira_em' => 'datetime', 'birth_date' => 'date'];
    }

    public function enderecos(): HasMany
    {
        return $this->hasMany(FerraKoraEndereco::class, 'cliente_id');
    }

    public function sessoes(): HasMany
    {
        return $this->hasMany(FerraKoraSessao::class, 'cliente_id');
    }

    /** Pedidos feitos no checkout do FerraKora com o e-mail desta conta. */
    public function pedidos(): Builder
    {
        return Order::query()
            ->where('site_origem', Order::SITE_FERRAKORA)
            ->whereIn('user_id', User::withTrashed()->where('email', $this->email)->select('id'));
    }

    /** Formato que o site espera. */
    public function paraSite(): array
    {
        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar' => $this->avatarUrl(),
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
     * Selo do Meu Perfil, igual ao da KazaKora (User::seloCliente), contando
     * só os pedidos finalizados feitos no FerraKora.
     */
    public function seloCliente(): string
    {
        $finalizados = $this->pedidos()
            ->where('status', Order::STATUS_COMPLETED)
            ->where('created_at', '>=', config('loja.selo_cliente.desde'))
            ->vendaEfetiva()
            ->count();

        return $finalizados > config('loja.selo_cliente.pedidos') ? 'cinco_estrelas' : 'super_cliente';
    }
}
