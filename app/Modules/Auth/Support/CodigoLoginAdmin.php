<?php

namespace App\Modules\Auth\Support;

use App\Models\User;
use App\Modules\Auth\Mail\CodigoLoginMail;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Login em 2 fatores do admin por código no e-mail (auditoria 2026-11-25).
 *
 * - Só para quem entra no painel (User::isStaff); cliente não passa por aqui.
 * - Código de 6 dígitos, guardado só como HMAC no cache, vale
 *   config('auth.admin_2fa.validade_minutos') e aceita
 *   config('auth.admin_2fa.tentativas') erros; depois disso some e é preciso
 *   entrar de novo com a senha.
 * - "Lembrar este dispositivo": cookie criptografado (o EncryptCookies do
 *   Laravel assina e cifra) com id, validade e um HMAC que inclui o hash da
 *   senha; trocar a senha invalida todos os dispositivos lembrados.
 * - ADMIN_2FA=false desliga tudo.
 */
class CodigoLoginAdmin
{
    public const COOKIE = 'kz_admin_dispositivo';

    public const OK = 'ok';

    public const ERRADO = 'errado';

    public const EXPIRADO = 'expirado';

    public const BLOQUEADO = 'bloqueado';

    public function ativo(): bool
    {
        return (bool) config('auth.admin_2fa.ativo', true);
    }

    public function validadeMinutos(): int
    {
        return max(1, (int) config('auth.admin_2fa.validade_minutos', 10));
    }

    public function maxTentativas(): int
    {
        return max(1, (int) config('auth.admin_2fa.tentativas', 5));
    }

    public function lembrarDias(): int
    {
        return max(1, (int) config('auth.admin_2fa.lembrar_dias', 30));
    }

    public function exigePara(User $user, Request $request): bool
    {
        return $this->ativo() && $user->isStaff() && ! $this->dispositivoLembrado($request, $user);
    }

    /** Gera um código novo (o anterior deixa de valer) e manda por e-mail. */
    public function enviar(User $user): void
    {
        $codigo = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $expira = now()->addMinutes($this->validadeMinutos());

        Cache::put($this->chave($user), [
            'hash' => $this->hashCodigo($user, $codigo),
            'expira' => $expira->getTimestamp(),
            'tentativas' => 0,
        ], $expira);

        Mail::to($user->email)->send(new CodigoLoginMail($user, $codigo, $this->validadeMinutos()));
    }

    /**
     * @return array{0: string, 1: int} [situação, tentativas restantes]
     */
    public function conferir(User $user, string $codigo): array
    {
        $chave = $this->chave($user);
        $dados = Cache::get($chave);

        if (! is_array($dados) || (int) ($dados['expira'] ?? 0) <= now()->getTimestamp()) {
            Cache::forget($chave);

            return [self::EXPIRADO, 0];
        }

        if (hash_equals((string) $dados['hash'], $this->hashCodigo($user, $codigo))) {
            Cache::forget($chave);

            return [self::OK, 0];
        }

        $dados['tentativas'] = (int) $dados['tentativas'] + 1;
        $restantes = $this->maxTentativas() - $dados['tentativas'];

        if ($restantes <= 0) {
            Cache::forget($chave);

            return [self::BLOQUEADO, 0];
        }

        Cache::put($chave, $dados, Carbon::createFromTimestamp((int) $dados['expira']));

        return [self::ERRADO, $restantes];
    }

    public function cookieLembrar(User $user): Cookie
    {
        $expira = now()->addDays($this->lembrarDias())->getTimestamp();

        return cookie(
            self::COOKIE,
            $user->getKey().'|'.$expira.'|'.$this->assinaturaDispositivo($user, $expira),
            $this->lembrarDias() * 24 * 60,
            httpOnly: true,
            sameSite: 'lax',
        );
    }

    public function dispositivoLembrado(Request $request, User $user): bool
    {
        $valor = $request->cookie(self::COOKIE);

        if (! is_string($valor) || substr_count($valor, '|') !== 2) {
            return false;
        }

        [$id, $expira, $assinatura] = explode('|', $valor);

        return $id === (string) $user->getKey()
            && ctype_digit($expira)
            && (int) $expira > now()->getTimestamp()
            && hash_equals($this->assinaturaDispositivo($user, (int) $expira), $assinatura);
    }

    private function chave(User $user): string
    {
        return 'admin-2fa:'.$user->getKey();
    }

    private function hashCodigo(User $user, string $codigo): string
    {
        return hash_hmac('sha256', $user->getKey().'|'.$codigo, (string) config('app.key'));
    }

    private function assinaturaDispositivo(User $user, int $expira): string
    {
        return hash_hmac('sha256', 'dispositivo|'.$user->getKey().'|'.$expira.'|'.$user->getAuthPassword(), (string) config('app.key'));
    }
}
