<?php

namespace App\Modules\FerraKora\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sessão do site FerraKora (pedido 2026-10-14): o cliente entra com a conta do
 * FerraKora (FerraKoraCliente, separada da KazaKora desde 2026-10-15) e o site
 * guarda só o token. Aqui fica o hash dele, nunca o
 * token. Vale 30 dias desde o último uso; sair apaga a sessão.
 *
 * Token próprio em vez do Sanctum: o guard sanctum da API de parceiros aceita
 * token de qualquer dono, e o cliente do FerraKora não pode entrar por ali.
 */
class FerraKoraSessao extends Model
{
    public const DIAS = 30;

    protected $table = 'ferrakora_sessoes';

    protected $fillable = ['cliente_id', 'token_hash', 'expira_em', 'ultimo_uso_em', 'ip', 'user_agent'];

    protected function casts(): array
    {
        return ['expira_em' => 'datetime', 'ultimo_uso_em' => 'datetime'];
    }

    public function cliente(): BelongsTo
    {
        return $this->belongsTo(FerraKoraCliente::class, 'cliente_id');
    }

    /** Abre a sessão e devolve o token (só sai aqui, uma vez). */
    public static function abrir(FerraKoraCliente $cliente, Request $request): string
    {
        static::query()->where('cliente_id', $cliente->id)->where('expira_em', '<', now())->delete();

        $token = Str::random(64);
        static::query()->create([
            'cliente_id' => $cliente->id,
            'token_hash' => hash('sha256', $token),
            'expira_em' => now()->addDays(self::DIAS),
            'ultimo_uso_em' => now(),
            'ip' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, ''),
        ]);

        return $token;
    }

    /** Sessão válida do token, com cache curto para não ir ao banco a cada página. */
    public static function doToken(?string $token): ?self
    {
        if (blank($token) || strlen($token) > 128) {
            return null;
        }

        $hash = hash('sha256', $token);
        $id = Cache::remember(self::chaveCache($hash), now()->addMinutes(5), fn () => static::query()->where('token_hash', $hash)->value('id') ?? 0);
        $sessao = $id ? static::query()->with('cliente')->find($id) : null;

        if (! $sessao || $sessao->expira_em->isPast() || ! $sessao->cliente) {
            Cache::forget(self::chaveCache($hash));

            return null;
        }

        return $sessao;
    }

    /** Uso marcado no máximo a cada 10 minutos; cada uso empurra o vencimento. */
    public function marcarUso(): void
    {
        if ($this->ultimo_uso_em && $this->ultimo_uso_em->gt(now()->subMinutes(10))) {
            return;
        }

        $this->forceFill(['ultimo_uso_em' => now(), 'expira_em' => now()->addDays(self::DIAS)])->save();
    }

    /** Nova senha: derruba todas as sessões da conta. */
    public static function encerrarTodas(FerraKoraCliente $cliente): void
    {
        static::query()->where('cliente_id', $cliente->id)->get()->each->encerrar();
    }

    public function encerrar(): void
    {
        Cache::forget(self::chaveCache($this->token_hash));
        $this->delete();
    }

    private static function chaveCache(string $hash): string
    {
        return 'ferrakora-sessao-cliente:'.$hash;
    }
}
