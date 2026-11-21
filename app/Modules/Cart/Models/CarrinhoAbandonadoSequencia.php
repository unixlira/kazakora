<?php

namespace App\Modules\Cart\Models;

use App\Models\User;
use App\Modules\Catalog\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um carrinho abandonado na sequência de recuperação (pedido do Lira
 * 21/11/2026): um registro por pessoa e loja (o carrinho mais recente), com
 * o calendário de disparos e o histórico do que saiu. Dado pessoal (nome,
 * e-mail, telefone): só o admin e o comando leem.
 */
class CarrinhoAbandonadoSequencia extends Model
{
    public const ORIGEM_LEAD = 'lead_checkout';

    public const ORIGEM_SNAPSHOT = 'cart_snapshot';

    public const PAROU_COMPROU = 'comprou';

    public const PAROU_PEDIU = 'pediu_parar';

    public const PAROU_SEM_CONTATO = 'sem_contato';

    public const PAROU_SEM_PROMOCOES = 'sem_promocoes';

    public const PAROU_FIM = 'fim';

    protected $table = 'adm_abandoned_carts';

    protected $fillable = [
        'site',
        'origem',
        'origem_id',
        'user_id',
        'nome',
        'email',
        'telefone',
        'itens',
        'produto_id',
        'subtotal',
        'abandonado_em',
        'inicio_sequencia_em',
        'disparos_enviados',
        'ultimo_disparo_em',
        'proximo_disparo_em',
        'parado',
        'motivo_parada',
        'convertido_em',
        'historico',
    ];

    protected $hidden = ['email', 'telefone'];

    protected function casts(): array
    {
        return [
            'itens' => 'array',
            'historico' => 'array',
            'subtotal' => 'decimal:2',
            'disparos_enviados' => 'integer',
            'parado' => 'boolean',
            'abandonado_em' => 'datetime',
            'inicio_sequencia_em' => 'datetime',
            'ultimo_disparo_em' => 'datetime',
            'proximo_disparo_em' => 'datetime',
            'convertido_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function produto(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'produto_id')->withTrashed();
    }

    /** "Maria" (de "MARIA DA SILVA"); sem nome, "cliente". */
    public function primeiroNome(): string
    {
        $nome = trim((string) ($this->nome ?: $this->user?->name));

        return $nome !== '' ? mb_convert_case(mb_strtolower((string) strtok($nome, ' ')), MB_CASE_TITLE) : 'cliente';
    }

    public function parar(string $motivo, array $extra = []): void
    {
        $this->forceFill(['parado' => true, 'motivo_parada' => $motivo, 'proximo_disparo_em' => null, ...$extra])->save();
    }

    /** Acrescenta uma linha ao histórico (não salva). */
    public function anotar(array $linha): void
    {
        $historico = $this->historico ?? [];
        $historico[] = $linha + ['quando' => now()->toIso8601String()];
        $this->historico = $historico;
    }
}
