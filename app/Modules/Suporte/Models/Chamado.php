<?php

namespace App\Modules\Suporte\Models;

use App\Models\User;
use App\Modules\Checkout\Models\Order;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Chamado de suporte da loja (pedido 2026-10-11). */
class Chamado extends Model
{
    public const CONVERSA = 'conversa';

    public const ABERTO = 'aberto';

    public const EM_ATENDIMENTO = 'em_atendimento';

    public const RESOLVIDO = 'resolvido';

    public const FECHADO = 'fechado';

    public const STATUS = [self::CONVERSA, self::ABERTO, self::EM_ATENDIMENTO, self::RESOLVIDO, self::FECHADO];

    public const PRIORIDADES = ['baixa', 'normal', 'alta', 'urgente'];

    /** Ainda em andamento (o cliente continua na mesma conversa). */
    public const ATIVOS = [self::CONVERSA, self::ABERTO, self::EM_ATENDIMENTO];

    protected $table = 'suporte_chamados';

    protected $fillable = [
        'numero', 'user_id', 'order_id', 'assunto', 'status', 'prioridade', 'humano_ativo',
        'aberto_por', 'resumo', 'nao_lidas_admin', 'ultima_mensagem_em', 'aberto_em', 'fechado_em',
    ];

    /** Padrões do banco já no modelo recém-criado (registrar() soma em cima). */
    protected $attributes = [
        'status' => self::CONVERSA,
        'prioridade' => 'normal',
        'humano_ativo' => false,
        'nao_lidas_admin' => 0,
    ];

    protected function casts(): array
    {
        return [
            'humano_ativo' => 'boolean',
            'ultima_mensagem_em' => 'datetime',
            'aberto_em' => 'datetime',
            'fechado_em' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function mensagens(): HasMany
    {
        return $this->hasMany(MensagemSuporte::class, 'chamado_id')->orderBy('id');
    }

    /** SUP + AAMMDD + 6 caracteres, ex. SUP261011K7Q2M9. */
    public static function gerarNumero(): string
    {
        $alfabeto = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

        do {
            $numero = 'SUP'.now('America/Sao_Paulo')->format('ymd');
            for ($i = 0; $i < 6; $i++) {
                $numero .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
            }
        } while (self::query()->where('numero', $numero)->exists());

        return $numero;
    }

    public function registrar(string $autor, string $conteudo, ?int $userId = null): MensagemSuporte
    {
        $mensagem = $this->mensagens()->create(['autor' => $autor, 'conteudo' => $conteudo, 'user_id' => $userId]);

        $this->forceFill([
            'ultima_mensagem_em' => now(),
            'nao_lidas_admin' => $autor === MensagemSuporte::CLIENTE ? $this->nao_lidas_admin + 1 : $this->nao_lidas_admin,
        ])->save();

        return $mensagem;
    }

    /** Formato da tela (loja e admin). */
    public function paraTela(): array
    {
        return [
            'id' => $this->id,
            'numero' => $this->numero,
            'assunto' => $this->assunto,
            'status' => $this->status,
            'prioridade' => $this->prioridade,
            'humano_ativo' => $this->humano_ativo,
            'aberto_por' => $this->aberto_por,
            'resumo' => $this->resumo,
            'nao_lidas' => $this->nao_lidas_admin,
            'ultima_mensagem_em' => $this->ultima_mensagem_em?->toIso8601String(),
            'criado_em' => $this->created_at?->toIso8601String(),
        ];
    }
}
