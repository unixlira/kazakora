<?php

namespace App\Modules\Checkout\Support;

/** Estado (UF) de um CEP pelas faixas oficiais dos Correios (5 primeiros dígitos). */
final class CepUf
{
    private const FAIXAS = [
        [1000, 19999, 'SP'], [20000, 28999, 'RJ'], [29000, 29999, 'ES'], [30000, 39999, 'MG'],
        [40000, 48999, 'BA'], [49000, 49999, 'SE'], [50000, 56999, 'PE'], [57000, 57999, 'AL'],
        [58000, 58999, 'PB'], [59000, 59999, 'RN'], [60000, 63999, 'CE'], [64000, 64999, 'PI'],
        [65000, 65999, 'MA'], [66000, 68899, 'PA'], [68900, 68999, 'AP'], [69000, 69299, 'AM'],
        [69300, 69399, 'RR'], [69400, 69899, 'AM'], [69900, 69999, 'AC'], [70000, 72799, 'DF'],
        [72800, 72999, 'GO'], [73000, 73699, 'DF'], [73700, 76799, 'GO'], [76800, 76999, 'RO'],
        [77000, 77999, 'TO'], [78000, 78899, 'MT'], [78900, 78999, 'RO'], [79000, 79999, 'MS'],
        [80000, 87999, 'PR'], [88000, 89999, 'SC'], [90000, 99999, 'RS'],
    ];

    public static function porCep(?string $cep): ?string
    {
        $digitos = preg_replace('/\D/', '', (string) $cep);
        if (strlen($digitos) !== 8) {
            return null;
        }

        $prefixo = (int) substr($digitos, 0, 5);
        foreach (self::FAIXAS as [$de, $ate, $uf]) {
            if ($prefixo >= $de && $prefixo <= $ate) {
                return $uf;
            }
        }

        return null;
    }
}
