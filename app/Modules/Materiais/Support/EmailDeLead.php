<?php

namespace App\Modules\Materiais\Support;

use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;

/**
 * E-mail do formulário de lead (pedido do Lira 26/11/2026): formato, domínio
 * que existe (MX ou A, guardado 1 dia), sem e-mail descartável e com aviso
 * para os erros de digitação mais comuns (gmial.com, hotmal.com...).
 *
 * A tela da FerraKora roda o formato, os descartáveis e os erros de digitação
 * em src/lib/email.js; o DNS só aqui.
 */
final class EmailDeLead
{
    public const DESCARTAVEIS = [
        'mailinator.com', 'yopmail.com', 'guerrillamail.com', 'guerrillamail.net', 'sharklasers.com',
        '10minutemail.com', '10minutemail.net', 'temp-mail.org', 'tempmail.com', 'tempmail.net', 'tempmail.dev',
        'tempail.com', 'trashmail.com', 'getnada.com', 'nada.email', 'dispostable.com', 'maildrop.cc',
        'throwawaymail.com', 'fakeinbox.com', 'mohmal.com', 'emailondeck.com', 'mintemail.com',
        'minuteinbox.com', '1secmail.com', 'mailnesia.com', 'spamgourmet.com', 'mytemp.email',
        'tmpmail.org', 'tmpmail.net', 'burnermail.io', 'inboxkitten.com', 'emailfake.com', 'fakemail.net',
        'mail.tm', 'tmail.ws', 'example.com', 'teste.com', 'test.com',
    ];

    /** Erro de digitação => domínio certo. */
    public const DIGITACAO = [
        'gmial.com' => 'gmail.com', 'gmal.com' => 'gmail.com', 'gmai.com' => 'gmail.com', 'gnail.com' => 'gmail.com',
        'gmail.co' => 'gmail.com', 'gmail.con' => 'gmail.com', 'gmail.cm' => 'gmail.com', 'gmail.om' => 'gmail.com',
        'gmail.com.br' => 'gmail.com', 'gamil.com' => 'gmail.com', 'gmaill.com' => 'gmail.com', 'gimail.com' => 'gmail.com',
        'hotmial.com' => 'hotmail.com', 'hotmal.com' => 'hotmail.com', 'hotmai.com' => 'hotmail.com', 'hotmail.co' => 'hotmail.com',
        'hotmail.con' => 'hotmail.com', 'hotmil.com' => 'hotmail.com', 'homail.com' => 'hotmail.com', 'hotamil.com' => 'hotmail.com',
        'outlok.com' => 'outlook.com', 'outloo.com' => 'outlook.com', 'outlook.con' => 'outlook.com', 'outlook.co' => 'outlook.com',
        'yaho.com' => 'yahoo.com', 'yahoo.con' => 'yahoo.com', 'yahoo.co' => 'yahoo.com', 'yahoo.com.b' => 'yahoo.com.br',
        'icloud.co' => 'icloud.com', 'iclod.com' => 'icloud.com', 'icloud.con' => 'icloud.com',
    ];

    /** Troca a consulta de DNS (testes). */
    public static ?Closure $resolver = null;

    public static function normalizar(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    public static function erro(string $email): ?string
    {
        if ($email === '') {
            return 'Digite seu e-mail.';
        }
        if (mb_strlen($email) > 160 || Validator::make(['e' => $email], ['e' => 'email:rfc,strict'])->fails()
            || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return 'Esse e-mail não parece válido. Confira se digitou certo.';
        }

        $dominio = substr($email, strrpos($email, '@') + 1);
        if (isset(self::DIGITACAO[$dominio])) {
            return 'Você quis dizer @'.self::DIGITACAO[$dominio].'? Confira o final do e-mail.';
        }
        if (in_array($dominio, self::DESCARTAVEIS, true)) {
            return 'Use um e-mail seu de verdade: e-mail temporário não recebe o guia.';
        }
        if (! str_contains($dominio, '.')) {
            return 'Falta o final do e-mail (ex.: @gmail.com).';
        }
        if (config('materiais.conferir_dns', true) && ! self::dominioExiste($dominio)) {
            return 'O endereço @'.$dominio.' não existe. Confira o final do e-mail.';
        }

        return null;
    }

    public static function dominioExiste(string $dominio): bool
    {
        if (self::$resolver !== null) {
            return (bool) (self::$resolver)($dominio);
        }

        return Cache::remember('materiais.dns.'.md5($dominio), now()->addDay(), function () use ($dominio) {
            $fqdn = rtrim($dominio, '.').'.';

            // Falha da própria consulta não pode barrar ninguém: só "não existe" barra.
            try {
                return checkdnsrr($fqdn, 'MX') || checkdnsrr($fqdn, 'A') || checkdnsrr($fqdn, 'AAAA');
            } catch (\Throwable) {
                return true;
            }
        });
    }
}
