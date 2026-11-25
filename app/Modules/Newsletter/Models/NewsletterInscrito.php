<?php

namespace App\Modules\Newsletter\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Str;

/**
 * Inscrito da newsletter (pedido do Lira 24/11/2026). Duplo opt-in: entra
 * como "pendente" e só recebe o resumo do blog depois de clicar no link do
 * e-mail de confirmação. O token serve para confirmar e para sair com 1
 * clique; não expira e não depende de login.
 */
class NewsletterInscrito extends Model
{
    use Prunable;

    public const PENDENTE = 'pendente';

    public const ATIVO = 'ativo';

    public const DESCADASTRADO = 'descadastrado';

    protected $table = 'newsletter_inscritos';

    protected $guarded = [];

    protected $attributes = ['status' => self::PENDENTE];

    protected function casts(): array
    {
        return [
            'consentimento_em' => 'datetime',
            'confirmacao_enviada_em' => 'datetime',
            'confirmado_em' => 'datetime',
            'descadastrado_em' => 'datetime',
            'ultimo_envio_em' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $inscrito) {
            $inscrito->token ??= self::novoToken();
        });
    }

    public static function novoToken(): string
    {
        return Str::random(48);
    }

    public static function normalizarEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    public function scopeAtivos(Builder $q): Builder
    {
        return $q->where('status', self::ATIVO);
    }

    public function ativo(): bool
    {
        return $this->status === self::ATIVO;
    }

    public function confirmar(): void
    {
        if ($this->ativo()) {
            return;
        }

        $this->forceFill(['status' => self::ATIVO, 'confirmado_em' => now(), 'descadastrado_em' => null])->save();
    }

    public function descadastrar(): void
    {
        if ($this->status === self::DESCADASTRADO) {
            return;
        }

        $this->forceFill(['status' => self::DESCADASTRADO, 'descadastrado_em' => now()])->save();
    }

    /** Inscrição que nunca foi confirmada sai depois de N dias (model:prune). */
    public function prunable(): Builder
    {
        return static::query()
            ->where('status', self::PENDENTE)
            ->whereNull('confirmado_em')
            ->where('created_at', '<', now()->subDays((int) config('newsletter.dias_para_apagar_pendente', 30)));
    }
}
