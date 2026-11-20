<?php

namespace App\Modules\Infoprodutos\Support;

use App\Models\User;
use App\Modules\Analytics\Models\SiteVisit;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Quem é da equipe, para a tela Comportamento não contar como visita
 * (pedido do Lira em 2026-11-20: "não contar robôs nem os IPs da equipe").
 *
 * - usuários com papel de equipe (User::STAFF_ROLES), pelo user_id;
 * - o navegador (visitor_id) em que alguém da equipe navegou logado;
 * - os IPs desse navegador nos últimos 30 dias, mais a lista fixa
 *   COMPORTAMENTO_IPS_EQUIPE (config infoprodutos.ips_equipe).
 *
 * Os infoprodutos não gravam IP: a coleta (ColetaController) confere o IP
 * aqui na hora e marca a visita como teste, sem guardar o IP.
 */
class EquipeDaLoja
{
    public const DIAS = 30;

    /** @return list<int> */
    public static function usuarios(): array
    {
        return self::dados()['usuarios'];
    }

    /** @return list<string> */
    public static function visitantes(): array
    {
        return self::dados()['visitantes'];
    }

    /** @return list<string> IPs exatos e faixas (CIDR) */
    public static function ips(): array
    {
        return self::dados()['ips'];
    }

    public static function ip(?string $ip): bool
    {
        if ($ip === null || $ip === '') {
            return false;
        }
        $ips = self::ips();

        return $ips !== [] && IpUtils::checkIp($ip, $ips);
    }

    public static function esquecer(): void
    {
        Cache::forget('comportamento:equipe');
    }

    /** @return array{usuarios: list<int>, visitantes: list<string>, ips: list<string>} */
    private static function dados(): array
    {
        return Cache::remember('comportamento:equipe', 600, function () {
            $usuarios = User::query()->whereIn('role', User::STAFF_ROLES)->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
            $logado = SiteVisit::query()->whereIn('user_id', $usuarios ?: [0])->where('created_at', '>=', now()->subDays(self::DIAS));
            $visitantes = (clone $logado)->distinct()->pluck('visitor_id')->filter()->map(fn ($v) => (string) $v)->values()->all();
            $ips = (clone $logado)->whereNotNull('ip')->distinct()->pluck('ip')->map(fn ($ip) => (string) $ip)->all();
            $fixos = array_values(array_filter((array) config('infoprodutos.ips_equipe'), fn ($ip) => is_string($ip) && $ip !== ''));

            return ['usuarios' => $usuarios, 'visitantes' => $visitantes, 'ips' => array_values(array_unique([...$fixos, ...$ips]))];
        });
    }
}
