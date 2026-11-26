<?php

namespace App\Modules\Materiais\Support;

/**
 * Celular brasileiro do formulário de lead (pedido do Lira 26/11/2026).
 *
 * O campo da tela já mostra o +55 fixo; a pessoa digita DDD + número. Muita
 * gente digita o 55 de novo na frente (vira "DDD 55"): com 12 ou 13 dígitos
 * começando em 55, o 55 sai. Zero de operadora na frente (011...) também sai.
 *
 * A tela da FerraKora roda a MESMA regra em src/lib/telefone.js.
 */
final class TelefoneBrasil
{
    /** DDDs válidos por UF (Anatel). O 61 vale no DF e no entorno de GO. */
    public const DDDS_POR_UF = [
        'AC' => ['68'], 'AL' => ['82'], 'AP' => ['96'], 'AM' => ['92', '97'],
        'BA' => ['71', '73', '74', '75', '77'], 'CE' => ['85', '88'], 'DF' => ['61'],
        'ES' => ['27', '28'], 'GO' => ['61', '62', '64'], 'MA' => ['98', '99'],
        'MT' => ['65', '66'], 'MS' => ['67'], 'MG' => ['31', '32', '33', '34', '35', '37', '38'],
        'PA' => ['91', '93', '94'], 'PB' => ['83'], 'PR' => ['41', '42', '43', '44', '45', '46'],
        'PE' => ['81', '87'], 'PI' => ['86', '89'], 'RJ' => ['21', '22', '24'], 'RN' => ['84'],
        'RS' => ['51', '53', '54', '55'], 'RO' => ['69'], 'RR' => ['95'], 'SC' => ['47', '48', '49'],
        'SP' => ['11', '12', '13', '14', '15', '16', '17', '18', '19'], 'SE' => ['79'], 'TO' => ['63'],
    ];

    /** @return list<string> */
    public static function ddds(): array
    {
        $todos = array_unique(array_merge(...array_values(self::DDDS_POR_UF)));
        sort($todos);

        return array_values($todos);
    }

    public static function dddValido(string $ddd): bool
    {
        return in_array($ddd, self::ddds(), true);
    }

    /** DDD + número (10 ou 11 dígitos quando der), sem 55 do país e sem zero de operadora. */
    public static function nacional(?string $digitado): string
    {
        $n = preg_replace('/\D/', '', (string) $digitado);
        $n = ltrim($n, '0');
        if ((strlen($n) === 12 || strlen($n) === 13) && str_starts_with($n, '55')) {
            $n = substr($n, 2);
        }

        return $n;
    }

    /** Erro para mostrar no campo, ou null se o celular serve. */
    public static function erro(string $nacional): ?string
    {
        if ($nacional === '') {
            return 'Digite seu celular com DDD.';
        }
        if (strlen($nacional) < 10) {
            return 'Faltam números: digite o DDD e o celular com 9 dígitos.';
        }
        if (strlen($nacional) > 11) {
            return 'Número grande demais: digite só o DDD e o celular.';
        }
        if (! self::dddValido(substr($nacional, 0, 2))) {
            return 'DDD '.substr($nacional, 0, 2).' não existe. Confira os 2 primeiros números.';
        }
        if (strlen($nacional) === 10 || $nacional[2] !== '9') {
            return 'Use um celular: depois do DDD, 9 dígitos começando com 9.';
        }
        if (preg_match('/^(\d)\1{8}$/', substr($nacional, 2)) === 1) {
            return 'Esse número não parece real. Confira o celular.';
        }

        return null;
    }

    /** 55 + DDD + número, como fica gravado. */
    public static function completo(string $nacional): string
    {
        return '55'.$nacional;
    }
}
